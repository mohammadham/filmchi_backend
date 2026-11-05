<?php

namespace Tests\Feature;

use App\Console\Commands\SyncFarsanimeCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

class AutomationApiTest extends TestCase
{
    /**
     * The catalogue tables come from the production SQL dump, not from the
     * migrations, so the migrated test schema has no episode_sources. The
     * payload is verified against the real database instead.
     */
    protected function requireCatalogueTables(): void
    {
        try {
            if (Schema::hasTable('episode_sources')) {
                return;
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('No database connection: ' . $e->getMessage());
        }

        $this->markTestSkipped('The migrated test schema does not include the catalogue tables.');
    }

    public function test_status_requires_the_shared_token()
    {
        $this->requireCatalogueTables();

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
        $this->requireCatalogueTables();

        config(['media_relay.automation.token' => 'secret-token']);

        $this->getJson('/api/automation/status', ['Authorization' => 'Bearer secret-token'])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_catalogue_endpoint_requires_the_shared_token()
    {
        config(['media_relay.automation.token' => 'secret-token']);

        $this->postJson('/api/automation/catalogue')->assertStatus(403);
    }

    public function test_catalogue_endpoint_reports_a_pass_that_is_already_running()
    {
        config(['media_relay.automation.token' => 'secret-token']);

        Cache::put(SyncFarsanimeCatalogue::RUNNING_KEY, true, 600);
        Cache::forever(SyncFarsanimeCatalogue::HEARTBEAT_KEY, now()->toIso8601String());

        $this->postJson('/api/automation/catalogue', [], ['X-Fliksy-Token' => 'secret-token'])
            ->assertStatus(202)
            ->assertJsonPath('started', false)
            ->assertJsonPath('reason', 'already_running')
            ->assertJsonPath('catalogue.running', true);
    }

    public function test_catalogue_endpoint_waits_for_the_interval()
    {
        config([
            'media_relay.automation.token' => 'secret-token',
            'media_relay.automation.catalogue.interval_hours' => 6,
        ]);

        Cache::forever(SyncFarsanimeCatalogue::LAST_PASS_KEY, now()->toIso8601String());

        $response = $this->postJson('/api/automation/catalogue', [], ['X-Fliksy-Token' => 'secret-token'])
            ->assertStatus(202)
            ->assertJsonPath('started', false)
            ->assertJsonPath('reason', 'not_due')
            ->assertJsonPath('catalogue.due', false);

        $this->assertNotEmpty($response->json('catalogue.next_run_at'));
    }

    public function test_a_dead_pass_is_not_reported_as_running()
    {
        $this->requireCatalogueTables();

        config(['media_relay.automation.token' => 'secret-token']);

        // A deploy kills the detached child without clearing its flag.
        Cache::put(SyncFarsanimeCatalogue::RUNNING_KEY, true, 7200);
        Cache::forever(
            SyncFarsanimeCatalogue::HEARTBEAT_KEY,
            now()->subMinutes(40)->toIso8601String()
        );
        Cache::forever(SyncFarsanimeCatalogue::LAST_PASS_KEY, now()->subHours(9)->toIso8601String());

        $this->getJson('/api/automation/status', ['X-Fliksy-Token' => 'secret-token'])
            ->assertStatus(200)
            ->assertJsonPath('catalogue.running', false)
            ->assertJsonPath('catalogue.due', true);
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
