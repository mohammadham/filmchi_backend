<?php

namespace Tests\Feature;

use App\EpisodeSource;
use App\Jobs\IngestUrlJob;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreatesRelaySourceTables;
use Tests\TestCase;

/**
 * The download job's retry decision and error counters.
 *
 * The source answers nothing (a closed local port), so the probe fails at once
 * and the job takes its real failure path; only the queue's release() and the
 * part file on disk are observed.
 */
class DownloadRetryTest extends TestCase
{
    use CreatesRelaySourceTables;

    protected array $parts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSourceTables();
        DB::table('episode_sources')->delete();

        config([
            'media_relay.ingest.retry_max_attempts' => 3,
            'media_relay.ingest.retry_delay_seconds' => 300,
            'media_relay.ingest.connections' => 1,
            'media_relay.ingest.total_connections' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->parts as $part) {
            foreach ([$part, $part . '.done'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        DB::table('episode_sources')->delete();
        $this->dropSourceTables();

        parent::tearDown();
    }

    protected function source(array $attributes = []): EpisodeSource
    {
        $source = new EpisodeSource();
        $source->forceFill(array_merge([
            'episode_id' => 1,
            'source' => 'http://127.0.0.1:9/film.mkv',
            'ingest_status' => 'queued',
            'ingest_progress' => 0,
            'ingest_attempts' => 0,
            'ingest_fail_count' => 0,
        ], $attributes))->save();

        return $source;
    }

    /** A .part file where the job keeps its partial download. */
    protected function partFile(int $sourceId, string $contents = 'partial-bytes'): string
    {
        $dir = storage_path('app/ingest');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $part = $dir . '/ingest-' . $sourceId . '.part';
        file_put_contents($part, $contents);
        $this->parts[] = $part;

        return $part;
    }

    protected function jobFor(EpisodeSource $source): IngestUrlJob
    {
        $job = new IngestUrlJob('episode', (int) $source->id, (string) $source->source, 'test.mkv');

        // The queue object is the only thing a unit test has to stand in for:
        // release() is what hands the job back for a later attempt.
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

        return $job;
    }

    public function test_a_failed_download_releases_the_job_and_counts_the_attempt(): void
    {
        $source = $this->source();
        $job = $this->jobFor($source);

        $job->handle();

        $fresh = $source->fresh();

        $this->assertSame(1, (int) $fresh->ingest_attempts);
        $this->assertSame(1, (int) $fresh->ingest_fail_count);
        $this->assertSame('retrying', (string) $fresh->ingest_status);
        $this->assertSame([300], $job->job->released);
        $this->assertNotNull($fresh->ingest_next_retry_at);
        $this->assertStringContainsString('could not open the source URL', (string) $fresh->ingest_error);
        $this->assertNull($fresh->upload_status);
    }

    public function test_the_last_attempt_fails_the_source_and_drops_the_part(): void
    {
        // Two attempts are already spent, so this one is the third and last.
        $source = $this->source(['ingest_attempts' => 2]);
        $part = $this->partFile((int) $source->id);
        $job = $this->jobFor($source);

        $job->handle();

        $fresh = $source->fresh();

        $this->assertSame(3, (int) $fresh->ingest_attempts);
        $this->assertSame('failed', (string) $fresh->ingest_status);
        $this->assertSame([], $job->job->released);
        $this->assertNull($fresh->ingest_next_retry_at);
        $this->assertFileDoesNotExist($part);
    }

    public function test_a_retry_keeps_the_part_file_so_the_next_attempt_can_resume(): void
    {
        $source = $this->source();
        $part = $this->partFile((int) $source->id);

        $this->jobFor($source)->handle();

        $this->assertSame('retrying', (string) $source->fresh()->ingest_status);
        $this->assertFileExists($part);
    }

    public function test_a_completed_source_is_never_downloaded_again(): void
    {
        $source = $this->source(['ingest_status' => 'completed']);
        $job = $this->jobFor($source);

        $job->handle();

        $this->assertSame(0, (int) $source->fresh()->ingest_attempts);
        $this->assertSame('completed', (string) $source->fresh()->ingest_status);
        $this->assertSame([], $job->job->released);
    }
}
