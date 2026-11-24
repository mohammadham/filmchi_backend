<?php

namespace Tests\Feature;

use App\Console\Commands\RelayQueueSupervisor;
use App\Jobs\ImportBotFileJob;
use App\Services\Relay\IngestPartSweeper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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

    public function test_requeuing_with_no_worker_behind_the_queue_warns_once_an_hour(): void
    {
        Queue::fake();
        Log::spy();
        config(['queue.default' => 'sync']);

        $this->submission('sup-stale', 'queued', now()->subHour());

        $inline = fn (string $message): bool => str_contains($message, 'runs inline');

        (new RelayQueueSupervisorHarness())->requeueBotImports();
        Log::shouldHaveReceived('warning')->withArgs($inline)->once();

        // A second sweep in the same hour is the same statement: still one line.
        (new RelayQueueSupervisorHarness())->requeueBotImports();
        Log::shouldHaveReceived('warning')->withArgs($inline)->once();

        // A real queue has a worker behind it, so the sweep stays quiet.
        config(['queue.default' => 'redis']);
        (new RelayQueueSupervisorHarness())->requeueBotImports();
        Log::shouldHaveReceived('warning')->withArgs($inline)->once();
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

    public function test_a_failed_sweep_is_logged_once_an_hour(): void
    {
        Log::spy();

        // A sweep that keeps failing in silence is indistinguishable from a
        // disk that simply refills, so the reason has to reach the log.
        $this->app->instance(IngestPartSweeper::class, new class extends IngestPartSweeper {
            public function sweep(?int $staleHours = null, bool $dryRun = false): array
            {
                throw new RuntimeException('db is gone');
            }
        });

        $warning = fn (string $message): bool => $message === 'stale ingest part sweep failed';

        (new RelayQueueSupervisorHarness())->sweepParts();
        Log::shouldHaveReceived('warning')->withArgs($warning)->once();

        // The interval is cleared so the next tick retries, but the line stays
        // at one an hour: a repeating failure must not flood the log.
        (new RelayQueueSupervisorHarness())->sweepParts();
        Log::shouldHaveReceived('warning')->withArgs($warning)->once();
    }

    public function test_a_failed_requeue_is_logged_and_retried_on_the_next_tick(): void
    {
        // The requeue runs unattended inside the supervisor loop, so a swallowed
        // error only shows up as imports that quietly never resume.
        Log::spy();
        DB::shouldReceive('table')->andThrow(new RuntimeException('db is gone'));

        $supervisor = new RelayQueueSupervisorHarness();
        $supervisor->requeueBotImports();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'stale bot import requeue failed')
            ->once();

        $this->assertSame(0, $supervisor->botRequeueAt(), 'the next tick must retry instead of waiting an interval');

        // The reset makes the next tick run again; the line stays at one an hour.
        $supervisor->requeueBotImports();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'stale bot import requeue failed')
            ->once();
    }

    public function test_every_lane_in_the_role_list_is_supervised_on_its_own_queue(): void
    {
        $this->assertContains('bot', RelayQueueSupervisor::ROLES, 'the bot lane must stay in the reconciled loop');

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

    public function test_the_heartbeat_total_is_the_sum_of_the_lanes(): void
    {
        $supervisor = new RelayQueueSupervisorHarness();
        $supervisor->reconcile('bot');
        $supervisor->publishHeartbeat();

        $status = Cache::get(RelayQueueSupervisor::STATUS_KEY);

        $live = 0;
        $desired = 0;

        foreach (RelayQueueSupervisor::ROLES as $role) {
            $this->assertArrayHasKey($role . '_workers', $status);
            $this->assertArrayHasKey($role . '_desired', $status);

            $live += $status[$role . '_workers'];
            $desired += $status[$role . '_desired'];
        }

        $this->assertSame($live, $status['workers']);
        $this->assertSame($desired, $status['desired']);
        $this->assertGreaterThanOrEqual(1, $status['bot_desired'], 'the bot lane is always staffed');
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
