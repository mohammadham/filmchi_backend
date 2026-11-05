<?php

namespace App\Services\Relay;

use App\RelayEvent;
use App\RelayFile;
use Illuminate\Support\Facades\Http;
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

            // Cache the copy in the same per-title folder the primary tier
            // uses, so the two tiers stay browsable side by side.
            $folderId = null;

            if (!empty($file->folder)) {
                try {
                    $folderId = $this->ninedrive->ensureFolder((string) $file->folder);
                } catch (Throwable $e) {
                    Log::warning('9drive folder resolution failed; uploading to the default folder', [
                        'relay_file_id' => $file->id,
                        'folder' => $file->folder,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $uploaded = $this->ninedrive->uploadStream(
                $opened['stream'],
                $size,
                $file->name,
                $file->mime ?: 'application/octet-stream',
                $folderId
            );

            $file->nine_folder_id = $folderId;
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

            // Cheap post-promotion sanity check: the cached copy must be
            // reachable and its length must match what the primary tier
            // reported. HEAD only — no byte transfer.
            if (config('media_relay.cache.verify_after_promote', true)) {
                $this->verifyCachedCopy($file);
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
     * Total bytes currently held on the cache tier.
     */
    public function cacheUsage(): int
    {
        return (int) RelayFile::where('cache_state', RelayFile::WARM)->sum('size');
    }

    /**
     * How far the cache is over its budget, in bytes (0 when within it or
     * when no budget is configured).
     */
    public function overBudgetBytes(): int
    {
        $budget = (int) config('media_relay.cache.max_cache_bytes', 0);

        if ($budget <= 0) {
            return 0;
        }

        return max(0, $this->cacheUsage() - $budget);
    }

    /**
     * Evict warm files until the cache fits its budget again.
     *
     * Candidates are ordered least-recently-accessed first (never-accessed
     * files are the best candidates), and files touched within the idle
     * window are skipped so active viewers keep their copies.
     *
     * @param  int  $limit  maximum evictions in one pass
     * @return array{evicted:int,bytes:int,usage:int,over:int}
     */
    public function evictToBudget(int $limit = 20): array
    {
        $over = $this->overBudgetBytes();
        $idle = max(0, (int) config('media_relay.cache.evict_min_idle', 3600));
        $evicted = 0;
        $bytes = 0;

        while ($over > 0 && $evicted < $limit) {
            $candidate = RelayFile::where('cache_state', RelayFile::WARM)
                ->where(function ($q) use ($idle) {
                    $q->whereNull('last_access_at')
                        ->orWhere('last_access_at', '<', now()->subSeconds($idle));
                })
                ->orderBy('last_access_at', 'asc')
                ->first();

            if (!$candidate) {
                break;
            }

            $size = (int) $candidate->size;

            $this->evict($candidate, true);

            $evicted++;
            $bytes += $size;
            $over = max(0, $over - $size);
        }

        return [
            'evicted' => $evicted,
            'bytes' => $bytes,
            'usage' => $this->cacheUsage(),
            'over' => $this->overBudgetBytes(),
        ];
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

    /**
     * HEAD the public cache URL and compare its length with the stored size.
     * Throws on mismatch so the queue retry machinery takes over.
     */
    protected function verifyCachedCopy(RelayFile $file): void
    {
        $url = null;

        try {
            if (!empty($file->nine_share_token)) {
                $url = $this->ninedrive->previewUrl($file->nine_share_token);
            } elseif (!empty($file->nine_provider_file_id)) {
                $url = $this->ninedrive->driveUrl($file->nine_provider_file_id);
            }
        } catch (Throwable $e) {
            return; // nothing verifiable; serving will fall back anyway
        }

        if (empty($url)) {
            return;
        }

        try {
            $response = Http::timeout(30)->head($url);
        } catch (Throwable $e) {
            throw new RuntimeException('Cache copy verification failed: ' . $e->getMessage());
        }

        if ($response->status() >= 400) {
            throw new RuntimeException('Cache copy verification failed: HTTP ' . $response->status());
        }

        $length = (int) $response->header('Content-Length');

        if ($length > 0 && (int) $file->size > 0 && $length !== (int) $file->size) {
            throw new RuntimeException(sprintf(
                'Cache copy verification failed: upstream length %d, expected %d.',
                $length,
                (int) $file->size
            ));
        }
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
