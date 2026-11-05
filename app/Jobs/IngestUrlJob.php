<?php

namespace App\Jobs;

use App\Content;
use App\ContentSource;
use App\Episode;
use App\EpisodeSource;
use App\Season;
use App\RelayLink;
use App\Services\Relay\RelayStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls a remote URL into the combined storage: downloads the file to a
 * persistent .part file, dedup-checks it, uploads to Telegram Drive and
 * swaps the source's URL for the site-domain relay link.
 *
 * Runs on the Redis queue so the panel never blocks on a multi-gigabyte
 * fetch, and the single worker serialises ingests so the server's
 * bandwidth is not shredded by parallel pulls.
 */
class IngestUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Download + upload of a multi-gigabyte file. */
    public $timeout = 7000;

    public $tries = 2;

    /** @var array<int, int> */
    public $backoff = [300, 900];

    /** @var string */
    public $sourceType;

    /** @var int */
    public $sourceId;

    /** @var string */
    public $url;

    /** @var string */
    public $name;

    public function __construct(string $sourceType, int $sourceId, string $url, string $name)
    {
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->url = $url;
        $this->name = $name;

        $this->onQueue((string) config('media_relay.queue.name', 'default'));
    }

    public function handle(RelayStorage $storage): void
    {
        $source = $this->source();

        if (!$source) {
            return;
        }

        $dir = storage_path('app/ingest');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $part = $dir . '/ingest-' . $this->sourceId . '.part';

        try {
            $this->setStatus($source, 'downloading', 0);
            $this->download($source, $part);

            if (!$this->isRemoteSizeAcceptable($part)) {
                throw new \RuntimeException(sprintf(
                    'File is %d MB, above the %d MB limit for direct Telegram Drive uploads. '
                    . 'The split/merge scenario for large files is not wired up yet.',
                    (int) (filesize($part) / 1048576),
                    (int) config('media_relay.upload.max_size', 2048)
                ));
            }

            $this->setStatus($source, 'uploading', 100);

            $relayUrl = $storage->storePath(
                $part,
                $this->name,
                $this->guessMime($source->source),
                null,
                0,
                $this->folderFor($source)
            );

            $source->source = $relayUrl;
            $source->ingest_status = 'completed';
            $source->ingest_progress = 100;
            $source->save();
        } catch (Throwable $e) {
            Log::warning('URL ingest failed', [
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
                'message' => $e->getMessage(),
            ]);

            $source->ingest_status = 'failed';
            $source->ingest_error = Str::limit($e->getMessage(), 500);
            $source->save();

            throw $e;
        } finally {
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }

    protected function source()
    {
        if ($this->sourceType === 'content') {
            return ContentSource::find($this->sourceId);
        }

        if ($this->sourceType === 'episode') {
            return EpisodeSource::find($this->sourceId);
        }

        return null;
    }

    /**
     * The title's storage folder: files of one anime live together on both
     * tiers instead of piling up in the root. Falls back to the title itself.
     */
    protected function folderFor($source): ?string
    {
        $content = null;

        if ($source instanceof ContentSource) {
            $content = Content::find($source->content_id);
        } elseif ($source instanceof EpisodeSource) {
            $episode = Episode::find($source->episode_id);
            $season = $episode ? Season::find($episode->season_id) : null;
            $content = $season ? Content::find($season->content_id) : null;
        }

        if (!$content) {
            return null;
        }

        $folder = (string) ($content->storage_folder ?: $content->title);
        $folder = preg_replace('#[\\\\/]+#', ' ', $folder);
        $folder = preg_replace('/\s+/u', ' ', (string) $folder);

        return trim((string) $folder) ?: null;
    }

    protected function setStatus($source, string $status, int $progress): void
    {
        $source->ingest_status = $status;
        $source->ingest_progress = max(0, min(100, $progress));
        $source->save();
    }

    /**
     * Stream the remote file to disk, updating the ingest progress every
     * few percent so the panel can follow along.
     */
    protected function download($source, string $part): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => (int) config('media_relay.ingest.timeout', 3600),
            'user_agent' => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
            'follow_location' => 1,
        ]]);

        $remote = @fopen($this->url, 'rb', false, $context);

        if ($remote === false) {
            throw new \RuntimeException("could not open the source URL");
        }

        $meta = stream_get_meta_data($remote);
        $expected = 0;

        foreach ($meta['wrapper_data'] ?? [] as $header) {
            if (stripos((string) $header, 'content-length:') === 0) {
                $expected = (int) trim(substr((string) $header, 15));
                break;
            }
        }

        $maxBytes = (int) config('media_relay.upload.max_size', 2048) * 1048576;

        if ($expected > $maxBytes) {
            fclose($remote);

            throw new \RuntimeException(sprintf(
                'Remote file is %d MB, above the %d MB limit for direct Telegram Drive uploads. '
                . 'The split/merge scenario for large files is not wired up yet.',
                (int) ($expected / 1048576),
                (int) config('media_relay.upload.max_size', 2048)
            ));
        }

        $out = @fopen($part, 'wb');

        if ($out === false) {
            fclose($remote);

            throw new \RuntimeException('could not open the ingest target file');
        }

        $received = 0;
        $lastUpdate = microtime(true);

        try {
            while (!feof($remote)) {
                $buffer = fread($remote, 1048576);

                if ($buffer === false) {
                    break;
                }

                fwrite($out, $buffer);
                $received += strlen($buffer);

                if ($expected > 0 && microtime(true) - $lastUpdate >= 5) {
                    $this->setStatus($source, 'downloading', (int) ($received / $expected * 100));
                    $lastUpdate = microtime(true);
                }
            }
        } finally {
            fclose($remote);
            fclose($out);
        }

        if ($received === 0) {
            throw new \RuntimeException('the source URL returned no bytes');
        }
    }

    protected function isRemoteSizeAcceptable(string $part): bool
    {
        $maxBytes = (int) config('media_relay.upload.max_size', 2048) * 1048576;

        return filesize($part) <= $maxBytes;
    }

    protected function guessMime(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'm3u8' => 'application/vnd.apple.mpegurl',
            default => 'video/mp4',
        };
    }
}
