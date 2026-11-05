<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class AutomationApiTest extends TestCase
{
    /**
     * The status payload needs episode_sources; the local test box has no
     * database reachable (CI and the server do), so skip instead of failing.
     */
    protected function requireDatabase(): void
    {
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('No database connection in this environment: ' . $e->getMessage());
        }
    }

    public function test_status_requires_the_shared_token()
    {
        $this->requireDatabase();

        config(['media_relay.automation.token' => 'secret-token']);

        $this->getJson('/api/automation/status')->assertStatus(403);

        $this->getJson('/api/automation/status', ['X-Fliksy-Token' => 'wrong-token'])
            ->assertStatus(403);

        $this->getJson('/api/automation/status', ['X-Fliksy-Token' => 'secret-token'])
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'pending',
                'queued',
                'active',
                'completed',
                'failed',
                'current',
                'disk_free_gb',
                'min_free_gb',
            ]);
    }

    public function test_status_accepts_a_bearer_token()
    {
        $this->requireDatabase();

        config(['media_relay.automation.token' => 'secret-token']);

        $this->getJson('/api/automation/status', ['Authorization' => 'Bearer secret-token'])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_endpoints_are_disabled_without_a_configured_token()
    {
        config(['media_relay.automation.token' => '']);

        $this->getJson('/api/automation/status', ['X-Fliksy-Token' => 'anything'])
            ->assertStatus(503);

        $this->postJson('/api/automation/queue', ['limit' => 1], ['X-Fliksy-Token' => 'anything'])
            ->assertStatus(503);
    }
}
