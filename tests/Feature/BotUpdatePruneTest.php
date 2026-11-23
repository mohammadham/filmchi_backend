<?php

namespace Tests\Feature;

use App\Services\Bot\BotUpdatePruner;
use Illuminate\Support\Facades\DB;
use Tests\Support\RelayQueueSupervisorHarness;
use Tests\TestCase;

/**
 * bot_updates only exists to refuse a retried update_id, and Telegram stops
 * retrying after 24 hours. The prune must therefore drop rows older than the
 * retention window and nothing newer, so dedup inside the retry window keeps
 * working.
 */
class BotUpdatePruneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bot_updates')->delete();

        config([
            'media_relay.bot.prune_seconds' => 1, // every pass is allowed to run
            'media_relay.bot.update_retention_days' => 2,
        ]);
    }

    protected function update(int $updateId, $receivedAt): void
    {
        DB::table('bot_updates')->insert([
            'update_id' => $updateId,
            'kind' => 'message',
            'received_at' => $receivedAt,
        ]);
    }

    protected function remaining(): array
    {
        return DB::table('bot_updates')->orderBy('update_id')->pluck('update_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_only_updates_older_than_the_retention_window_are_pruned(): void
    {
        $this->update(9001, now());                       // today
        $this->update(9002, now()->subDay());             // inside the window
        $this->update(9003, now()->subDays(3));           // Telegram has given up

        (new RelayQueueSupervisorHarness())->pruneUpdates();

        $this->assertSame([9001, 9002], $this->remaining(), 'the prune must stop at the retention edge');
    }

    public function test_the_prune_waits_for_its_interval(): void
    {
        config(['media_relay.bot.prune_seconds' => 3600]);

        $this->update(9010, now()->subDays(5));

        $supervisor = new RelayQueueSupervisorHarness();
        $supervisor->pruneUpdates();

        $this->assertSame([], $this->remaining(), 'the first pass runs at boot');

        $this->update(9011, now()->subDays(5));
        $supervisor->pruneUpdates();

        $this->assertSame([9011], $this->remaining(), 'a second pass inside the interval must not run');
    }

    public function test_a_zero_retention_cannot_prune_telegrams_retry_window(): void
    {
        config(['media_relay.bot.update_retention_days' => 0]);

        $this->update(9020, now()->subHours(2));  // still retryable
        $this->update(9021, now()->subDays(3));   // long past it

        (new RelayQueueSupervisorHarness())->pruneUpdates();

        $this->assertSame([9020], $this->remaining(), 'retention is clamped to one day, never to zero');
    }

    public function test_the_artisan_command_prunes_an_install_without_a_supervisor(): void
    {
        // more than one batch, so the id-first loop runs twice
        $old = now()->subDays(5);
        $rows = [];

        for ($i = 0; $i < BotUpdatePruner::BATCH + 1; $i++) {
            $rows[] = ['update_id' => 10000 + $i, 'kind' => 'message', 'received_at' => $old];
        }

        DB::table('bot_updates')->insert($rows);
        $this->update(9099, now());

        $this->artisan('relay:prune-bot-updates')
            ->expectsOutputToContain('pruned 1001')
            ->assertExitCode(0);

        $this->assertSame([9099], $this->remaining(), 'the fresh row and only that one survives');
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

        (new RelayQueueSupervisorHarness())->pruneUpdates();

        $this->assertSame(
            [9041, 9042],
            $this->remaining(),
            'the prune stops at the edge; every row inside the retry window survives'
        );
    }
}
