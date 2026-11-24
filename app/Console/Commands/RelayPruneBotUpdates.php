<?php

namespace App\Console\Commands;

use App\Services\Bot\BotUpdatePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prune `bot_updates`.
 *
 * The prune used to ride on the supervisor's tick, but an install on
 * `QUEUE_CONNECTION=sync` has no supervisor at all, so its dedup table grew
 * forever. This is the only prune path now and it runs from the scheduler (see
 * App\Console\Kernel::schedule), so it needs neither a supervisor nor a cron
 * line of its own per install.
 */
class RelayPruneBotUpdates extends Command
{
    /** @var string */
    protected $signature = 'relay:prune-bot-updates';

    /** @var string */
    protected $description = 'Delete bot_updates rows past the retention window (for installs without a supervisor)';

    public function handle(BotUpdatePruner $pruner): int
    {
        $cutoff = $pruner->cutoff();

        try {
            $deleted = $pruner->prune($cutoff);
        } catch (Throwable $e) {
            // A cron job has nobody watching its output: leave the reason behind
            // so a failing prune is visible in the log too.
            Log::warning('bot_updates prune failed', [
                'message' => $e->getMessage(),
                'cutoff' => $cutoff->toIso8601String(),
            ]);
            $this->error('prune failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'pruned %d bot update(s) received before %s',
            $deleted,
            $cutoff->toIso8601String()
        ));

        return self::SUCCESS;
    }
}
