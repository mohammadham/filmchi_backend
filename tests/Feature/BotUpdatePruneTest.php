<?php

namespace Tests\Feature;

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

    public function test_pruning_keeps_a_retried_delivery_inside_the_window_single(): void
    {
        $this->update(9030, now());

        // The same update_id arriving twice is the case this table exists for.
        $again = DB::table('bot_updates')->insertOrIgnore([
            'update_id' => 9030,
            'kind' => 'message',
            'received_at' => now(),
        ]);

        $this->assertSame(0, $again, 'a retried delivery must not record a second row');
        $this->assertSame([9030], $this->remaining());
    }
}
