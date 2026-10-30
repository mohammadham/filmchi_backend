<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_healthz_reports_ok()
    {
        $response = $this->get('/healthz');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonPath('checks.queue.ok', true);
    }

    public function test_full_probe_includes_upstreams()
    {
        $response = $this->get('/healthz?full=1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'checks' => ['telegram_drive', 'ninedrive'],
            ]);
    }
}
