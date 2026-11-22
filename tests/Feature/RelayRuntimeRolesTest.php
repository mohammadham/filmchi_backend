<?php

namespace Tests\Feature;

use App\Console\Commands\RelayQueueSupervisor;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The workers card used to show one total. The heartbeat already carries a
 * live/target pair per lane, so the card shows each lane on its own: a stopped
 * bot pool has to be visible while download and upload keep running.
 */
class RelayRuntimeRolesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** The card's runtime call, without the login middleware in front of it. */
    protected function runtime(): array
    {
        $controller = new class extends SettingController {
            public function runtime(): array
            {
                return $this->relayRuntimeStatus();
            }
        };

        return $controller->runtime();
    }

    protected function beat(array $overrides = []): void
    {
        Cache::put(RelayQueueSupervisor::STATUS_KEY, $overrides + [
            'updated_at' => now()->toIso8601String(),
            'workers' => 4,
            'desired' => 5,
            'download_workers' => 2,
            'download_desired' => 2,
            'upload_workers' => 1,
            'upload_desired' => 2,
            'probe_workers' => 1,
            'probe_desired' => 1,
            'bot_workers' => 0,
            'bot_desired' => 1,
        ], now()->addMinutes(10));
    }

    public function test_every_lane_of_the_heartbeat_reaches_the_card(): void
    {
        $this->beat();

        $roles = $this->runtime()['roles'];

        $this->assertSame(['live' => 2, 'desired' => 2], $roles['download']);
        $this->assertSame(['live' => 1, 'desired' => 2], $roles['upload']);
        $this->assertSame(['live' => 1, 'desired' => 1], $roles['probe']);
        $this->assertSame(
            ['live' => 0, 'desired' => 1],
            $roles['bot'],
            'a bot lane that is configured but not running must not read as alive'
        );
    }

    public function test_every_role_appears_even_when_the_supervisor_is_down(): void
    {
        $payload = $this->runtime();

        $this->assertTrue($payload['supervisor_down']);

        foreach (RelayQueueSupervisor::ROLES as $role) {
            $this->assertArrayHasKey($role, $payload['roles']);
            $this->assertSame(0, $payload['roles'][$role]['live'], $role . ' live');
            $this->assertSame(0, $payload['roles'][$role]['desired'], $role . ' desired');
        }
    }

    public function test_a_stale_heartbeat_zeroes_the_lanes(): void
    {
        $this->beat(['updated_at' => now()->subMinutes(5)->toIso8601String()]);

        $payload = $this->runtime();

        $this->assertTrue($payload['supervisor_down'], 'an old beat is a dead supervisor');
        $this->assertSame(0, $payload['roles']['download']['live']);
        $this->assertSame(0, $payload['roles']['bot']['desired']);
    }
}
