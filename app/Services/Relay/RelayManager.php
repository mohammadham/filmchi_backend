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

        PromoteToCacheJob::dispatch($file->id);

        return true;
    }

    /* ---------------------------------------------------------------- serving */

    /**
     * Build the response that relays the file to the visitor.
     */
    public function serve(RelayFile $file, Request $request, bool $download = false): Response
    {
        $source = $this->source($file, $download);

        if (!config('media_relay.relay.proxy', true)) {
            return redirect()->away($source['url']);
        }

        $upstreamHeaders = $source['headers'];

        if ($request->header('Range')) {
            $upstreamHeaders['Range'] = (string) $request->header('Range');
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

            return response($this->errorPage($status), $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
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
            'Cache-Control' => 'public, max-age=0, must-revalidate',
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
