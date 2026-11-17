<?php

namespace App\Services\Relay;

use App\Jobs\ProbeWorkerJob;
use App\RelayWorker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
 *  - a failed worker leaves the pool until a probe proves it healthy again;
 *  - if no worker is available, the caller receives a direct route.
 */
class WorkerRegistry
{
    /** Bumped whenever Worker health/selection state changes. */
    public const POOL_VERSION_KEY = 'relay:workers:pool-version';

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
     * Number of Workers that passed their last health probe and are currently
     * usable. Failed Workers are excluded until a probe recovers them, so a
     * degraded pool is never presented as healthy.
     */
    public function healthyCount(string $sourceUrl): int
    {
        if (!$this->isEligible($sourceUrl)) {
            return 0;
        }

        return $this->selectPool($sourceUrl)
            ->filter(fn (RelayWorker $worker) => $worker->last_status === RelayWorker::STATUS_OK)
            ->count();
    }

    /**
     * Queue background probes for failed/auto-disabled Workers.
     *
     * This is intentionally independent of healthyCount(): with a large pool,
     * one bad Worker must be re-tested even while the rest are healthy. The
     * probe is asynchronous so a sleeping Worker cannot stall a download.
     * next_probe_at gives each Worker its own exponential-backoff schedule;
     * Cache::add() additionally throttles duplicate probes across queue workers.
     */
    public function recheckDegraded(?string $sourceUrl = null): int
    {
        $sourceUrl = $sourceUrl ?: (string) config('media_relay.workers.test_url', 'https://farsanime.ir/robots.txt');

        if (!$this->isEligible($sourceUrl)) {
            return 0;
        }

        $interval = max(10, (int) config('media_relay.workers.probe_interval_seconds',
            config('media_relay.workers.recheck_seconds', 60)));
        // The next_probe_at column is the real per-Worker schedule. Keep the
        // cross-process dispatch lock shorter than a long UI probe interval so
        // a Worker can still be retried promptly after it fails again.
        $lockSeconds = max(10, min(60, $interval));
        $limit = max(1, (int) config('media_relay.workers.recheck_limit', 3));

        $workers = RelayWorker::query()
            ->where('enabled', true)
            ->degraded()
            ->where(function ($query) {
                $query->whereNull('next_probe_at')
                    ->orWhere('next_probe_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', now());
            })
            ->orderByRaw('auto_disabled_at IS NOT NULL ASC')
            ->orderByRaw('last_checked_at IS NOT NULL ASC')
            ->orderBy('last_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($workers as $worker) {
            $key = 'relay:worker:probe:' . (int) $worker->id;

            if (!Cache::add($key, true, now()->addSeconds($lockSeconds))) {
                continue;
            }

            try {
                ProbeWorkerJob::dispatch((int) $worker->id);
                $dispatched++;
            } catch (Throwable $e) {
                Cache::forget($key);
            }
        }

        return $dispatched;
    }

    /**
     * Every registered Worker, including disabled and cooling-down ones.
     * Used by the explicit --all health test; normal routing stays on
     * activeWorkers()/selectPool().
     *
     * @return Collection<int, RelayWorker>
     */
    public function allWorkers(): Collection
    {
        try {
            return RelayWorker::query()
                ->orderBy('priority')
                ->orderBy('id')
                ->get();
        } catch (Throwable $e) {
            return collect();
        }
    }

    /**
     * Pick the best currently usable Workers for one source.
     *
     * "Usable" means enabled, not auto-disabled, outside cooldown, not marked
     * failed by the last real transfer or probe, and (when configured) above
     * the minimum measured speed and below the maximum latency. Unknown
     * speed/latency is allowed so a freshly added Worker can prove itself with
     * its first chunk. A failed Worker returns only after a successful probe.
     *
     * @param array<int,int> $excludeIds Workers that already failed this job
     * @return Collection<int, RelayWorker>
     */
    public function selectPool(string $sourceUrl, ?int $limit = null, array $excludeIds = []): Collection
    {
        if (!$this->isEligible($sourceUrl)) {
            return collect();
        }

        try {
            $workers = RelayWorker::query()
                ->available()
                ->when(!empty($excludeIds), function ($query) use ($excludeIds) {
                    $query->whereNotIn('id', array_map('intval', $excludeIds));
                })
                // scopeAvailable() already excludes failed/auto-disabled
                // Workers. They are not admitted to live traffic again until
                // a probe succeeds, so there is no "failed last" special case
                // that could still route a chunk to a known-bad endpoint.
                // Least-recently-used first. Speed is only a tie-breaker:
                // otherwise the fastest Worker would take every in-flight
                // slot while the rest of the pool sat idle. Unknown speed and
                // latency are placed after measured values.
                ->orderByRaw('last_used_at IS NOT NULL ASC')
                ->orderBy('last_used_at')
                ->orderByRaw('CASE WHEN last_speed_bps IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('last_speed_bps')
                ->orderByRaw('CASE WHEN last_latency_ms IS NULL THEN 1 ELSE 0 END')
                ->orderBy('last_latency_ms')
                ->orderBy('priority')
                ->orderBy('id')
                ->get();

            $minSpeed = (int) config('media_relay.workers.min_speed_bps', 0);
            $maxLatency = (int) config('media_relay.workers.max_latency_ms', 0);

            $workers = $workers->filter(function (RelayWorker $worker) use ($minSpeed, $maxLatency) {
                // A per-Worker override wins over the global admission
                // threshold. Null means "use the global value"; 0 disables the
                // threshold for this Worker.
                $workerMinSpeed = $worker->min_speed_bps !== null ? (int) $worker->min_speed_bps : $minSpeed;
                $workerMaxLatency = $worker->max_latency_ms !== null ? (int) $worker->max_latency_ms : $maxLatency;

                if ($workerMinSpeed > 0 && $worker->last_speed_bps !== null && (int) $worker->last_speed_bps < $workerMinSpeed) {
                    return false;
                }

                if ($workerMaxLatency > 0 && $worker->last_latency_ms !== null && (int) $worker->last_latency_ms > $workerMaxLatency) {
                    return false;
                }

                return true;
            })->values();

            // Apply selection strategy
            $strategy = config('media_relay.workers.selection_strategy', 'round_robin');
            if ($strategy === 'round_robin') {
                // Return workers in round-robin order (already ordered by priority, etc.)
                // but we'll rotate through them in the ingest job
            } elseif ($strategy === 'fastest') {
                // Sort by speed descending
                $workers = $workers->sortByDesc(function (RelayWorker $worker) {
                    return $worker->last_speed_bps ?? 0;
                });
            } elseif ($strategy === 'least_used') {
                // Sort by last_used_at ascending (oldest first)
                $workers = $workers->sortBy(function (RelayWorker $worker) {
                    return $worker->last_used_at ?? now()->subYears(1);
                });
            } elseif ($strategy === 'chunk_speed') {
                // The ingest job probes the fastest candidates per chunk.
                // Keep the pool itself ordered by measured real-transfer speed
                // as a sane fallback when a probe fails.
                $workers = $workers->sortByDesc(function (RelayWorker $worker) {
                    return $worker->last_speed_bps ?? 0;
                });
            }

            $workers = $workers->values();

            return $limit !== null ? $workers->take(max(0, $limit))->values() : $workers;
        } catch (Throwable $e) {
            // The table may not exist during a partial deploy; direct ingest
            // must keep working instead of turning the whole queue red.
            return collect();
        }
    }

    /**
     * Return the URL/headers the ingest job should use for one request.
     *
     * @param array<int,int> $excludeIds
     * @return array{url:string,headers:array<string,string>,proxied:bool,worker_id:int|null,worker_name:string|null,range:string|null}
     */
    public function route(string $sourceUrl, ?string $range = null, array $excludeIds = []): array
    {
        if (!$this->isEligible($sourceUrl)) {
            return $this->directRoute($sourceUrl, $range);
        }

        $worker = $this->selectPool($sourceUrl, 1, $excludeIds)->first();

        if (!$worker) {
            return $this->directRoute($sourceUrl, $range);
        }

        return $this->routeFor($worker, $sourceUrl, $range);
    }

    /**
     * Build a route for a Worker already chosen by the pool selector.
     *
     * @return array{url:string,headers:array<string,string>,proxied:bool,worker_id:int|null,worker_name:string|null,range:string|null}
     */
    public function routeFor(RelayWorker $worker, string $sourceUrl, ?string $range = null): array
    {
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
     * A manual test is an explicit operator override: one success is enough
     * to return an auto-disabled Worker to the pool. Automatic probes require
     * the configured consecutive-success threshold.
     *
     * @return array{ok:bool,status:int|null,latency_ms:int,message:string,worker_id:int,worker_name:string}
     */
    public function test(RelayWorker $worker, bool $manual = false): array
    {
        $started = microtime(true);
        $testUrl = (string) config('media_relay.workers.test_url', 'https://farsanime.ir/sitemap.xml');
        $timeout = max(3, (int) config('media_relay.workers.timeout', 30));

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
                $this->markProbeSuccess($worker, $latency, $manual);
            } else {
                $message = 'HTTP ' . $response->status()
                    . ($upstream !== '' ? ' (upstream ' . $upstream . ')' : '')
                    . ($response->body() !== '' ? ': ' . Str::limit($response->body(), 160) : '');
                $this->markFailure($worker, $message, 'probe', $manual);
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
            $this->markFailure($worker, $message, 'probe', $manual);

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

    /**
     * A probe succeeded. Automatic probes need consecutive successes; a
     * manual test recovers immediately. The Worker stays failed and out of the
     * pool after a first automatic success, so one lucky response cannot
     * re-introduce a flapping endpoint.
     */
    public function markProbeSuccess(RelayWorker $worker, int $latency, bool $manual = false): void
    {
        $worker->last_latency_ms = max(0, $latency);
        $worker->last_checked_at = now();
        $worker->last_error = null;
        $worker->probe_success_count = (int) $worker->probe_success_count + 1;

        $threshold = max(1, (int) config('media_relay.workers.probe_success_threshold', 2));
        // A queued probe can finish after an operator has already recovered
        // this Worker. A healthy Worker must not be knocked back to "failed"
        // merely because its old probe job was still in flight.
        $needsRecovery = $worker->auto_disabled_at !== null
            || $worker->last_status !== RelayWorker::STATUS_OK
            || $worker->cooldown_until !== null
            || (int) $worker->probe_success_count > 1;

        if ($manual || !$needsRecovery || (int) $worker->probe_success_count >= $threshold) {
            $this->clearFailureState($worker);
            $worker->last_status = RelayWorker::STATUS_OK;
            $worker->probe_success_count = 0;
        } else {
            $worker->last_status = RelayWorker::STATUS_FAILED;
            $worker->next_probe_at = now()->addSeconds($this->probeIntervalSeconds());
        }

        $worker->save();
        $this->bumpPoolVersion();
    }

    /**
     * Clear the failure/auto-disable state without touching the operator's
     * enabled switch. The Worker becomes eligible on the next pool refresh.
     */
    protected function clearFailureState(RelayWorker $worker): void
    {
        $worker->failure_count = 0;
        $worker->last_failure_at = null;
        $worker->cooldown_until = null;
        $worker->auto_disabled_at = null;
        $worker->disabled_reason = null;
        $worker->next_probe_at = null;
        $worker->probe_success_count = 0;
        $worker->last_error = null;
    }

    /**
     * Backward-compatible explicit success path used by callers that have
     * already established that the Worker is healthy (for example a real
     * transfer). A manual probe should use markProbeSuccess().
     */
    public function markHealthy(RelayWorker $worker, int $latency): void
    {
        $worker->last_status = RelayWorker::STATUS_OK;
        $worker->last_latency_ms = max(0, $latency);
        $worker->last_checked_at = now();
        $this->clearFailureState($worker);
        $worker->save();
        $this->bumpPoolVersion();
    }

    /**
     * Record a failure. Only real-transfer failures count toward the
     * auto-disable threshold; a failed probe keeps a Worker out of the pool and
     * schedules the next probe without letting a flaky probe test permanently
     * disable an otherwise usable endpoint.
     */
    public function markFailure(RelayWorker $worker, string $message, string $source = 'transfer', bool $manual = false): void
    {
        $worker->last_status = RelayWorker::STATUS_FAILED;
        $worker->last_checked_at = now();
        $worker->last_error = Str::limit($message, 1000);

        if ($source === 'transfer') {
            $window = max(30, (int) config('media_relay.workers.failure_window_seconds', 600));
            $previousFailure = $worker->last_failure_at;

            if (!$previousFailure || $previousFailure->lt(now()->subSeconds($window))) {
                $worker->failure_count = 0;
            }

            $worker->failure_count = (int) $worker->failure_count + 1;
            $worker->last_failure_at = now();

            $threshold = max(1, (int) config('media_relay.workers.failure_threshold', 3));
            $autoDisable = filter_var(config('media_relay.workers.auto_disable', true), FILTER_VALIDATE_BOOLEAN);

            if ($autoDisable && (int) $worker->failure_count >= $threshold) {
                $worker->auto_disabled_at = now();
                $worker->disabled_reason = sprintf(
                    'auto-disabled after %d consecutive transfer failures',
                    (int) $worker->failure_count
                );
                $worker->cooldown_until = null;
                $worker->next_probe_at = now()->addSeconds($this->backoffSeconds((int) $worker->failure_count));
            } else {
                $delay = $this->backoffSeconds((int) $worker->failure_count);
                $worker->cooldown_until = now()->addSeconds($delay);
                $worker->next_probe_at = now()->addSeconds($delay);
            }

            $worker->probe_success_count = 0;
        } else {
            // A probe failure must not increase the transfer-failure counter;
            // it only keeps the Worker unavailable until the next probe.
            $worker->probe_success_count = 0;
            $delay = max(10, (int) config('media_relay.workers.cooldown_seconds', 60));
            $worker->cooldown_until = now()->addSeconds($delay);
            $worker->next_probe_at = now()->addSeconds($delay);
        }

        $worker->save();
        $this->bumpPoolVersion();
    }

    protected function backoffSeconds(int $failureCount): int
    {
        $base = max(1, (int) config('media_relay.workers.backoff_base_seconds', 60));
        $max = max($base, (int) config('media_relay.workers.backoff_max_seconds', 3600));
        $exponent = max(0, min(10, $failureCount - 1));

        return min($max, $base * (2 ** $exponent));
    }

    protected function probeIntervalSeconds(): int
    {
        return max(10, (int) config('media_relay.workers.probe_interval_seconds',
            config('media_relay.workers.recheck_seconds', 60)));
    }

    /**
     * Record a real transfer success and update the throughput EWMA. A tiny
     * sitemap probe is not a throughput signal, so this is the value the
     * selector uses when choosing the fastest pool.
     */
    public function reportSuccess(?int $workerId, int $bytes, int $durationMs): void
    {
        if (empty($workerId) || $bytes <= 0 || $durationMs <= 0) {
            return;
        }

        try {
            $worker = RelayWorker::find($workerId);

            if (!$worker) {
                return;
            }

            $sample = (int) round($bytes / ($durationMs / 1000));
            $previous = (int) ($worker->last_speed_bps ?? 0);
            $wasUnavailable = $worker->last_status !== RelayWorker::STATUS_OK
                || $worker->auto_disabled_at !== null
                || $worker->cooldown_until !== null;

            $worker->last_speed_bps = $previous > 0
                ? (int) round(($previous * 0.7) + ($sample * 0.3))
                : $sample;
            $worker->last_speed_at = now();
            $worker->success_count = (int) $worker->success_count + 1;
            $worker->last_status = RelayWorker::STATUS_OK;
            $worker->last_checked_at = now();
            $this->clearFailureState($worker);
            $worker->save();

            // Successful chunks are frequent; only notify long-running ingests
            // when this success actually restored an unavailable Worker.
            if ($wasUnavailable) {
                $this->bumpPoolVersion();
            }
        } catch (Throwable $e) {
            // Telemetry must never fail an ingest.
        }
    }

    /**
     * A real chunk failed. Unlike the health probe, this immediately removes
     * the Worker from the next retry and starts its cooldown.
     */
    public function reportFailure(?int $workerId, string $message): void
    {
        if (empty($workerId)) {
            return;
        }

        try {
            $worker = RelayWorker::find($workerId);

            if ($worker) {
                $this->markFailure($worker, $message);
            }
        } catch (Throwable $e) {
            // Telemetry must never fail an ingest.
        }
    }

    public function poolVersion(): int
    {
        try {
            $value = Cache::get(self::POOL_VERSION_KEY);

            if ($value === null) {
                Cache::forever(self::POOL_VERSION_KEY, 1);

                return 1;
            }

            return (int) $value;
        } catch (Throwable $e) {
            return 1;
        }
    }

    /**
     * Notify long-running ingest jobs that a Worker was added, recovered,
     * failed, or toggled. Ingest checks this cheap counter while chunks are in
     * flight and refreshes immediately instead of waiting for the 10-second
     * periodic refresh.
     */
    public function bumpPoolVersion(): void
    {
        try {
            if (Cache::get(self::POOL_VERSION_KEY) === null) {
                Cache::forever(self::POOL_VERSION_KEY, 1);
            }

            Cache::increment(self::POOL_VERSION_KEY);
        } catch (Throwable $e) {
            // A cache outage must not fail a download or a health test.
        }
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
