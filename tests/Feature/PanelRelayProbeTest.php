<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckLogin;
use App\Jobs\ProbeSourceLinkJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PanelRelayProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(CheckLogin::class);
        Queue::fake();
    }

    public function test_a_single_row_link_is_queued_for_probing(): void
    {
        $response = $this->post(route('probeRelaySource'), [
            'source_type' => 'episode',
            'source_id' => 42,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Queue::assertPushed(ProbeSourceLinkJob::class, function ($job) {
            return $job->sourceType === 'episode' && $job->sourceId === 42;
        });
    }

    public function test_the_batch_button_probes_each_selected_row_once(): void
    {
        $response = $this->post(route('probeRelaySources'), [
            'sources' => ['episode:1', 'content:2', 'episode:1'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Queue::assertPushed(ProbeSourceLinkJob::class, 2);
        Queue::assertPushed(ProbeSourceLinkJob::class, function ($job) {
            return $job->sourceType === 'content' && $job->sourceId === 2;
        });
    }

    public function test_a_malformed_row_is_rejected_before_anything_is_queued(): void
    {
        $response = $this->post(route('probeRelaySources'), [
            'sources' => ['episode:1', 'episode;2'],
        ]);

        $response->assertSessionHasErrors();

        Queue::assertNothingPushed();
    }

    public function test_the_row_shows_link_state_and_consecutive_failures(): void
    {
        $dead = (object) [
            'link_status' => 'dead',
            'link_fail_count' => 4,
            'link_checked_at' => now()->subMinutes(5),
        ];
        $unchecked = (object) ['link_status' => null, 'link_fail_count' => 0, 'link_checked_at' => null];

        $deadHtml = view('partials.sourceLinkHealth', ['source' => $dead])->render();
        $uncheckedHtml = view('partials.sourceLinkHealth', ['source' => $unchecked])->render();

        $this->assertStringContainsString('مرده', $deadHtml);
        $this->assertStringContainsString('خطای پیاپی: 4', $deadHtml);
        $this->assertStringContainsString('بررسی نشده', $uncheckedHtml, 'a row without a probe result says so instead of looking dead');
        $this->assertStringNotContainsString('خطای پیاپی', $uncheckedHtml);
    }
}
