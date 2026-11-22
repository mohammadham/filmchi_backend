<?php

namespace Tests\Feature;

use App\Console\Commands\RelayQueueSupervisor;
use App\Jobs\ImportBotFileJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RelayQueueSupervisorHarness;
use Tests\TestCase;

/**
 * The bot lane needs a supervised worker of its own: ImportBotFileJob declares
 * the "bot" queue, and a role the supervisor never reconciles means nothing
 * ever consumes it.
 */
class RelayQueueSupervisorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'media_relay.bot.queue' => 'bot',
            'media_relay.bot.concurrency' => 1,
        ]);
    }

    public function test_the_bot_role_consumes_the_queue_the_import_job_uses(): void
    {
        $supervisor = new RelayQueueSupervisorHarness();

        $this->assertSame('bot', $supervisor->queueFor('bot'));
        $this->assertSame(
            (new ImportBotFileJob(1))->queue,
            $supervisor->queueFor('bot'),
            'the worker and the job must name the same queue'
        );
        $this->assertNotSame('uploads', $supervisor->queueFor('bot'), 'bot imports keep their own lane');
    }

    public function test_the_bot_role_starts_the_configured_number_of_workers(): void
    {
        config(['media_relay.bot.concurrency' => 2]);

        $supervisor = new RelayQueueSupervisorHarness();
        $supervisor->reconcile('bot');

        $this->assertSame(['bot', 'bot'], $supervisor->spawnedRoles);
    }

    public function test_the_bot_concurrency_is_clamped(): void
    {
        $supervisor = new RelayQueueSupervisorHarness();

        config(['media_relay.bot.concurrency' => 0]);
        $this->assertSame(1, $supervisor->desired('bot'));

        config(['media_relay.bot.concurrency' => 9]);
        $this->assertSame(2, $supervisor->desired('bot'), 'a bot import is a copyMessage plus an adopt, not a transfer swarm');
    }

    public function test_the_heartbeat_reports_the_bot_pool(): void
    {
        config(['media_relay.bot.concurrency' => 2]);

        $supervisor = new RelayQueueSupervisorHarness();
        $supervisor->reconcile('bot');
        $supervisor->publishHeartbeat();

        $status = Cache::get(RelayQueueSupervisor::STATUS_KEY);

        $this->assertSame(2, $status['bot_workers']);
        $this->assertSame(2, $status['bot_desired']);
        $this->assertSame(2, $status['workers'], 'only the bot pool is running in this test');
        $this->assertGreaterThanOrEqual($status['bot_desired'], $status['desired']);
    }

    public function test_a_stale_submission_is_requeued_and_a_fresh_one_is_not(): void
    {
        Queue::fake();

        $stale = $this->submission('sup-stale', 'queued', now()->subHour());
        $this->submission('sup-fresh', 'retrying', now());
        $this->submission('sup-done', 'done', now()->subDay());

        (new RelayQueueSupervisorHarness())->requeueBotImports();

        Queue::assertPushed(ImportBotFileJob::class, 1);
        Queue::assertPushed(ImportBotFileJob::class, fn (ImportBotFileJob $job) => $job->submissionId === $stale);
    }

    public function test_the_requeue_window_limits_one_pass_per_interval(): void
    {
        Queue::fake();

        $this->submission('sup-stale', 'queued', now()->subHour());

        $supervisor = new RelayQueueSupervisorHarness();
        $supervisor->requeueBotImports();
        $supervisor->requeueBotImports();

        Queue::assertPushed(ImportBotFileJob::class, 1);
    }

    public function test_every_lane_in_the_role_list_is_supervised_on_its_own_queue(): void
    {
        $this->assertSame(
            ['download', 'upload', 'probe', 'bot'],
            RelayQueueSupervisor::ROLES,
            'the bot lane must stay in the reconciled loop'
        );

        $supervisor = new RelayQueueSupervisorHarness();
        $queues = [];

        foreach (RelayQueueSupervisor::ROLES as $role) {
            $queue = $supervisor->queueFor($role);

            $this->assertNotSame('', $queue, $role . ' needs a queue');
            $queues[] = $queue;

            if ($role !== 'download') {
                // The download lane may be pinned to 0 by the disk budget.
                $this->assertGreaterThanOrEqual(1, $supervisor->desired($role), $role . ' needs a worker');
            }
        }

        $this->assertCount(
            count(RelayQueueSupervisor::ROLES),
            array_unique($queues),
            'each lane keeps its own queue so no import sits behind a transfer'
        );
    }

    public function test_the_bot_lane_comes_up_without_a_manual_worker(): void
    {
        Queue::fake();

        $supervisor = new RelayQueueSupervisorHarness();
        $supervisor->reconcile('bot');

        $this->assertSame(['bot'], $supervisor->spawnedRoles);
        $this->assertSame((new ImportBotFileJob(1))->queue, $supervisor->queueFor('bot'));
    }

    /** A row in the state a dead worker leaves behind. */
    protected function submission(string $uniqueId, string $status, $updatedAt): int
    {
        DB::table('bot_submissions')->where('file_unique_id', $uniqueId)->delete();

        return (int) DB::table('bot_submissions')->insertGetId([
            'telegram_user_id' => 111,
            'chat_id' => 999000,
            'message_id' => random_int(1000000, 9999999),
            'file_unique_id' => $uniqueId,
            'name' => 'stale.mkv',
            'size' => 1,
            'mime' => 'video/x-matroska',
            'status' => $status,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }
}
