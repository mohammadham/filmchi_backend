<?php

namespace Tests\Feature;

use App\IngestAttempt;
use App\Jobs\IngestUrlJob;
use App\Services\Relay\SourceRequeuer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Concerns\CreatesRelaySourceTables;
use Tests\Feature\Concerns\ReadyDestination;
use Tests\TestCase;

/**
 * The attempt counters live on the source row and a requeue wipes them; the
 * history table is what keeps the attempts readable afterwards.
 */
class IngestAttemptLogTest extends TestCase
{
    use CreatesRelaySourceTables, ReadyDestination;

    protected string $part = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSourceTables();
        DB::table('episode_sources')->delete();
        DB::table('content_sources')->delete();
        DB::table('ingest_attempt_log')->delete();
        Cache::flush();

        config([
            'media_relay.enabled' => true,
            'media_relay.telegram.base_url' => 'http://tgdrive.test',
            'media_relay.telegram.storage_chat' => '@store',
            'media_relay.workers.enabled' => true,
            'media_relay.workers.hosts' => 'cdn.test',
            'media_relay.ingest.history_limit' => 100,
            'media_relay.quarantine.zero_attempts' => 99,
            'media_relay.quarantine.zero_workers' => 99,
            'media_relay.ingest.retry_delay_seconds' => 300,
            'media_relay.ingest.connections' => 1,
            'media_relay.ingest.total_connections' => 1,
        ]);

        Queue::fake();
        $this->stubReadyDestination();

        HistoryProbeJob::$workers = [];
        HistorySuccessJob::$bytes = 2048;
    }

    protected function tearDown(): void
    {
        if ($this->part !== '' && is_file($this->part)) {
            @unlink($this->part);
        }

        DB::table('episode_sources')->delete();
        DB::table('content_sources')->delete();
        DB::table('ingest_attempt_log')->delete();
        $this->dropSourceTables();

        parent::tearDown();
    }

    protected function source(string $url = 'http://127.0.0.1:9/film.mkv')
    {
        $source = new \App\EpisodeSource();
        $source->forceFill([
            'episode_id' => 1,
            'source' => $url,
            'ingest_status' => 'queued',
            'ingest_progress' => 0,
            'ingest_attempts' => 0,
            'ingest_fail_count' => 0,
        ])->save();

        return $source;
    }

    protected function contentSource()
    {
        $source = new \App\ContentSource();
        $source->forceFill([
            'content_id' => 1,
            'title' => 'film',
            'source' => 'http://127.0.0.1:9/film.mkv',
            'ingest_status' => 'queued',
            'ingest_progress' => 0,
            'ingest_attempts' => 0,
            'ingest_fail_count' => 0,
        ])->save();

        return $source;
    }

    /** Run one attempt that fails before a byte, on the given Workers. */
    protected function runFailedAttempt($source, array $workers = [], string $type = 'episode'): void
    {
        HistoryProbeJob::$workers = $workers;

        $job = new HistoryProbeJob($type, (int) $source->id, (string) $source->source, 'test.mkv');
        $job->job = new class {
            public array $released = [];

            public function release($delay = 0): void
            {
                $this->released[] = $delay;
            }

            public function delete(): void
            {
            }

            public function attempts(): int
            {
                return 1;
            }
        };

        $job->handle();
    }

    protected function history(): array
    {
        return IngestAttempt::query()->orderBy('id')->get()->all();
    }

    public function test_every_failed_attempt_keeps_a_row_with_its_attempt_number(): void
    {
        $source = $this->source();

        $this->runFailedAttempt($source);
        $source = $source->fresh();
        $this->runFailedAttempt($source);
        $source = $source->fresh();
        $this->runFailedAttempt($source);

        $rows = $this->history();

        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], array_map(fn ($row) => (int) $row->attempt_no, $rows));
        $this->assertSame(
            [IngestAttempt::OUTCOME_RETRYING, IngestAttempt::OUTCOME_RETRYING, IngestAttempt::OUTCOME_FAILED],
            array_map(fn ($row) => $row->outcome, $rows)
        );
        $this->assertSame('episode', $rows[0]->source_type);
        $this->assertSame(0, (int) $rows[0]->bytes);
        $this->assertStringContainsString('could not open the source URL', (string) $rows[0]->error);
        $this->assertNotNull($rows[0]->started_at);
        $this->assertNotNull($rows[0]->finished_at);
    }

    public function test_a_requeue_resets_the_counters_but_keeps_the_history(): void
    {
        $source = $this->contentSource();

        foreach ([1, 2, 3] as $ignored) {
            $this->runFailedAttempt($source, [], 'content');
            $source = $source->fresh();
        }

        $this->assertSame(3, (int) $source->ingest_attempts);
        $this->assertCount(3, $this->history());

        $result = app(SourceRequeuer::class)->requeue('content', (int) $source->id);

        $this->assertTrue($result['ok'], $result['message']);

        $source = $source->fresh();
        $rows = $this->history();

        $this->assertSame(0, (int) $source->ingest_attempts, 'the counters are disposable');
        $this->assertCount(4, $rows, 'the history survives the reset');
        $this->assertSame(IngestAttempt::OUTCOME_REQUEUED, $rows[3]->outcome);
        $this->assertSame(3, (int) $rows[3]->attempt_no);
        $this->assertSame([1, 2, 3], array_map(fn ($row) => (int) $row->attempt_no, array_slice($rows, 0, 3)));

        $this->runFailedAttempt($source, [], 'content');

        $rows = $this->history();

        $this->assertCount(5, $rows);
        $this->assertSame(1, (int) $rows[4]->attempt_no, 'the new budget starts at one');
        $this->assertSame((int) $source->id, (int) $rows[4]->source_id);
    }

    public function test_a_quarantined_attempt_records_the_workers_that_failed(): void
    {
        config([
            'media_relay.quarantine.zero_attempts' => 2,
            'media_relay.quarantine.zero_workers' => 2,
        ]);

        $source = $this->source('https://cdn.test/film.mkv');

        $this->runFailedAttempt($source, [11]);
        $this->runFailedAttempt($source->fresh(), [12]);

        $rows = $this->history();

        $this->assertCount(2, $rows);
        $this->assertSame('dead', (string) $source->fresh()->link_status);
        $this->assertSame(IngestAttempt::OUTCOME_QUARANTINED, $rows[1]->outcome);
        $this->assertSame('11,12', $rows[1]->route);
        $this->assertStringContainsString('قرنطینه', (string) $rows[1]->error);
    }

    public function test_a_successful_download_records_the_bytes_it_fetched(): void
    {
        $source = $this->source();

        $job = new HistorySuccessJob('episode', (int) $source->id, (string) $source->source, 'test.mkv');
        $job->handle();

        $source = $source->fresh();
        $rows = $this->history();

        $this->part = storage_path('app/ingest/ingest-' . $source->id . '.part');

        $this->assertSame('uploading', (string) $source->ingest_status, (string) $source->ingest_error);
        $this->assertCount(1, $rows);
        $this->assertSame(IngestAttempt::OUTCOME_DOWNLOADED, $rows[0]->outcome);
        $this->assertSame(2048, (int) $rows[0]->bytes);
        $this->assertSame(1, (int) $rows[0]->attempt_no);
        $this->assertNotNull($rows[0]->duration_ms);
    }

    public function test_the_history_is_pruned_to_the_configured_limit(): void
    {
        config(['media_relay.ingest.history_limit' => 2]);

        $source = $this->source();

        foreach ([1, 2, 3] as $ignored) {
            $this->runFailedAttempt($source);
            $source = $source->fresh();
        }

        $rows = $this->history();

        $this->assertCount(2, $rows);
        $this->assertSame([2, 3], array_map(fn ($row) => (int) $row->attempt_no, $rows));
    }

    public function test_the_panel_history_is_bounded_and_newest_first(): void
    {
        $first = $this->source();
        $second = $this->source();

        foreach (range(1, 5) as $attemptNo) {
            IngestAttempt::create([
                'source_type' => 'episode',
                'source_id' => $first->id,
                'attempt_no' => $attemptNo,
                'outcome' => IngestAttempt::OUTCOME_FAILED,
                'bytes' => 0,
            ]);
        }

        IngestAttempt::create([
            'source_type' => 'episode',
            'source_id' => $second->id,
            'attempt_no' => 1,
            'outcome' => IngestAttempt::OUTCOME_REQUEUED,
            'bytes' => 0,
        ]);

        $history = IngestAttempt::latestPerSource([
            'episode:' . $first->id,
            'episode:' . $second->id,
            'content:999',
        ], 3);

        $this->assertSame(
            [5, 4, 3],
            array_map(fn ($row) => (int) $row->attempt_no, $history['episode:' . $first->id])
        );
        $this->assertCount(1, $history['episode:' . $second->id]);
        $this->assertArrayNotHasKey('content:999', $history, 'a source with no history is not listed');
    }

    public function test_the_panel_row_shows_time_outcome_bytes_and_workers(): void
    {
        $attempt = IngestAttempt::create([
            'source_type' => 'episode',
            'source_id' => 1,
            'attempt_no' => 3,
            'outcome' => IngestAttempt::OUTCOME_QUARANTINED,
            'bytes' => 1048576,
            'route' => '11,12',
            'error' => 'no route delivered a byte',
            'finished_at' => now()->setDate(2026, 11, 20)->setTime(14, 5),
        ]);

        $html = view('partials.ingestAttemptHistory', ['attempts' => [$attempt]])->render();

        $this->assertStringContainsString('تلاش', $html);
        $this->assertStringContainsString('#3', $html);
        $this->assertStringContainsString('11/20 14:05', $html);
        $this->assertStringContainsString('قرنطینه', $html);
        $this->assertStringContainsString('1 MB', $html);
        $this->assertStringContainsString('ورکر 11,12', $html);
        $this->assertStringContainsString('no route delivered a byte', $html, 'the error is visible, not only in a tooltip');
    }

    public function test_a_long_error_is_truncated_in_the_panel_row(): void
    {
        $attempt = IngestAttempt::create([
            'source_type' => 'episode',
            'source_id' => 1,
            'attempt_no' => 2,
            'outcome' => IngestAttempt::OUTCOME_FAILED,
            'bytes' => 0,
            'error' => str_repeat('connection refused ', 20) . 'TAIL-MARKER',
            'finished_at' => now(),
        ]);

        $html = view('partials.ingestAttemptHistory', ['attempts' => [$attempt]])->render();

        $this->assertSame(1, preg_match('/<span class="text-danger"[^>]*>\s*(.*?)\s*<\/span>/s', $html, $match));

        $visible = trim($match[1]);

        $this->assertStringContainsString('connection refused', $visible);
        $this->assertStringNotContainsString('TAIL-MARKER', $visible, 'only the first part of a long error is rendered');
        $this->assertLessThanOrEqual(110, mb_strlen($visible));
    }
}

/** Always fails before a byte, on the Workers the test names. */
class HistoryProbeJob extends IngestUrlJob
{
    /** @var array<int,int> */
    public static array $workers = [];

    protected function probeSource(): array
    {
        $this->triedWorkers = self::$workers;

        throw new RuntimeException('could not open the source URL');
    }
}

/** Downloads a small file and hands it to the upload queue. */
class HistorySuccessJob extends IngestUrlJob
{
    public static int $bytes = 2048;

    protected function download($source, string $part): void
    {
        file_put_contents($part, str_repeat('x', self::$bytes));
    }

    /** The test schema has no catalogue tables; the folder is not under test. */
    protected function folderFor($source): ?string
    {
        return null;
    }
}
