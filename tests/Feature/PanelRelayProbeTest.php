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

    public function test_the_requeue_routes_are_served_by_the_panel_controller(): void
    {
        $this->post(route('requeueRelaySources'), ['sources' => []])
            ->assertSessionHasErrors('sources');
    }

    public function test_the_probe_all_form_covers_rows_waiting_for_a_retry(): void
    {
        $rows = collect([
            (object) ['source_type' => 'episode', 'id' => 7, 'ingest_status' => 'failed'],
            (object) ['source_type' => 'content', 'id' => 9, 'ingest_status' => 'retrying'],
        ]);

        $html = view('partials.probeAllSourcesForm', ['failedSources' => $rows])->render();

        $this->assertSame(2, substr_count($html, 'name="sources[]"'));
        $this->assertStringContainsString('value="episode:7"', $html);
        $this->assertStringContainsString('value="content:9"', $html, 'a retrying row is probed like any other row');
        $this->assertStringNotContainsString('disabled', $html, 'no field of the all-rows probe may be disabled');
    }

    public function test_the_row_shows_the_quarantine_reason_of_a_dead_link(): void
    {
        $html = view('partials.sourceFailureDetails', [
            'attempts' => [],
            'source' => (object) ['quarantine_reason' => 'منبع قرنطینه شد: در 2 تلاش روی 2 ورکر صفر بایت دریافت شد.'],
        ])->render();

        $this->assertStringContainsString('<tr>', $html);
        $this->assertStringContainsString('دلیل قرنطینه', $html);
        $this->assertStringContainsString('در 2 تلاش روی 2 ورکر', $html);
    }

    public function test_a_live_row_without_history_adds_no_second_row(): void
    {
        $html = view('partials.sourceFailureDetails', ['attempts' => [], 'source' => (object) []])->render();

        $this->assertSame('', trim($html), 'a live row without history gets no empty <tr>');
    }

    public function test_a_live_row_with_history_shows_the_attempts_but_no_reason(): void
    {
        $attempt = (object) [
            'attempt_no' => 2,
            'outcome' => 'failed',
            'bytes' => 0,
            'route' => null,
            'error' => 'timeout',
            'finished_at' => null,
        ];

        $html = view('partials.sourceFailureDetails', ['attempts' => [$attempt], 'source' => (object) []])->render();

        $this->assertStringContainsString('<tr>', $html);
        $this->assertStringContainsString('#2', $html);
        $this->assertStringNotContainsString('دلیل قرنطینه', $html);
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
