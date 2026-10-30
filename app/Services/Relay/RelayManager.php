<?php

namespace App\Services\Relay;

use App\Jobs\PromoteToCacheJob;
use App\RelayEvent;
use App\RelayFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which tier serves a request and streams the bytes through.
 *
 * The site is only ever a pipe: a live upstream stream is read in chunks and
 * echoed to the client. No temporary file, no local copy, nothing to clean up.
 */
class RelayManager
{
    /** @var TelegramDriveClient */
    protected $telegram;

    /** @var NineDriveClient */
    protected $ninedrive;

    /** @var DemandTracker */
    protected $demand;

    public function __construct(TelegramDriveClient $telegram, NineDriveClient $ninedrive, DemandTracker $demand)
    {
        $this->telegram = $telegram;
        $this->ninedrive = $ninedrive;
        $this->demand = $demand;
    }

    public function telegram(): TelegramDriveClient
    {
        return $this->telegram;
    }

    public function ninedrive(): NineDriveClient
    {
        return $this->ninedrive;
    }

    public function demandTracker(): DemandTracker
    {
        return $this->demand;
    }

    /* ------------------------------------------------------------------ tiers */

    public function isCached(RelayFile $file): bool
    {
        if ($file->cache_state !== RelayFile::WARM) {
            return false;
        }

        // An unreachable gateway must never swallow a request: the file then
        // simply keeps being served by the primary tier instead.
        if (!$this->ninedrive->hasBaseUrl()) {
            return false;
        }

        if (!empty($file->nine_share_token)) {
            return true;
        }

        return $this->serveMode() === 'drive' && !empty($file->nine_provider_file_id);
    }

    protected function serveMode(): string
    {
        return (string) config('media_relay.ninedrive.serve_mode', 'auto');
    }

    /**
     * Decide whether the given upstream URL may be handed to nginx.
     *
     * Returns the encoded internal redirect target, or null when the URL is
     * empty or its host is not one of the configured upstreams — in which
     * case serve() falls back to streaming through PHP.
     *
     * @return string|null
     */
    protected function accelTarget(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $allowed = [
            parse_url((string) config('media_relay.telegram.base_url'), PHP_URL_HOST),
            parse_url((string) config('media_relay.ninedrive.base_url'), PHP_URL_HOST),
            parse_url((string) config('media_relay.ninedrive.frontend_url'), PHP_URL_HOST),
            'drive.google.com',
            'drive.usercontent.google.com',
        ];

        if (!in_array(strtolower($host), array_filter(array_map('strtolower', $allowed)), true)) {
            return null;
        }

        return '/relay-upstream/' . rawurlencode($url);
    }

    /**
     * Response that defers the byte transfer to nginx.
     *
     * Only headers the client should see are set here; Content-Type and
     * Content-Length arrive from the proxied upstream response. HEAD skips
     * the upstream entirely (no Content-Length, but ranges still work for
     * real GETs).
     *
     * @param  array{tier:string,url:string,headers:array<string,string>}  $source
     */
    protected function accelResponse(RelayFile $file, array $source, Request $request, bool $download, string $target): Response
    {
        // Cache-Control is intentionally absent: nginx emits it via
        // add_header on the proxied response (see relay-upstream.conf), and
        // setting it here as well would produce a duplicate header.
        $headers = [
            'ETag' => $this->etagFor($file),
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => $this->contentDisposition($file->name, $download),
            'X-Relay-Tier' => $source['tier'],
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        // The ETag and tier travel inside the redirect target and are emitted
        // by nginx's add_header, because headers set on the internal response
        // are not reliably forwarded through X-Accel-Redirect. ETag/tier are
        // argument-safe (no spaces, &, ? or #) so they pass unencoded.
        $redirect = '/relay-upstream/' . rawurlencode($source['url'])
            . '?rte=' . $this->etagFor($file)
            . '&rtt=' . $source['tier'];

        return response('', 200, $headers)->header('X-Accel-Redirect', $redirect);
    }

    /**
     * Where the bytes for this file come from right now.
     *
     * @return array{tier:string,url:string,headers:array<string,string>}
     */
    public function source(RelayFile $file, bool $download = false): array
    {
        if ($this->isCached($file)) {
            return [
                'tier' => 'cache',
                'url' => $this->cacheUrl($file, $download),
                'headers' => [],
            ];
        }

        if (empty($file->tg_file_id)) {
            throw new RuntimeException('This file has no storage location yet.');
        }

        return [
            'tier' => 'telegram',
            'url' => $this->telegram->presignedLink((string) $file->tg_file_id),
            'headers' => [],
        ];
    }

    public function cacheUrl(RelayFile $file, bool $download = false): string
    {
        if (!empty($file->nine_share_token)) {
            return $download
                ? $this->ninedrive->downloadUrl($file->nine_share_token)
                : $this->ninedrive->previewUrl($file->nine_share_token);
        }

        if (!empty($file->nine_provider_file_id)) {
            return $this->ninedrive->driveUrl($file->nine_provider_file_id);
        }

        throw new RuntimeException('The cached copy has no public link.');
    }

    /* ----------------------------------------------------------------- demand */

    /**
     * Record a request and queue a promotion once demand crosses the threshold.
     *
     * @return array{count:int,threshold:int,tier:string,promoted:bool}
     */
    public function trackDemand(RelayFile $file, Request $request = null, bool $download = false): array
    {
        $count = $this->demand->record($file);
        $this->demand->touch($file);

        RelayEvent::create([
            'relay_file_id' => $file->id,
            'event' => $download ? RelayEvent::DOWNLOAD : RelayEvent::DEMAND,
            'served_by' => $this->isCached($file) ? 'cache' : 'telegram',
            'ip' => $request ? $request->ip() : null,
            'created_at' => now(),
        ]);

        $promoted = false;

        if ($this->demand->shouldPromote($file)) {
            $promoted = $this->queuePromotion($file);
        }

        return [
            'count' => $count,
            'threshold' => $this->demand->threshold(),
            'tier' => $this->isCached($file) ? 'cache' : 'telegram',
            'promoted' => $promoted,
        ];
    }

    /**
     * Push a promotion onto the queue when a real worker is available;
     * otherwise mark it so `php artisan relay:promote` can pick it up.
     */
    public function queuePromotion(RelayFile $file): bool
    {
        $connection = config('media_relay.queue.connection') ?: config('queue.default');

        if ($connection === 'sync' || $connection === 'null') {
            if ($file->cache_state === RelayFile::COLD) {
                $file->cache_state = RelayFile::QUEUED;
                $file->save();
            }

            return false;
        }

        $file->cache_state = RelayFile::QUEUED;
        $file->save();

        // Telegram Drive finalises an upload asynchronously; hitting it with a
        // presign request the same second reliably answers 404 "not ready".
        // Give it a short grace period before the first attempt.
        PromoteToCacheJob::dispatch($file->id)->delay(now()->addSeconds(20));

        return true;
    }

    /* ---------------------------------------------------------------- serving */

    /**
     * Build the response that relays the file to the visitor.
     */
    public function serve(RelayFile $file, Request $request, bool $download = false): Response
    {
        $etag = $this->etagFor($file);

        // The file bytes are immutable, so a matching If-None-Match can be
        // answered without touching either upstream.
        if ($this->ifNoneMatchMatches($request, $etag)) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=3600',
                'Accept-Ranges' => 'bytes',
            ]);
        }

        $source = $this->source($file, $download);

        if (!config('media_relay.relay.proxy', true)) {
            return redirect()->away($source['url']);
        }

        // Hand the byte streaming to nginx: the response only carries an
        // X-Accel-Redirect to the internal pass-through location, and nginx
        // proxies the upstream itself (Range included) without any PHP work.
        if ((bool) config('media_relay.relay.x_accel', true) && ($accel = $this->accelTarget($source['url'] ?? '')) !== null) {
            return $this->accelResponse($file, $source, $request, $download, $accel);
        }

        $upstreamHeaders = $source['headers'];

        if ($request->header('Range')) {
            // If-Range carries the validator the client based its range on.
            // A stale validator means the client's cache no longer matches,
            // so the range must be ignored and the full body returned.
            if (trim((string) $request->header('If-Range')) !== '' && !$this->ifRangeMatches($request, $etag)) {
                unset($upstreamHeaders['Range']);
            } else {
                $upstreamHeaders['Range'] = (string) $request->header('Range');
            }
        }

        $opened = Streams::open(
            $source['url'],
            $upstreamHeaders,
            (int) config('media_relay.relay.stream_timeout', 1800)
        );

        $status = (int) $opened['status'];
        $headers = $this->responseHeaders($file, $opened['headers'], $download, $source['tier']);

        if ($status >= 400) {
            if (is_resource($opened['stream'])) {
                fclose($opened['stream']);
            }

            Log::warning('Relay upstream error', [
                'relay_file_id' => $file->id,
                'tier' => $source['tier'],
                'status' => $status,
            ]);

            $errorHeaders = ['Content-Type' => 'text/plain; charset=UTF-8'];

            if ($status === 416 && (int) $file->size > 0) {
                $errorHeaders['Content-Range'] = 'bytes */' . (int) $file->size;
            }

            return response($this->errorPage($status), $status, $errorHeaders);
        }

        if ($request->isMethod('HEAD')) {
            if (is_resource($opened['stream'])) {
                fclose($opened['stream']);
            }

            return response('', $status, $headers);
        }

        $stream = $opened['stream'];
        $chunkSize = max(8192, (int) config('media_relay.relay.chunk_size', 262144));

        return response()->stream(function () use ($stream, $chunkSize, $file, $source, $download) {
            $served = 0;

            try {
                while (!feof($stream)) {
                    $buffer = fread($stream, $chunkSize);

                    if ($buffer === false) {
                        break;
                    }

                    if ($buffer === '') {
                        continue;
                    }

                    $served += strlen($buffer);
                    echo $buffer;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            } catch (\Throwable $e) {
                Log::warning('Relay stream interrupted', [
                    'relay_file_id' => $file->id,
                    'message' => $e->getMessage(),
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $this->accountServed($file, $source['tier'], $served);
            }
        }, $status, $headers);
    }

    protected function accountServed(RelayFile $file, string $tier, int $bytes): void
    {
        try {
            $file->hits = (int) $file->hits + 1;
            $file->bytes_served = (int) $file->bytes_served + max(0, $bytes);
            $file->save();
        } catch (\Throwable $e) {
            // Accounting must never break a download.
        }
    }

    /**
     * Forward the upstream metadata the browser needs, and label the tier.
     */
    protected function responseHeaders(RelayFile $file, array $upstream, bool $download, string $tier): array
    {
        $headers = [
            'Content-Type' => $upstream['content-type'] ?? ($file->mime ?: 'application/octet-stream'),
            'Accept-Ranges' => $upstream['accept-ranges'] ?? 'bytes',
            'Content-Disposition' => $this->contentDisposition($file->name, $download),
            // The bytes never change for a given file, so the browser may
            // keep a private copy and revalidate with If-None-Match (304).
            'Cache-Control' => 'private, max-age=3600',
            'ETag' => $this->etagFor($file),
            'X-Relay-Tier' => $tier,
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        foreach (['content-length', 'content-range', 'last-modified'] as $name) {
            if (!empty($upstream[$name])) {
                $headers[$this->headerCase($name)] = $upstream[$name];
            }
        }

        return $headers;
    }

    protected function headerCase(string $name): string
    {
        $map = [
            'content-length' => 'Content-Length',
            'content-range' => 'Content-Range',
            'last-modified' => 'Last-Modified',
        ];

        return $map[$name] ?? $name;
    }

    /**
     * Weak validator derived from the file identity. The bytes behind a
     * relay file never change (re-upload creates a new file), so id + size
     * is a stable validator for caching clients.
     */
    protected function etagFor(RelayFile $file): string
    {
        return 'W/"relay-' . $file->id . '-' . (int) $file->size . '"';
    }

    /**
     * @param  array<int, string>|null  $header
     */
    protected function ifNoneMatchMatches(Request $request, string $etag): bool
    {
        $value = trim((string) $request->header('If-None-Match'));

        if ($value === '') {
            return false;
        }

        if ($value === '*') {
            return true;
        }

        foreach (explode(',', $value) as $candidate) {
            if (trim($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * If-Range with a date validator (or anything that is not our ETag)
     * counts as stale, which simply serves the full body — the safe path
     * for a client whose cache does not match.
     */
    protected function ifRangeMatches(Request $request, string $etag): bool
    {
        $value = trim((string) $request->header('If-Range'));

        return $value === $etag;
    }

    protected function contentDisposition(string $name, bool $download): string
    {
        $disposition = $download ? 'attachment' : 'inline';
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', str_replace(['"', '\\'], '_', $name));

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $ascii,
            rawurlencode($name)
        );
    }

    protected function errorPage(int $status): string
    {
        if ($status === 404) {
            return 'فایل در دسترس نیست.' . "\n";
        }

        if ($status === 403) {
            return 'دسترسی به این فایل مجاز نیست.' . "\n";
        }

        return 'خطای موقت در دریافت فایل از سرویس ذخیره‌سازی.' . "\n";
    }
}
