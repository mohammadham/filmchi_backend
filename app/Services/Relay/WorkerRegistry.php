<?php

namespace App\Services\Relay;

use App\RelayWorker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Chooses a healthy Cloudflare Worker for a source URL and builds the URL the
 * ingest job should request.
 *
 * The registry is deliberately conservative:
 *  - the global switch and the per-worker switch must both be on;
 *  - only hosts in media_relay.workers.hosts are proxied, so unrelated links
 *    keep using the direct path;
 *  - a failed worker enters a cooldown and is skipped by later requests;
 *  - if no worker is available, the caller receives a direct route.
 */
class WorkerRegistry
{
    public function enabled(): bool
    {
        return (bool) config('media_relay.workers.enabled', true);
    }

    public function allowedHosts(): array
    {
        $configured = config('media_relay.workers.hosts', 'farsanime.ir,di.farsanime.ir');

        if (is_array($configured)) {
            $hosts = $configured;
        } else {
            $hosts = explode(',', (string) $configured);
        }

        return array_values(array_filter(array_map(function ($host) {
            return strtolower(trim((string) $host));
        }, $hosts)));
    }

    public function isEligible(string $sourceUrl): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));

        if ($scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach ($this->allowedHosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, RelayWorker>
     */
    public function activeWorkers(): Collection
    {
        if (!$this->enabled()) {
            return collect();
        }

        try {
            return RelayWorker::query()
                ->available()
                ->orderBy('priority')
                ->orderByRaw('last_used_at IS NOT NULL ASC')
                ->orderBy('last_used_at')
                ->orderBy('id')
                ->get();
        } catch (Throwable $e) {
            // The table may not exist during a partial deploy; direct ingest
            // must keep working instead of turning the whole queue red.
            return collect();
        }
    }

    public function hasActiveWorkers(): bool
    {
        return $this->activeWorkers()->isNotEmpty();
    }

    /**
     * Return the URL/headers the ingest job should use for one request.
     *
     * @return array{url:string,headers:array<string,string>,proxied:bool,worker_id:int|null,worker_name:string|null,range:string|null}
     */
    public function route(string $sourceUrl, ?string $range = null): array
    {
        if (!$this->isEligible($sourceUrl)) {
            return $this->directRoute($sourceUrl, $range);
        }

        $worker = $this->activeWorkers()->first();

        if (!$worker) {
            return $this->directRoute($sourceUrl, $range);
        }

        $this->touch($worker);

        return [
            'url' => $this->buildUrl($worker, $sourceUrl, $range),
            'headers' => $this->headers($worker),
            'proxied' => true,
            'worker_id' => (int) $worker->id,
            'worker_name' => (string) $worker->name,
            'range' => $range,
        ];
    }

    /**
     * @return array{url:string,headers:array<string,string>,proxied:bool,worker_id:int|null,worker_name:string|null,range:string|null}
     */
    protected function directRoute(string $sourceUrl, ?string $range = null): array
    {
        return [
            'url' => $sourceUrl,
            'headers' => [],
            'proxied' => false,
            'worker_id' => null,
            'worker_name' => null,
            'range' => $range,
        ];
    }

    public function buildUrl(RelayWorker $worker, string $sourceUrl, ?string $range = null): string
    {
        $base = rtrim((string) $worker->base_url, '/');
        $query = ['url' => $sourceUrl];

        if ($range !== null && trim($range) !== '') {
            $query['range'] = 'bytes=' . ltrim($range, '=');
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string,string>
     */
    public function headers(RelayWorker $worker): array
    {
        $key = trim((string) $worker->api_key);

        return $key === '' ? [] : ['x-proxy-key' => $key];
    }

    protected function touch(RelayWorker $worker): void
    {
        try {
            RelayWorker::whereKey($worker->id)->update(['last_used_at' => now()]);
        } catch (Throwable $e) {
            // Telemetry is not worth failing an ingest over.
        }
    }

    /**
     * Probe a worker with a tiny, allow-listed document. The worker's own
     * response headers tell us whether it reached the origin and whether the
     * key was accepted.
     *
     * @return array{ok:bool,status:int|null,latency_ms:int,message:string,worker_id:int,worker_name:string}
     */
    public function test(RelayWorker $worker): array
    {
        $started = microtime(true);
        $testUrl = (string) config('media_relay.workers.test_url', 'https://farsanime.ir/sitemap.xml');
        $timeout = max(3, (int) config('media_relay.workers.timeout', 20));

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($this->headers($worker))
                ->get($this->buildUrl($worker, $testUrl));

            $latency = (int) round((microtime(true) - $started) * 1000);
            $upstream = (string) $response->header('x-proxy-upstream-status');
            $ok = $response->successful()
                && ($upstream === '' || in_array($upstream, ['200', '206'], true));

            if ($ok) {
                $message = 'online';
                $this->markHealthy($worker, $latency);
            } else {
                $message = 'HTTP ' . $response->status()
                    . ($upstream !== '' ? ' (upstream ' . $upstream . ')' : '')
                    . ($response->body() !== '' ? ': ' . Str::limit($response->body(), 160) : '');
                $this->markFailure($worker, $message);
            }

            return [
                'ok' => $ok,
                'status' => $response->status(),
                'latency_ms' => $latency,
                'message' => $message,
                'worker_id' => (int) $worker->id,
                'worker_name' => (string) $worker->name,
            ];
        } catch (Throwable $e) {
            $latency = (int) round((microtime(true) - $started) * 1000);
            $message = Str::limit($e->getMessage(), 300);
            $this->markFailure($worker, $message);

            return [
                'ok' => false,
                'status' => null,
                'latency_ms' => $latency,
                'message' => $message,
                'worker_id' => (int) $worker->id,
                'worker_name' => (string) $worker->name,
            ];
        }
    }

    public function markHealthy(RelayWorker $worker, int $latency): void
    {
        $worker->last_status = RelayWorker::STATUS_OK;
        $worker->last_latency_ms = max(0, $latency);
        $worker->last_checked_at = now();
        $worker->last_error = null;
        $worker->failure_count = 0;
        $worker->cooldown_until = null;
        $worker->save();
    }

    public function markFailure(RelayWorker $worker, string $message): void
    {
        $worker->last_status = RelayWorker::STATUS_FAILED;
        $worker->last_checked_at = now();
        $worker->last_error = Str::limit($message, 1000);
        $worker->failure_count = (int) $worker->failure_count + 1;
        $worker->cooldown_until = now()->addSeconds(max(10, (int) config('media_relay.workers.cooldown_seconds', 120)));
        $worker->save();
    }

    /**
     * Reject local/private targets and malformed worker URLs. This is an admin
     * screen, but a bad URL must not become an SSRF primitive or a redirect to
     * an internal service.
     */
    public function validateBaseUrl(string $value): string
    {
        $url = trim($value);
        $parts = parse_url($url);

        if ($url === '' || $parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw ValidationException::withMessages(['base_url' => 'A valid https Worker URL is required.']);
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw ValidationException::withMessages(['base_url' => 'Worker URLs must use https.']);
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw ValidationException::withMessages(['base_url' => 'Credentials must not be embedded in the Worker URL.']);
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw ValidationException::withMessages(['base_url' => 'Worker URLs may only use port 443.']);
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['base_url' => 'Use a bare Worker base URL without a query string or fragment.']);
        }

        $host = strtolower((string) $parts['host']);

        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')
            || str_ends_with($host, '.test') || str_starts_with($host, '[')) {
            throw ValidationException::withMessages(['base_url' => 'Local and internal hosts are not allowed.']);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $publicIp = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($publicIp === false) {
                throw ValidationException::withMessages(['base_url' => 'Private and reserved IP addresses are not allowed.']);
            }
        } elseif (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw ValidationException::withMessages(['base_url' => 'The Worker host name is not valid.']);
        }

        return rtrim($url, '/');
    }
}
