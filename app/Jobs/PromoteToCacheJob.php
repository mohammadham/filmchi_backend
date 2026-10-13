<?php

namespace App\Jobs;

use App\RelayFile;
use App\Services\Relay\CachePromoter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Moves one file from Telegram Drive into the 9drive cache.
 *
 * Long timeout because the bytes are copied between two remote services while
 * the operator's queue worker holds the job.
 */
class PromoteToCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $timeout = 3600;

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $relayFileId;

    public function __construct(int $relayFileId)
    {
        $this->relayFileId = $relayFileId;

        $connection = config('media_relay.queue.connection');

        if ($connection) {
            $this->onConnection($connection);
        }

        $this->onQueue((string) config('media_relay.queue.name', 'default'));
    }

    public function handle(CachePromoter $promoter): void
    {
        $file = RelayFile::find($this->relayFileId);

        if (!$file) {
            return;
        }

        $result = $promoter->promote($file);

        if (!$result['ok'] && empty($result['skipped'])) {
            // Let the queue retry with its usual backoff.
            throw new \RuntimeException($result['message']);
        }
    }
}
