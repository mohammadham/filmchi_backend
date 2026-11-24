<?php

namespace Tests\Feature;

use App\Services\Bot\BotUpdatePruner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * bot_updates only exists to refuse a retried update_id, and Telegram stops
 * retrying after 24 hours. The prune must therefore drop rows older than the
 * retention window and nothing newer, so dedup inside the retry window keeps
 * working.
 *
 * It runs from the scheduler (`relay:prune-bot-updates`), never from the
 * supervisor: an install on QUEUE_CONNECTION=sync has no supervisor at all.
 */
class BotUpdatePruneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bot_updates')->delete();

        config(['media_relay.bot.update_retention_days' => 2]);
    }

    protected function update(int $updateId, $receivedAt): void
    {
        DB::table('bot_updates')->insert([
            'update_id' => $updateId,
            'received_at' => $receivedAt,
        ]);
    }

    protected function remaining(): array
    {
        return DB::table('bot_updates')->orderBy('update_id')->pluck('update_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_a_zero_retention_cannot_prune_telegrams_retry_window(): void
    {
        config(['media_relay.bot.update_retention_days' => 0]);

        $this->update(9020, now()->subHours(2));  // still retryable
        $this->update(9021, now()->subDays(3));   // long past it

        (new BotUpdatePruner())->prune();

        $this->assertSame([9020], $this->remaining(), 'retention is clamped to one day, never to zero');
    }

    public function test_the_prune_stops_at_the_window_edge_and_keeps_every_newer_row(): void
    {
        // Retention is 2 days and the prune deletes received_at < cutoff, so a
        // row one second either side of the edge says which way it went. The
        // edge is not pinned to the exact second: the command takes its own
        // now(), a few milliseconds later than this one.
        $edge = now()->subDays(2);

        $this->update(9040, $edge->copy()->subSecond());   // past the edge: Telegram gave up
        $this->update(9041, $edge->copy()->addSecond());   // one second inside: still retryable
        $this->update(9042, now());                        // just arrived

        (new BotUpdatePruner())->prune();

        $this->assertSame(
            [9041, 9042],
            $this->remaining(),
            'the prune stops at the edge; every row inside the retry window survives'
        );
    }

    public function test_the_scheduler_runs_the_prune(): void
    {
        // The supervisor is not always there (sync has none), so the schedule is
        // what makes the prune happen at all.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('relay:prune-bot-updates')
            ->assertExitCode(0);
    }

    public function test_the_artisan_command_prunes_a_backlog_in_more_than_one_batch(): void
    {
        // 2.5 batches: the id-first loop has to go round three times, and the
        // last, short pass is what ends it.
        $old = now()->subDays(5);
        $rows = [];

        for ($i = 0; $i < BotUpdatePruner::BATCH * 2 + intdiv(BotUpdatePruner::BATCH, 2); $i++) {
            $rows[] = ['update_id' => 100000 + $i, 'received_at' => $old];
        }

        DB::table('bot_updates')->insert($rows);
        $this->update(9099, now());

        $this->artisan('relay:prune-bot-updates')
            ->expectsOutputToContain('pruned ' . count($rows))
            ->assertExitCode(0);

        $this->assertSame([9099], $this->remaining(), 'every old row is gone and the fresh one survives');
    }

    public function test_a_failing_prune_is_logged_and_fails_the_command(): void
    {
        $this->app->instance(BotUpdatePruner::class, new class extends BotUpdatePruner {
            public function prune(?Carbon $cutoff = null): int
            {
                throw new RuntimeException('db is gone');
            }
        });

        Log::spy();

        $this->artisan('relay:prune-bot-updates')
            ->expectsOutputToContain('db is gone')
            ->assertExitCode(1);

        // Nobody reads a scheduler's output: the reason has to reach the log.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'bot_updates prune failed')
            ->once();
    }
}
