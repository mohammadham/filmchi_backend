<?php

namespace App\Services\Bot;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Drop the dedup rows Telegram can no longer retry.
 *
 * `bot_updates` exists to refuse a repeated `update_id`, and Telegram stops
 * retrying a delivery after 24 hours, so a row older than the retention window
 * cannot dedupe anything ever again. The retention is clamped to at least one
 * day for exactly that reason: pruning inside the retry window would let a
 * retried update start a second flow.
 *
 * Two callers share this one implementation - the supervisor tick and
 * `relay:prune-bot-updates`, which is what a cron entry runs on installs that do
 * not keep the supervisor up (every `QUEUE_CONNECTION=sync` install) - so the
 * cutoff and the batching cannot drift apart between them.
 */
class BotUpdatePruner
{
    /** Rows per delete: `DELETE ... LIMIT` is not portable, so ids come first. */
    public const BATCH = 1000;

    /** The oldest row that may still dedupe something. */
    public function cutoff(): Carbon
    {
        return now()->subDays(max(1, (int) config('media_relay.bot.update_retention_days', 2)));
    }

    /**
     * Delete every row older than the cutoff, in short batches so a
     * long-abandoned install does not lock the table in one huge delete.
     *
     * @return int rows deleted
     */
    public function prune(?Carbon $cutoff = null): int
    {
        $cutoff ??= $this->cutoff();
        $deleted = 0;

        do {
            $ids = DB::table('bot_updates')
                ->where('received_at', '<', $cutoff)
                ->orderBy('update_id')
                ->limit(self::BATCH)
                ->pluck('update_id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('bot_updates')->whereIn('update_id', $ids->all())->delete();
        } while ($ids->count() >= self::BATCH);

        return $deleted;
    }
}
