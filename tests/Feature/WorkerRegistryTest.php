<?php

namespace Tests\Feature;

use App\RelayWorker;
use App\Services\Relay\WorkerRegistry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkerRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('relay_workers')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('relay_workers')->delete();

        parent::tearDown();
    }

    protected function worker(array $attributes): RelayWorker
    {
        return RelayWorker::create(array_merge([
            'name' => 'worker',
            'base_url' => 'https://worker.example.workers.dev',
            'enabled' => true,
            'priority' => 100,
            'last_status' => RelayWorker::STATUS_OK,
        ], $attributes));
    }

    public function test_pool_skips_disabled_cooling_and_failed_workers(): void
    {
        $fast = $this->worker(['name' => 'fast', 'last_speed_bps' => 5000000, 'last_latency_ms' => 100]);
        $slow = $this->worker(['name' => 'slow', 'last_speed_bps' => 1000000, 'last_latency_ms' => 50]);
        $unknown = $this->worker(['name' => 'unknown', 'last_status' => RelayWorker::STATUS_UNKNOWN, 'last_latency_ms' => 500]);
        $this->worker(['name' => 'disabled', 'enabled' => false, 'last_speed_bps' => 9000000]);
        $this->worker(['name' => 'cooling', 'cooldown_until' => now()->addMinutes(5)]);
        $this->worker(['name' => 'failed', 'last_status' => RelayWorker::STATUS_FAILED, 'last_speed_bps' => 9000000]);
        $this->worker(['name' => 'auto-disabled', 'auto_disabled_at' => now(), 'last_status' => RelayWorker::STATUS_FAILED]);

        $pool = app(WorkerRegistry::class)->selectPool('https://di.farsanime.ir/test.mp4');

        // Only healthy/unknown enabled Workers may receive live traffic.
        // Failed and auto-disabled Workers are excluded until a probe passes.
        $this->assertSame([$fast->id, $slow->id, $unknown->id], $pool->pluck('id')->all());
    }

    public function test_failed_worker_stays_out_until_a_probe_succeeds(): void
    {
        $failed = $this->worker([
            'name' => 'failed',
            'last_status' => RelayWorker::STATUS_FAILED,
            'failure_count' => 1,
            'cooldown_until' => now()->subMinute(),
        ]);

        $registry = app(WorkerRegistry::class);

        $this->assertTrue($registry->selectPool('https://di.farsanime.ir/test.mp4')->isEmpty());

        $registry->markProbeSuccess($failed, 25, true);
        $failed->refresh();

        $this->assertSame(RelayWorker::STATUS_OK, $failed->last_status);
        $this->assertNull($failed->auto_disabled_at);
        $this->assertSame([$failed->id], $registry->selectPool('https://di.farsanime.ir/test.mp4')->pluck('id')->all());
    }

    public function test_auto_disable_after_repeated_transfer_failures(): void
    {
        config([
            'media_relay.workers.auto_disable' => true,
            'media_relay.workers.failure_threshold' => 3,
            'media_relay.workers.failure_window_seconds' => 600,
            'media_relay.workers.backoff_base_seconds' => 60,
            'media_relay.workers.backoff_max_seconds' => 3600,
        ]);

        $worker = $this->worker(['name' => 'flaky']);
        $registry = app(WorkerRegistry::class);

        $registry->markFailure($worker, 'first');
        $registry->markFailure($worker, 'second');
        $worker->refresh();

        $this->assertSame(2, $worker->failure_count);
        $this->assertNull($worker->auto_disabled_at);
        $this->assertNotNull($worker->cooldown_until);
        $this->assertTrue($registry->selectPool('https://di.farsanime.ir/test.mp4')->isEmpty());

        $registry->markFailure($worker, 'third');
        $worker->refresh();

        $this->assertSame(3, $worker->failure_count);
        $this->assertNotNull($worker->auto_disabled_at);
        $this->assertNull($worker->cooldown_until);
        $this->assertStringContainsString('auto-disabled', (string) $worker->disabled_reason);
        $this->assertTrue($registry->selectPool('https://di.farsanime.ir/test.mp4')->isEmpty());
    }

    public function test_automatic_probe_needs_the_configured_successes(): void
    {
        config(['media_relay.workers.probe_success_threshold' => 2]);

        $worker = $this->worker([
            'name' => 'recovering',
            'last_status' => RelayWorker::STATUS_FAILED,
            'failure_count' => 3,
            'auto_disabled_at' => now(),
        ]);
        $registry = app(WorkerRegistry::class);

        $registry->markProbeSuccess($worker, 20, false);
        $worker->refresh();

        $this->assertNotNull($worker->auto_disabled_at);
        $this->assertSame(RelayWorker::STATUS_FAILED, $worker->last_status);
        $this->assertSame(1, $worker->probe_success_count);
        $this->assertTrue($registry->selectPool('https://di.farsanime.ir/test.mp4')->isEmpty());

        $registry->markProbeSuccess($worker, 20, false);
        $worker->refresh();

        $this->assertNull($worker->auto_disabled_at);
        $this->assertSame(RelayWorker::STATUS_OK, $worker->last_status);
        $this->assertSame([$worker->id], $registry->selectPool('https://di.farsanime.ir/test.mp4')->pluck('id')->all());
    }

    public function test_late_automatic_probe_does_not_knock_a_healthy_worker_out(): void
    {
        $worker = $this->worker(['name' => 'already-recovered']);
        $registry = app(WorkerRegistry::class);

        // A queued probe from before the manual recovery finishes afterwards.
        $registry->markProbeSuccess($worker, 20, false);
        $worker->refresh();

        $this->assertSame(RelayWorker::STATUS_OK, $worker->last_status);
        $this->assertNull($worker->auto_disabled_at);
        $this->assertSame([$worker->id], $registry->selectPool('https://di.farsanime.ir/test.mp4')->pluck('id')->all());
    }

    public function test_manual_probe_recovers_an_auto_disabled_worker_immediately(): void
    {
        $worker = $this->worker([
            'name' => 'manual-recovery',
            'last_status' => RelayWorker::STATUS_FAILED,
            'failure_count' => 5,
            'auto_disabled_at' => now(),
        ]);
        $registry = app(WorkerRegistry::class);

        $registry->markProbeSuccess($worker, 15, true);
        $worker->refresh();

        $this->assertNull($worker->auto_disabled_at);
        $this->assertSame(0, $worker->failure_count);
        $this->assertSame(RelayWorker::STATUS_OK, $worker->last_status);
        $this->assertSame([$worker->id], $registry->selectPool('https://di.farsanime.ir/test.mp4')->pluck('id')->all());
    }

    public function test_pool_prefers_least_recently_used_before_speed(): void
    {
        $fast = $this->worker([
            'name' => 'fast',
            'last_speed_bps' => 9000000,
            'last_used_at' => now(),
        ]);
        $slow = $this->worker([
            'name' => 'slow',
            'last_speed_bps' => 1000000,
            'last_used_at' => now()->subMinute(),
        ]);

        $pool = app(WorkerRegistry::class)->selectPool('https://di.farsanime.ir/test.mp4');

        $this->assertSame([$slow->id, $fast->id], $pool->pluck('id')->all());
    }

    public function test_route_for_builds_a_ranged_worker_url(): void
    {
        $worker = $this->worker(['name' => 'proxy', 'api_key' => 'secret-key']);
        $route = app(WorkerRegistry::class)->routeFor($worker, 'https://di.farsanime.ir/a.mp4', '0-99');

        $this->assertTrue($route['proxied']);
        $this->assertStringContainsString('url=', $route['url']);
        $this->assertStringContainsString('range=bytes%3D0-99', $route['url']);
        $this->assertSame(['x-proxy-key' => 'secret-key'], $route['headers']);
    }

    public function test_success_updates_the_throughput_ema(): void
    {
        $worker = $this->worker(['name' => 'ema', 'last_speed_bps' => 1000000]);

        app(WorkerRegistry::class)->reportSuccess($worker->id, 4000000, 2000);
        $worker->refresh();

        $this->assertSame(1300000, $worker->last_speed_bps);
        $this->assertSame(1, $worker->success_count);
        $this->assertSame(RelayWorker::STATUS_OK, $worker->last_status);
    }
}
