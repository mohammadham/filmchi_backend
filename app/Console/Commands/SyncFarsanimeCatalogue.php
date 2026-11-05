<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * One catalogue pass, started by the n8n orchestrator.
 *
 * The pass is deliberately a detached CLI process instead of a queue job: a
 * full sweep touches ~180 series pages and many range requests, so it must not
 * sit in front of the download worker and delay the file queue. It also never
 * writes media bytes, only database rows, so it is cheap next to a transfer.
 *
 * Progress is published through the cache so /api/automation/status can tell
 * the orchestrator whether another pass is running and when one is due.
 */
class SyncFarsanimeCatalogue extends Command
{
    public const RUNNING_KEY = 'automation:catalogue:running';
    public const STARTED_KEY = 'automation:catalogue:started_at';
    public const HEARTBEAT_KEY = 'automation:catalogue:heartbeat';
    public const LAST_PASS_KEY = 'automation:catalogue:last_pass_at';
    public const LAST_EXIT_KEY = 'automation:catalogue:last_exit';
    public const LOCK_KEY = 'automation:catalogue:lock';

    protected $signature = 'farsanime:sync
        {--sleep=250 : Milliseconds between series pages}';

    protected $description = 'One catalogue pass: pick up new series, seasons, episodes and qualities';

    public function handle()
    {
        $lock = Cache::lock(self::LOCK_KEY, 7200);

        if (!$lock->get()) {
            $this->warn('Another catalogue pass is already running.');

            return 0;
        }

        Cache::put(self::RUNNING_KEY, true, 7200);
        Cache::put(self::STARTED_KEY, now()->toIso8601String(), 7200);
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());

        $exit = 1;

        try {
            $exit = (int) $this->call('farsanime:import', [
                '--sleep' => (int) $this->option('sleep'),
            ]);
        } catch (\Throwable $e) {
            $this->error('Catalogue pass failed: ' . $e->getMessage());
        } finally {
            Cache::put(self::LAST_PASS_KEY, now()->toIso8601String(), now()->addDays(30));
            Cache::put(self::LAST_EXIT_KEY, $exit, now()->addDays(30));
            Cache::forget(self::RUNNING_KEY);
            Cache::forget(self::STARTED_KEY);
            Cache::forget(self::HEARTBEAT_KEY);

            $lock->release();
        }

        return $exit;
    }
}
