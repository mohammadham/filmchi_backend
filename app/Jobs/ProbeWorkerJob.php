<?php

namespace App\Jobs;

use App\RelayWorker;
use App\Services\Relay\WorkerRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-test one Worker in the background.
 *
 * A health probe can take several seconds (a sleeping Fly.io machine can take
 * longer), so it must never run inline in a download or in the supervisor
 * loop. The probe job updates the same last_status/cooldown fields the
 * selector reads on the next chunk.
 */
class ProbeWorkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A probe is a short request; do not hold a download slot for long. */
    public $timeout = 60;

    public $tries = 1;

    /** @var int */
    public $workerId;

    public function __construct(int $workerId)
    {
        $this->workerId = $workerId;
        $this->onQueue((string) config('media_relay.workers.probe_queue', 'uploads'));
    }

    public function handle(WorkerRegistry $registry): void
    {
        $worker = RelayWorker::find($this->workerId);

        if (!$worker || !$worker->enabled) {
            return;
        }

        $result = $registry->test($worker);

        Log::info('relay worker probe', [
            'worker_id' => $this->workerId,
            'worker' => $worker->name,
            'ok' => (bool) $result['ok'],
            'latency_ms' => (int) $result['latency_ms'],
            'message' => $result['message'],
        ]);
    }
}
