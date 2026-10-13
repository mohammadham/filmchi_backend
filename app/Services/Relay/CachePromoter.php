<?php

namespace App\Services\Relay;

use App\RelayEvent;
use App\RelayFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Promotes a file from the primary tier (Telegram Drive) into the cache tier
 * (9drive / Google Drive).
 *
 * The bytes travel straight from one remote service into the other: the
 * source is opened as a live HTTP stream and handed to the 9drive upload as
 * the multipart body, so nothing is ever written to this server's disk.
 */
class CachePromoter
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

    /**
     * @return array{ok:bool,skipped?:bool,message:string,tier?:string}
     */
    public function promote(RelayFile $file): array
    {
        if (!$this->ninedrive->isConfigured()) {
            return $this->fail($file, '9drive is not configured.');
        }

        if (empty($file->tg_file_id)) {
            return $this->fail($file, 'The file has no Telegram Drive copy to promote.');
        }

        if ($file->cache_state === RelayFile::WARM && !empty($file->nine_file_id)) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Already cached.'];
        }

        if ($file->cache_attempts >= (int) config('media_relay.cache.max_attempts', 5)) {
            return ['ok' => false, 'skipped' => true, 'message' => 'Promotion attempt limit reached.'];
        }

        if (!$this->demand->claim($file)) {
            return ['ok' => false, 'skipped' => true, 'message' => 'A promotion for this file is already running.'];
        }

        $opened = null;

        $file->cache_state = RelayFile::PROMOTING;
        $file->cache_error = null;
        $file->cache_attempts = (int) $file->cache_attempts + 1;
        $file->cache_checked_at = now();
        $file->save();

        $this->logEvent($file, RelayEvent::PROMOTE_REQUESTED, 'Promotion started (attempt ' . $file->cache_attempts . ').');

        try {
            $link = $this->telegram->presignedLink((string) $file->tg_file_id);
            $opened = Streams::open($link, [], (int) config('media_relay.relay.stream_timeout', 1800));

            if ($opened['status'] >= 400) {
                throw new \RuntimeException('Telegram Drive returned HTTP ' . $opened['status'] . ' for the source file.');
            }

            if ((int) $file->size <= 0 && !empty($opened['headers']['content-length'])) {
                $file->size = (int) $opened['headers']['content-length'];
            }

            $size = (int) $file->size;

            if ($size <= 0) {
                throw new \RuntimeException('Unknown file size; refusing to promote.');
            }

            $uploaded = $this->ninedrive->uploadStream(
                $opened['stream'],
                $size,
                $file->name,
                $file->mime ?: 'application/octet-stream'
            );

            $file->nine_file_id = $uploaded['id'] ?? null;
            $file->nine_provider_file_id = $uploaded['providerFileId'] ?? null;
            $file->nine_account = $uploaded['connectedAccount']['email'] ?? null;

            // A long-lived share token is what the public relay streams from.
            try {
                $share = $this->ninedrive->createShare((string) $file->nine_file_id);
                $file->nine_share_token = $share['token'];
                $file->nine_share_url = $this->ninedrive->sharePageUrl($share['token']);
            } catch (Throwable $e) {
                // Without a share token the file can still be served from the
                // public Google Drive link, so this is a warning, not a failure.
                Log::warning('9drive share creation failed', [
                    'relay_file_id' => $file->id,
                    'message' => $e->getMessage(),
                ]);
                $file->cache_error = 'Cached, but no share link: ' . $e->getMessage();
            }

            $file->cache_state = RelayFile::WARM;
            $file->promoted_at = now();
            $file->save();

            $this->logEvent($file, RelayEvent::PROMOTED, 'Promoted to the 9drive cache.');

            return ['ok' => true, 'message' => 'Promoted to cache.', 'tier' => 'cache'];
        } catch (Throwable $e) {
            $file->cache_state = RelayFile::FAILED;
            $file->cache_error = $e->getMessage();
            $file->save();

            $this->logEvent($file, RelayEvent::PROMOTE_FAILED, $e->getMessage());

            Log::error('Relay promotion failed', ['relay_file_id' => $file->id, 'message' => $e->getMessage()]);

            return $this->fail($file, $e->getMessage());
        } finally {
            if (is_array($opened) && is_resource($opened['stream'])) {
                fclose($opened['stream']);
            }

            $this->demand->release($file);
        }
    }

    /**
     * Drop the cached copy and fall back to the primary tier.
     */
    public function evict(RelayFile $file, bool $deleteRemote = false): array
    {
        if ($deleteRemote && !empty($file->nine_file_id) && $this->ninedrive->isConfigured()) {
            try {
                $this->ninedrive->deleteFile((string) $file->nine_file_id);
            } catch (Throwable $e) {
                Log::warning('9drive delete failed during eviction', [
                    'relay_file_id' => $file->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $file->cache_state = RelayFile::COLD;
        $file->nine_file_id = null;
        $file->nine_share_token = null;
        $file->nine_share_url = null;
        $file->nine_provider_file_id = null;
        $file->promoted_at = null;
        $file->save();

        $this->demand->reset($file);

        $this->logEvent($file, 'evicted', 'Removed from the cache tier.');

        return ['ok' => true, 'message' => 'Cache entry removed.'];
    }

    protected function fail(RelayFile $file, string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    protected function logEvent(RelayFile $file, string $event, string $message): void
    {
        RelayEvent::create([
            'relay_file_id' => $file->id,
            'event' => $event,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}
