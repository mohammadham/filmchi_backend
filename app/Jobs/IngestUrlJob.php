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
     * Fetch the remote file to disk.
     *
     * The scraper host throttles every connection to about 100 KB/s, so when
     * it advertises byte ranges the file is pulled over several connections at
     * once; a source without range support falls back to a plain stream.
     */
    protected function download($source, string $part): void
    {
        $probe = $this->probeSource();
        $maxBytes = (int) config('media_relay.upload.max_size', 2048) * 1048576;

        if ($probe['size'] > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'Remote file is %d MB, above the %d MB limit for direct Telegram Drive uploads. '
                . 'The split/merge scenario for large files is not wired up yet.',
                (int) ($probe['size'] / 1048576),
                (int) config('media_relay.upload.max_size', 2048)
            ));
        }

        if ($probe['ranges'] && $probe['size'] > 0 && $this->connectionCount() > 1) {
            try {
                $this->downloadSegmented($source, $part, $probe['size']);

                return;
            } catch (Throwable $e) {
                // A source can advertise ranges and still misbehave mid-transfer;
                // the plain stream below is the safety net, not a second failure.
                Log::info('Segmented ingest download failed; retrying as one stream', [
                    'source_id' => $this->sourceId,
                    'message' => $e->getMessage(),
                ]);

                @unlink($part);
            }
        }

        $this->downloadStream($source, $part, $probe['size']);
    }

    /** Connections used for a single file. */
    protected function connectionCount(): int
    {
        return max(1, (int) config('media_relay.ingest.connections', 8));
    }

    /**
     * Ask for two bytes and read the answer: the total size and whether the
     * source honours Range requests. A source that ignores the range answers
     * 200 with the full length, which lands here as ranges = false.
     */
    protected function probeSource(): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Range: bytes=0-1\r\n",
            'timeout' => 30,
            'user_agent' => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
            'follow_location' => 1,
        ]]);

        $remote = @fopen($this->url, 'rb', false, $context);

        if ($remote === false) {
            throw new \RuntimeException('could not open the source URL');
        }

        $headers = $http_response_header ?? [];

        @fread($remote, 2);
        fclose($remote);

        $size = 0;
        $ranges = false;

        foreach ($headers as $header) {
            $header = (string) $header;

            if (stripos($header, 'content-range:') === 0 && preg_match('#/(\d+)\s*$#', $header, $m)) {
                $size = (int) $m[1];
                $ranges = true;
            } elseif (stripos($header, 'content-length:') === 0 && $size === 0) {
                $size = (int) trim(substr($header, 15));
            } elseif (stripos($header, 'accept-ranges:') === 0 && stripos($header, 'bytes') !== false) {
                $ranges = true;
            }
        }

        return ['size' => $size, 'ranges' => $ranges];
    }

    /**
     * Split the file into equal ranged chunks and pull them concurrently into
     * one pre-sized file. Writing at an offset keeps the completed chunks on
     * disk and avoids doubling the space a concatenation pass would need.
     */
    protected function downloadSegmented($source, string $part, int $total): void
    {
        $minimumChunk = 2 * 1048576;
        $connections = max(1, min($this->connectionCount(), (int) ceil($total / $minimumChunk)));
        $chunk = (int) ceil($total / $connections);

        $out = @fopen($part, 'c+b');

        if ($out === false) {
            throw new \RuntimeException('could not open the ingest target file');
        }

        if (!ftruncate($out, $total)) {
            fclose($out);

            throw new \RuntimeException('could not reserve space for the ingest target file');
        }

        $multi = curl_multi_init();
        $handles = [];
        $written = [];
        $expectedBytes = [];
        $received = 0;

        try {
            for ($i = 0; $i < $connections; $i++) {
                $start = $i * $chunk;

                if ($start >= $total) {
                    break;
                }

                $length = min($chunk, $total - $start);
                $written[$i] = 0;
                $expectedBytes[$i] = $length;

                $writer = function ($ch, string $data) use ($out, $start, $length, $i, &$written, &$received) {
                    $remaining = $length - $written[$i];

                    // A source that ignored the range and started sending the
                    // whole file would run past the chunk; stop it there.
                    if ($remaining <= 0) {
                        return 0;
                    }

                    if (strlen($data) > $remaining) {
                        $data = substr($data, 0, $remaining);
                    }

                    fseek($out, $start + $written[$i]);
                    $bytes = (int) fwrite($out, $data);
                    $written[$i] += $bytes;
                    $received += $bytes;

                    return strlen($data);
                };

                $handle = curl_init($this->url);

                curl_setopt_array($handle, [
                    CURLOPT_RANGE => $start . '-' . ($start + $length - 1),
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_LOW_SPEED_LIMIT => 1,
                    CURLOPT_LOW_SPEED_TIME => (int) config('media_relay.ingest.stall_timeout', 300),
                    CURLOPT_USERAGENT => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
                    CURLOPT_WRITEFUNCTION => $writer,
                ]);

                curl_multi_add_handle($multi, $handle);
                $handles[$i] = $handle;
            }

            $lastUpdate = microtime(true);

            do {
                $status = curl_multi_exec($multi, $running);

                if ($running) {
                    curl_multi_select($multi, 1.0);
                }

                if (microtime(true) - $lastUpdate >= 5) {
                    $this->setStatus($source, 'downloading', (int) min(100, $received / $total * 100));
                    $lastUpdate = microtime(true);
                }
            } while ($running && $status === CURLM_OK);

            $problem = null;

            foreach ($handles as $i => $handle) {
                $errno = curl_errno($handle);
                $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                if ($errno !== 0) {
                    $problem = $problem ?: curl_error($handle);
                } elseif ($code !== 206) {
                    $problem = $problem ?: 'the source answered ' . $code . ' to a ranged request';
                } elseif ($written[$i] !== $expectedBytes[$i]) {
                    $problem = $problem ?: sprintf(
                        'chunk %d stopped at %d of %d bytes',
                        $i,
                        $written[$i],
                        $expectedBytes[$i]
                    );
                }

                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
        } finally {
            curl_multi_close($multi);
            fflush($out);
            fclose($out);
        }

        if (!empty($problem)) {
            throw new \RuntimeException($problem);
        }

        clearstatcache(true, $part);

        if ((int) filesize($part) !== $total) {
            throw new \RuntimeException(sprintf(
                'the assembled file is %d bytes, expected %d',
                (int) filesize($part),
                $total
            ));
        }
    }

    /**
     * Single-connection fallback for sources without range support.
     */
    protected function downloadStream($source, string $part, int $expected): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            // Per-read timeout: this is a stall guard, not a cap on the whole
            // transfer, so a slow-but-alive source keeps working while a dead
            // one frees the single worker within minutes.
            'timeout' => (int) config('media_relay.ingest.stall_timeout', 300),
            'user_agent' => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
            'follow_location' => 1,
        ]]);

        $remote = @fopen($this->url, 'rb', false, $context);

        if ($remote === false) {
            throw new \RuntimeException("could not open the source URL");
        }

        if ($expected === 0) {
            foreach (stream_get_meta_data($remote)['wrapper_data'] ?? [] as $header) {
                if (stripos((string) $header, 'content-length:') === 0) {
                    $expected = (int) trim(substr((string) $header, 15));
                    break;
                }
            }
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

        if ($expected > 0 && $received < $expected) {
            throw new \RuntimeException(sprintf(
                'the transfer stopped early: %d of %d bytes (stall or dropped connection)',
                $received,
                $expected
            ));
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
