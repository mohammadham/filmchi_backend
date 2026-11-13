<?php

namespace App\Jobs;

use App\Content;
use App\ContentSource;
use App\Episode;
use App\EpisodeSource;
use App\Season;
use App\RelayLink;
use App\Services\Relay\RelaySettings;
use App\Services\Relay\RelayStorage;
use App\Services\Relay\UploadMetrics;
use App\Services\Relay\WorkerRegistry;
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
 * fetch. The worker pool size is panel-controlled; each job still owns its
 * own connection budget and .part file.
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
        // A queue worker is long-lived; refresh panel-saved ingest tuning for
        // every job instead of only at process boot.
        RelaySettings::apply();

        $source = $this->source();

        if (!$source) {
            return;
        }

        // A retried queue job can outlive its DB row (worker crash, timeout,
        // deploy). Never download a source that already finished; upload
        // retries belong to the storage layer, not to a second ingest.
        if ((string) $source->ingest_status === 'completed') {
            Log::info('ingest skipped: source already completed', ['source_id' => $this->sourceId]);

            return;
        }

        $dir = storage_path('app/ingest');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $part = $dir . '/ingest-' . $this->sourceId . '.part';

        $uploadStartedAt = null;
        $uploadBytes = 0;

        try {
            $source->ingest_bytes = 0;
            $source->ingest_speed_bps = 0;
            $source->ingest_started_at = now();
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

            $this->setStatus($source, 'uploading', 0);
            UploadMetrics::markStarted($source);
            $uploadStartedAt = now();
            $uploadBytes = (int) @filesize($part);
            $metrics = [];

            $relayUrl = $storage->storePath(
                $part,
                $this->name,
                $this->guessMime($source->source),
                null,
                0,
                $this->folderFor($source),
                $metrics
            );

            UploadMetrics::apply($source, $metrics);
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

            if ($uploadStartedAt !== null) {
                UploadMetrics::markFailed($source, $uploadBytes, $uploadStartedAt);
            }

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

    protected function setStatus($source, string $status, int $progress, ?int $bytes = null, ?int $speedBps = null): void
    {
        $source->ingest_status = $status;
        $source->ingest_progress = max(0, min(100, $progress));

        if ($bytes !== null) {
            $source->ingest_bytes = max(0, $bytes);
        }

        if ($speedBps !== null) {
            $source->ingest_speed_bps = max(0, $speedBps);
        }

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

                $registry = app(WorkerRegistry::class);

                // A cold Worker/origin can answer one chunk with 200 even after
                // the probe was told ranges exist. Give the origin one short
                // warm-up pass before dropping to the slow single stream.
                if ($registry->isEligible($this->url) && $registry->hasActiveWorkers()) {
                    usleep((int) config('media_relay.workers.cold_retry_delay_ms', 2000) * 1000);

                    try {
                        $retryProbe = $this->probeSource();

                        if ($retryProbe['ranges'] && $retryProbe['size'] > 0 && $this->connectionCount() > 1) {
                            $this->downloadSegmented($source, $part, $retryProbe['size']);

                            return;
                        }
                    } catch (Throwable $retryError) {
                        Log::info('Worker cold-retry failed; retrying as one stream', [
                            'source_id' => $this->sourceId,
                            'message' => $retryError->getMessage(),
                        ]);

                        @unlink($part);
                    }
                }
            }
        }

        $this->downloadStream($source, $part, $probe['size']);
    }

    /**
     * Connections used for a single file.
     *
     * A positive aggregate ceiling is divided by the panel concurrency so
     * four parallel files cannot open four times the socket budget. With no
     * ceiling the old per-file value applies unchanged.
     */
    protected function connectionCount(): int
    {
        $perFile = max(1, (int) config('media_relay.ingest.connections', 8));
        $total = max(0, (int) config('media_relay.ingest.total_connections', 0));

        if ($total === 0) {
            return $perFile;
        }

        $concurrency = max(1, (int) config('media_relay.ingest.concurrency', 1));

        return max(1, min($perFile, intdiv($total, $concurrency)));
    }

    /**
     * Ask for two bytes and read the answer: the total size and whether the
     * source honours Range requests. A source that ignores the range answers
     * 200 with the full length, which lands here as ranges = false.
     */
    protected function probeSource(): array
    {
        $registry = app(WorkerRegistry::class);
        $attempts = 2;
        $route = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            // Keep the same Worker for the cold-cache retry. Re-routing here
            // can land on a different cold Worker, which answers 200 again and
            // makes the job fall back to the single-connection downloader.
            if ($route === null) {
                $route = $registry->route($this->url, '0-1');
            }

            $warm = $attempt === 0 && $route['proxied'];

            $probe = $this->readProbe(
                $route,
                $warm ? max(2, (int) config('media_relay.workers.warmup_bytes', 1048576)) : 2
            );

            $probe['proxied'] = (bool) $route['proxied'];
            $probe['worker_id'] = $route['worker_id'];

            if (!$route['proxied'] || $probe['ranges'] || $probe['status'] !== 200) {
                return $probe;
            }

            if ($attempt + 1 < $attempts) {
                usleep((int) config('media_relay.workers.cold_retry_delay_ms', 2000) * 1000);
            }
        }

        return $probe;
    }

    /**
     * Read only headers and a bounded prefix of a probe response.
     *
     * A Worker may answer a cold ranged request with 200 and the full file.
     * Reading a small prefix lets the origin/CDN warm up without buffering the
     * media; probeSource then asks once more for a real 206.
     */
    protected function readProbe(array $route, int $maxBytes): array
    {
        $requestHeaders = [];

        foreach (($route['headers'] ?? []) as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        if (!$route['proxied']) {
            $requestHeaders[] = 'Range: bytes=0-1';
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $requestHeaders),
            'timeout' => 30,
            'user_agent' => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
            'follow_location' => 1,
            'ignore_errors' => true,
        ]]);

        $remote = @fopen($route['url'], 'rb', false, $context);

        if ($remote === false) {
            throw new \RuntimeException('could not open the source URL');
        }

        $headers = $http_response_header ?? [];
        $status = 0;
        $size = 0;
        $ranges = false;

        foreach ($headers as $header) {
            $header = (string) $header;

            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($header, 'content-range:') === 0) {
                if (preg_match('#/(\d+)\s*$#', $header, $m)) {
                    $size = (int) $m[1];
                }
                $ranges = true;
            } elseif (stripos($header, 'content-length:') === 0 && $size === 0) {
                $size = (int) trim(substr($header, 15));
            } elseif (
                !$route['proxied']
                && stripos($header, 'accept-ranges:') === 0
                && stripos($header, 'bytes') !== false
            ) {
                $ranges = true;
            }
        }

        $read = 0;

        while (!feof($remote) && $read < $maxBytes) {
            $buffer = fread($remote, min(65536, max(1, $maxBytes - $read)));

            if ($buffer === false || $buffer === '') {
                break;
            }

            $read += strlen($buffer);
        }

        fclose($remote);

        return ['size' => $size, 'ranges' => $ranges, 'status' => $status];
    }

    protected function curlHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $out[] = $name . ': ' . $value;
        }

        return $out;
    }

    /**
     * Pull the file as a dynamic queue of small ranged chunks.
     *
     * Instead of slicing the file into one big equal chunk per connection
     * (100-230 MB each), the file is cut into fixed-size chunks and every
     * finished slot immediately picks up the next chunk. Fast Workers
     * naturally take more chunks, a retry only repeats a few MB instead of a
     * couple hundred, and no single chunk can sit for minutes without
     * progress. Writing at an offset keeps completed chunks on disk.
     */
    protected function downloadSegmented($source, string $part, int $total): void
    {
        $chunkMb = max(1, (int) config('media_relay.ingest.chunk_mb', 8));
        $minChunkMb = max(1, (int) config('media_relay.ingest.min_chunk_mb', 1));
        $maxChunkMb = max($minChunkMb, (int) config('media_relay.ingest.max_chunk_mb', 16));
        $chunkSize = max($minChunkMb, min($maxChunkMb, $chunkMb)) * 1048576;
        $registry = app(WorkerRegistry::class);
        $pool = $registry->selectPool($this->url);

        // Cap how many Workers one file may use. 0 keeps the whole pool.
        $maxWorkers = max(0, (int) config('media_relay.ingest.max_workers', 0));

        if ($maxWorkers > 0) {
            $pool = $pool->take($maxWorkers)->values();
        }

        // Each concurrent slot is one connection. With Workers the pool size
        // times streams-per-worker sets the width and RELAY_INGEST_CONNECTIONS
        // stays the ceiling; without Workers the direct count applies alone.
        $slots = $pool->isNotEmpty()
            ? min($pool->count() * max(1, (int) config('media_relay.ingest.streams_per_worker', 1)), $this->connectionCount())
            : $this->connectionCount();

        $slots = max(1, min($slots, (int) ceil($total / $chunkSize)));

        $out = @fopen($part, 'c+b');

        if ($out === false) {
            throw new \RuntimeException('could not open the ingest target file');
        }

        if (!ftruncate($out, $total)) {
            fclose($out);

            throw new \RuntimeException('could not reserve space for the ingest target file');
        }

        // Pending queue: chunk index => ['start' => int, 'length' => int].
        // Materialise chunks lazily so max_pending_chunks bounds memory while
        // still letting the remaining chunks be fetched as slots free up.
        $pending = [];
        $chunkCount = (int) ceil($total / $chunkSize);
        $configuredPending = max(0, (int) config('media_relay.ingest.max_pending_chunks', 0));
        $pendingLimit = $configuredPending > 0 ? $configuredPending : $chunkCount;
        $nextChunk = 0;

        $fillPending = function () use (&$pending, &$nextChunk, $chunkCount, $chunkSize, $total, $pendingLimit) {
            while ($nextChunk < $chunkCount && count($pending) < $pendingLimit) {
                $start = $nextChunk * $chunkSize;
                $pending[$nextChunk] = ['start' => $start, 'length' => min($chunkSize, $total - $start)];
                $nextChunk++;
            }
        };

        $fillPending();

        $multi = curl_multi_init();
        $handles = [];   // chunk index => curl handle
        $routes = [];    // chunk index => route used
        $written = [];   // chunk index => bytes written
        $lengths = [];   // chunk index => expected bytes
        $inFlight = [];  // chunk index => true, ordered by dispatch
        $failed = [];    // chunk index => error message
        $received = 0;
        $downloadStartedAt = microtime(true);

        try {
            // Track the curl handle object hash -> chunk index. The write
            // callback only receives the handle, and resource ids get reused,
            // so an explicit map set at dispatch time is the safe binding.
            $handleChunkMap = [];

            $bindHandle = function ($handle, int $index) use (&$handleChunkMap) {
                $handleChunkMap[spl_object_id($handle)] = $index;
            };

            $chunkFor = function ($handle) use (&$handleChunkMap) {
                return $handleChunkMap[spl_object_id($handle)] ?? null;
            };

            $writer = function ($ch, string $data) use ($out, $chunkSize, $chunkFor, &$lengths, &$written, &$received) {
                $index = $chunkFor($ch);

                if ($index === null) {
                    return 0;
                }

                $start = $index * $chunkSize;
                $remaining = ($lengths[$index] ?? 0) - ($written[$index] ?? 0);

                // A source that ignored the range and started sending the
                // whole file would run past the chunk; stop it there.
                if ($remaining <= 0) {
                    return 0;
                }

                if (strlen($data) > $remaining) {
                    $data = substr($data, 0, $remaining);
                }

                fseek($out, $start + ($written[$index] ?? 0));
                $bytes = fwrite($out, $data);

                if ($bytes === false || $bytes !== strlen($data)) {
                    return 0;
                }

                $written[$index] = ($written[$index] ?? 0) + $bytes;
                $received += $bytes;

                return $bytes;
            };

            // Worker selection strategy
            $selectionStrategy = config('media_relay.workers.selection_strategy', 'round_robin');
            $workerIndices = array_keys($pool->toArray());
            $workerCount = count($workerIndices);
            $currentWorker = 0;

            $startChunk = function (int $index) use ($registry, $multi, &$pending, &$handles, &$routes, &$written, &$lengths, &$inFlight, $writer, $chunkSize, $bindHandle, $selectionStrategy, &$workerIndices, &$workerCount, &$currentWorker, $pool) {
                $meta = $pending[$index];
                unset($pending[$index]);

                $start = $meta['start'];
                $length = $meta['length'];
                $written[$index] = 0;
                $lengths[$index] = $length;
                $inFlight[$index] = true;

                $range = $start . '-' . ($start + $length - 1);

                if ($selectionStrategy === 'round_robin' && $workerCount > 0) {
                    // Round-robin worker selection
                    $worker = $pool->get($workerIndices[$currentWorker]);
                    $currentWorker = ($currentWorker + 1) % $workerCount;
                    $route = $registry->routeFor($worker, $this->url, $range);
                } else {
                    // Default: fastest worker
                    $route = $registry->route($this->url, $range);
                }

                $routes[$index] = $route;
                $options = [
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => 30,
                    CURLOPT_LOW_SPEED_LIMIT => 1,
                    CURLOPT_LOW_SPEED_TIME => (int) config('media_relay.ingest.chunk_stall_timeout', 60),
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_USERAGENT => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
                    CURLOPT_WRITEFUNCTION => $writer,
                ];

                if ($route['proxied']) {
                    if (!empty($route['headers'])) {
                        $options[CURLOPT_HTTPHEADER] = $this->curlHeaders($route['headers']);
                    }
                } else {
                    $options[CURLOPT_RANGE] = $range;
                }

                $handle = curl_init($route['url']);
                curl_setopt_array($handle, $options);

                curl_multi_add_handle($multi, $handle);
                $handles[$index] = $handle;
                $bindHandle($handle, $index);
            };

            // Fill every slot with the first chunks of the queue.
            $fillSlots = function () use (&$handles, &$pending, $slots, $startChunk, $fillPending) {
                $fillPending();

                while (count($handles) < $slots) {
                    $index = array_key_first($pending);

                    if ($index === null) {
                        break;
                    }

                    $startChunk($index);
                }
            };

            $fillSlots();

            $lastUpdate = microtime(true);

            // curl_multi_info_read is the only reliable "this handle finished"
            // signal, so the loop polls it and refills freed slots from the
            // pending queue as soon as a chunk completes.
            $harvest = function () use ($multi, $registry, &$handles, &$routes, &$written, &$lengths, &$inFlight, &$failed, &$received, &$pending, &$handleChunkMap, $startChunk, $fillSlots) {
                while (true) {
                    $info = curl_multi_info_read($multi);

                    if ($info === false) {
                        break;
                    }

                    $handle = $info['handle'];
                    $index = null;

                    foreach ($handles as $candidate => $candidateHandle) {
                        if ($candidateHandle === $handle) {
                            $index = $candidate;
                            break;
                        }
                    }

                    if ($index === null) {
                        continue;
                    }

                    $errno = curl_errno($handle);
                    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    $durationMs = max(1, (int) round(((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000));
                    $route = $routes[$index] ?? [];
                    $expected = $lengths[$index];
                    $got = $written[$index] ?? 0;
                    $message = null;

                    if ($errno !== 0) {
                        $message = curl_error($handle);
                    } elseif ($code !== 206) {
                        $message = 'the source answered ' . $code . ' to a ranged request';
                    } elseif ($got !== $expected) {
                        $message = sprintf(
                            'chunk %d stopped at %d of %d bytes',
                            $index,
                            $got,
                            $expected
                        );
                    }

                    if ($message === null) {
                        $registry->reportSuccess($route['worker_id'] ?? null, $got, $durationMs);
                    } else {
                        $registry->reportFailure($route['worker_id'] ?? null, $message);
                        $failed[$index] = $message;
                    }

                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($handles[$index], $inFlight[$index], $handleChunkMap[spl_object_id($handle)]);

                    // Free slot: materialise more work and immediately pick up
                    // the next pending chunk.
                    $fillSlots();
                }
            };

            do {
                $status = curl_multi_exec($multi, $running);

                if ($running) {
                    curl_multi_select($multi, 1.0);
                }

                $harvest();

                if (microtime(true) - $lastUpdate >= 5) {
                    $elapsed = max(1, microtime(true) - $downloadStartedAt);
                    $this->setStatus(
                        $source,
                        'downloading',
                        (int) min(100, $received / $total * 100),
                        $received,
                        (int) round($received / $elapsed)
                    );
                    $lastUpdate = microtime(true);
                }
            } while ((!empty($handles) || !empty($pending) || $nextChunk < $chunkCount) && $status === CURLM_OK);

            $harvest();

            // A failed chunk is retried on another Worker, not by restarting
            // the whole file. This is what keeps one bad endpoint from
            // degrading a multi-Worker download to a single stream.
            $failedWorkerIds = [];

            foreach (array_keys($failed) as $failedIndex) {
                $failedRoute = $routes[$failedIndex] ?? [];

                if (!empty($failedRoute['worker_id'])) {
                    $failedWorkerIds[] = (int) $failedRoute['worker_id'];
                }
            }

            $failedWorkerIds = array_values(array_unique($failedWorkerIds));
            $retryRound = 0;

            while (!empty($failed) && $retryRound < 2) {
                $retryRound++;
                $next = [];

                foreach ($failed as $i => $firstError) {
                    $start = $i * $chunkSize;
                    $length = min($chunkSize, $total - $start);
                    $range = $start . '-' . ($start + $length - 1);
                    $route = $registry->route($this->url, $range, $failedWorkerIds);
                    $written[$i] = 0;
                    $result = $this->downloadChunk($route, $out, $start, $length, $range);
                    $written[$i] = (int) $result['bytes'];

                    if (!empty($result['ok'])) {
                        $registry->reportSuccess($route['worker_id'] ?? null, $result['bytes'], $result['duration_ms']);
                    } else {
                        $registry->reportFailure($route['worker_id'] ?? null, (string) $result['error']);

                        if (!empty($route['worker_id'])) {
                            $failedWorkerIds[] = (int) $route['worker_id'];
                            $failedWorkerIds = array_values(array_unique($failedWorkerIds));
                        }

                        $next[$i] = (string) $result['error'] ?: $firstError;
                    }
                }

                $failed = $next;
            }

            if (!empty($failed)) {
                throw new \RuntimeException((string) reset($failed));
            }
        } finally {
            curl_multi_close($multi);
            fflush($out);
            fclose($out);
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
     * Download one range with a single Worker. Used for retrying just the
     * failed chunks after the concurrent first pass.
     */
    protected function downloadChunk(array $route, $out, int $start, int $length, string $range): array
    {
        $written = 0;
        $options = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => (int) config('media_relay.ingest.chunk_stall_timeout', 60),
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_USERAGENT => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($out, $start, $length, &$written) {
                $remaining = $length - $written;

                if ($remaining <= 0) {
                    return 0;
                }

                if (strlen($data) > $remaining) {
                    $data = substr($data, 0, $remaining);
                }

                fseek($out, $start + $written);
                $bytes = fwrite($out, $data);

                if ($bytes === false || $bytes !== strlen($data)) {
                    return 0;
                }

                $written += $bytes;

                return $bytes;
            },
        ];

        if ($route['proxied']) {
            if (!empty($route['headers'])) {
                $options[CURLOPT_HTTPHEADER] = $this->curlHeaders($route['headers']);
            }
        } else {
            $options[CURLOPT_RANGE] = $range;
        }

        $handle = curl_init($route['url']);

        if ($handle === false) {
            return ['ok' => false, 'bytes' => 0, 'duration_ms' => 0, 'error' => 'could not initialise curl'];
        }

        curl_setopt_array($handle, $options);
        curl_exec($handle);

        $errno = curl_errno($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $durationMs = max(1, (int) round(((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME)) * 1000));
        $error = $errno !== 0
            ? curl_error($handle)
            : ($code !== 206
                ? 'the source answered ' . $code . ' to a ranged request'
                : ($written !== $length ? 'the retry stopped early' : ''));

        curl_close($handle);

        return [
            'ok' => $error === '' && $written === $length,
            'bytes' => $written,
            'duration_ms' => $durationMs,
            'error' => $error ?: 'chunk retry failed',
        ];
    }

    /**
     * Single-connection fallback for sources without range support.
     */
    protected function downloadStream($source, string $part, int $expected): void
    {
        $route = app(WorkerRegistry::class)->route($this->url);
        $requestHeaders = [];

        foreach (($route['headers'] ?? []) as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $requestHeaders),
            // Per-read timeout: this is a stall guard, not a cap on the whole
            // transfer, so a slow-but-alive source keeps working while a dead
            // one frees the single worker within minutes.
            'timeout' => (int) config('media_relay.ingest.stall_timeout', 300),
            'user_agent' => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
            'follow_location' => 1,
        ]]);

        $remote = @fopen($route['url'], 'rb', false, $context);

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
        $downloadStartedAt = microtime(true);

        try {
            while (!feof($remote)) {
                $buffer = fread($remote, 1048576);

                if ($buffer === false) {
                    break;
                }

                fwrite($out, $buffer);
                $received += strlen($buffer);

                if (microtime(true) - $lastUpdate >= 5) {
                    $elapsed = max(1, microtime(true) - $downloadStartedAt);
                    $progress = $expected > 0 ? (int) ($received / $expected * 100) : 0;
                    $this->setStatus(
                        $source,
                        'downloading',
                        $progress,
                        $received,
                        (int) round($received / $elapsed)
                    );
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
