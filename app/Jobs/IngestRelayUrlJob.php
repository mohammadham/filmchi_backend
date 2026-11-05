<?php

namespace App\Jobs;

use App\MediaGallery;
use App\RelayEvent;
use App\RelayFile;
use App\RelayLink;
use App\Services\Relay\RelayManager;
use App\Services\Relay\Thumbnail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Add from link" for the relay: download a remote URL to a persistent .part
 * file, push it into Telegram Drive, mint a public /r link and register the
 * file in the media library so Content can pick it.
 *
 * Runs on the queue so a multi-gigabyte fetch never blocks the panel.
 */
class IngestRelayUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7000;

    public $tries = 2;

    /** @var array<int, int> */
    public $backoff = [300, 900];

    /** @var int */
    public $relayFileId;

    /** @var string */
    public $url;

    /** @var string|null */
    public $folder;

    public function __construct(int $relayFileId, string $url, ?string $folder = null)
    {
        $this->relayFileId = $relayFileId;
        $this->url = $url;
        $this->folder = $folder;

        $this->onQueue((string) config('media_relay.queue.name', 'default'));
    }

    public function handle(RelayManager $relay): void
    {
        $file = RelayFile::find($this->relayFileId);

        if (!$file) {
            return;
        }

        $dir = storage_path('app/ingest');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $part = $dir . '/relay-' . $file->id . '.part';

        try {
            $this->setStatus($file, 'downloading', 0);
            $this->download($file, $part);

            $maxBytes = (int) config('media_relay.upload.max_size', 2048) * 1048576;

            if (filesize($part) > $maxBytes) {
                throw new \RuntimeException(sprintf(
                    'File is %d MB, above the %d MB limit for a direct Telegram Drive upload.',
                    (int) (filesize($part) / 1048576),
                    (int) ($maxBytes / 1048576)
                ));
            }

            $this->setStatus($file, 'uploading', 100);

            $mime = $this->guessMime($file->name);
            $file->folder = trim((string) $this->folder, " /\\") ?: null;

            $result = $relay->telegram()->uploadFromPath(
                $part,
                $file->name,
                $mime,
                (string) config('media_relay.telegram.storage_chat', ''),
                $file->folder
            );

            $fileId = $result['file_id'] ?? ($result['id'] ?? null);

            if (empty($fileId)) {
                throw new \RuntimeException('Telegram Drive did not return a file id.');
            }

            $file->size = (int) ($result['size'] ?? filesize($part));
            $file->mime = $result['mime'] ?? $mime;
            $file->extension = pathinfo($file->name, PATHINFO_EXTENSION);
            $file->checksum = $result['sha256'] ?? (hash_file('sha256', $part) ?: null);
            $file->tg_file_id = (string) $fileId;
            $file->tg_backend = (string) config('media_relay.telegram.default_backend', 'telegram');
            $file->tg_storage_chat = (string) config('media_relay.telegram.storage_chat', '');
            $file->cache_state = RelayFile::COLD;
            $file->ingest_status = 'completed';
            $file->ingest_progress = 100;
            $file->ingest_error = null;
            $file->save();

            Thumbnail::make($part, (string) $file->mime, $file);

            $link = new RelayLink();
            $link->relay_file_id = $file->id;
            $link->token = Str::random(40);
            $link->mode = RelayLink::MODE_STREAM;
            $link->label = $file->name;
            $link->save();

            MediaGallery::registerRelayFile($file, $link->streamUrl(), $file->name);

            RelayEvent::create([
                'relay_file_id' => $file->id,
                'event' => 'uploaded',
                'served_by' => 'telegram',
                'bytes' => (int) $file->size,
                'message' => 'Stored on Telegram Drive (from link).',
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Relay URL ingest failed', [
                'relay_file_id' => $file->id,
                'url' => $this->url,
                'message' => $e->getMessage(),
            ]);

            $file->ingest_status = 'failed';
            $file->ingest_error = Str::limit($e->getMessage(), 500);
            $file->save();

            throw $e;
        } finally {
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }

    protected function setStatus(RelayFile $file, string $status, int $progress): void
    {
        $file->ingest_status = $status;
        $file->ingest_progress = max(0, min(100, $progress));
        $file->save();
    }

    /**
     * Stream the remote file to disk, updating progress every few seconds.
     */
    protected function download(RelayFile $file, string $part): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => (int) config('media_relay.ingest.timeout', 3600),
            'user_agent' => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0'),
            'follow_location' => 1,
        ]]);

        $remote = @fopen($this->url, 'rb', false, $context);

        if ($remote === false) {
            throw new \RuntimeException('could not open the source URL');
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
                'Remote file is %d MB, above the %d MB limit for a direct Telegram Drive upload.',
                (int) ($expected / 1048576),
                (int) ($maxBytes / 1048576)
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
                    $this->setStatus($file, 'downloading', (int) ($received / $expected * 100));
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

    protected function guessMime(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
