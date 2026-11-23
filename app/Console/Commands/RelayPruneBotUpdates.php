<?php

namespace App\Console\Commands;

use App\Services\Bot\BotUpdatePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prune `bot_updates` from cron.
 *
 * The supervisor prunes on its own tick, but an install that runs
 * `QUEUE_CONNECTION=sync` has no supervisor at all, so its dedup table would grow
 * forever. This command runs the same BotUpdatePruner, so both paths delete
 * exactly the rows Telegram can no longer retry.
 *
 *   * * * * * cd /path/to/flixy && php artisan relay:prune-bot-updates
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
            Log::warning('bot_updates prune failed', ['message' => $e->getMessage()]);
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
