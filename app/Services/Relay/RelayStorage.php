<?php

namespace App\Services\Relay;

use App\RelayEvent;
use App\RelayFile;
use App\RelayLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stores files uploaded from the panel on the relay's primary tier and hands
 * back a public link on this site's domain.
 *
 * This is what backs the "combined" storage type: bytes live on Telegram
 * Drive, in-demand files are promoted into the 9drive cache, and viewers only
 * ever see /r/{token} on the site domain.
 */
class RelayStorage
{
    /** @var RelayManager */
    protected $relay;

    public function __construct(RelayManager $relay)
    {
        $this->relay = $relay;
    }

    public function isReady(): bool
    {
        return (bool) config('media_relay.enabled') && $this->relay->telegram()->isConfigured();
    }

    public function storeUploadedFile(UploadedFile $file, string $name = null, string $folder = null): string
    {
        $name = $name ?: $file->getClientOriginalName();

        return $this->storePath(
            $file->getRealPath(),
            $name,
            $file->getMimeType(),
            $file->getClientOriginalExtension(),
            (int) $file->getSize(),
            $folder
        );
    }

    public function storeUrl(string $url, string $name = null): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'flixy_relay_');

        if ($tmp === false) {
            return null;
        }

        try {
            $in = @fopen($url, 'rb');

            if ($in === false) {
                return null;
            }

            $out = fopen($tmp, 'wb');

            if ($out === false) {
                fclose($in);

                return null;
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $name = $name ?: (basename($path) ?: 'media');

            return $this->storePath(
                $tmp,
                $name,
                null,
                pathinfo($name, PATHINFO_EXTENSION),
                (int) @filesize($tmp)
            );
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function storePath(string $path, string $name, string $mime = null, string $extension = null, int $size = 0, string $folder = null, ?array &$metrics = null): string
    {
        $folder = trim((string) $folder, " /\\") ?: null;

        if (!$this->isReady()) {
            throw new RuntimeException('Media relay is disabled or Telegram Drive is not configured.');
        }

        // Hash once and use the same value for the lock and the lookup. Two
        // ingest jobs for the same bytes used to both miss the dedup check
        // and upload the file twice before either row existed. A MySQL named
        // lock (released by the connection on a crash) serializes the
        // check/upload/create sequence across queue workers.
        $checksum = $this->checksumOf($path);
        $lockName = $checksum ? 'flixy_upload_' . md5($checksum) : null;
        $locked = false;
        $db = DB::connection();

        if ($lockName && $db->getDriverName() === 'mysql') {
            // Use the write PDO explicitly: a named lock lives on one MySQL
            // connection, so the re-check and release must use that same one.
            $lock = $db->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lockName, 900], false);
            $locked = (int) ($lock->acquired ?? 0) === 1;

            if (!$locked) {
                throw new RuntimeException('Another upload of the same file is still in progress; retrying later.');
            }
        }

        try {
            // Identical bytes already stored? Point a fresh link at the
            // existing copy instead of uploading it again. Re-check while
            // holding the lock, because the first check may race another job.
            $existing = $checksum
                ? $db->table('relay_files')->where('checksum', $checksum)->first()
                : null;

            if ($existing) {
                $metrics = [
                    'upload_status' => UploadMetrics::STATUS_DEDUPLICATED,
                    'upload_bytes' => 0,
                    'upload_duration_ms' => null,
                    'upload_speed_bps' => null,
                ];
                $link = RelayLink::issueFor($existing, $name);

                RelayEvent::create([
                    'relay_file_id' => $existing->id,
                    'event' => 'uploaded',
                    'served_by' => 'telegram',
                    'bytes' => (int) $existing->size,
                    'message' => 'Deduplicated upload: reused the stored copy.',
                    'created_at' => now(),
                ]);

                return $link->streamUrl();
            }

            $chat = (string) config('media_relay.telegram.storage_chat', '');

            $result = $this->relay->telegram()->uploadFromPath(
                $path,
                $name,
                $mime ?: 'application/octet-stream',
                $chat,
                $folder
            );

            $fileId = $result['file_id'] ?? ($result['id'] ?? null);

            if (empty($fileId)) {
                throw new RuntimeException('Telegram Drive did not return a file id.');
            }

            $file = new RelayFile();
            $file->name = $name;
            $file->size = (int) ($result['size'] ?? $size);
            $file->mime = $result['mime'] ?? $mime;
            $file->extension = $extension;
            // Prefer Telegram Drive's sha256 (hash of the original file before
            // any split); fall back to hashing the local copy.
            $file->checksum = $result['sha256'] ?? $checksum;
            $file->tg_file_id = (string) $fileId;
            $file->tg_backend = (string) config('media_relay.telegram.default_backend', 'telegram');
            $file->tg_storage_chat = $chat;
            $file->folder = $folder;
            $file->cache_state = RelayFile::COLD;
            $file->owner = 'panel';
            $metrics = UploadMetrics::fromResult($result, (int) ($result['size'] ?? $size));
            UploadMetrics::apply($file, $metrics);
            $file->save();

            // The temp upload still exists on this server, so this is the only
            // moment a poster frame can be extracted cheaply.
            Thumbnail::make($path, (string) ($file->mime ?? $mime ?? ''), $file);

            $link = RelayLink::issueFor($file, $name);

            RelayEvent::create([
                'relay_file_id' => $file->id,
                'event' => 'uploaded',
                'served_by' => 'telegram',
                'bytes' => (int) $file->size,
                'message' => 'Stored on Telegram Drive (combined storage).',
                'created_at' => now(),
            ]);

            return $link->streamUrl();
        } finally {
            if ($locked) {
                $db->select('SELECT RELEASE_LOCK(?)', [$lockName], false);
            }
        }
    }

    /**
     * Find a previously stored copy with identical bytes.
     */
    public function findByChecksum(string $path, ?string $checksum = null): ?RelayFile
    {
        $checksum = $checksum ?: $this->checksumOf($path);

        if ($checksum === null) {
            return null;
        }

        return RelayFile::where('checksum', $checksum)->first();
    }

    protected function checksumOf(string $path): ?string
    {
        if (!is_file($path) || filesize($path) === 0) {
            return null;
        }

        $checksum = @hash_file('sha256', $path);

        return $checksum !== false && $checksum !== '' ? $checksum : null;
    }
}
