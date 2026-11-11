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

    public function test_pool_skips_disabled_cooling_and_parks_failed_last(): void
    {
        $fast = $this->worker(['name' => 'fast', 'last_speed_bps' => 5000000, 'last_latency_ms' => 100]);
        $slow = $this->worker(['name' => 'slow', 'last_speed_bps' => 1000000, 'last_latency_ms' => 50]);
        $unknown = $this->worker(['name' => 'unknown', 'last_status' => RelayWorker::STATUS_UNKNOWN, 'last_latency_ms' => 500]);
        $this->worker(['name' => 'disabled', 'enabled' => false, 'last_speed_bps' => 9000000]);
        $this->worker(['name' => 'cooling', 'cooldown_until' => now()->addMinutes(5)]);
        $failed = $this->worker(['name' => 'failed', 'last_status' => RelayWorker::STATUS_FAILED, 'last_speed_bps' => 9000000]);

        $pool = app(WorkerRegistry::class)->selectPool('https://di.farsanime.ir/test.mp4');

        // Disabled and still-cooling Workers are out; a failed one stays
        // eligible but only after every healthy Worker has been tried.
        $this->assertSame([$fast->id, $slow->id, $unknown->id, $failed->id], $pool->pluck('id')->all());
    }

    public function test_recovered_failed_worker_returns_to_the_pool_after_cooldown(): void
    {
        // The production outage: every Worker failed once, their cooldowns
        // expired, and ingest kept failing on the unreachable direct route.
        $recovered = $this->worker([
            'name' => 'recovered',
            'last_status' => RelayWorker::STATUS_FAILED,
            'failure_count' => 1,
            'cooldown_until' => now()->subMinute(),
        ]);

        $pool = app(WorkerRegistry::class)->selectPool('https://di.farsanime.ir/test.mp4');

        $this->assertSame([$recovered->id], $pool->pluck('id')->all());
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
