<?php

namespace Tests\Feature;

use App\EpisodeSource;
use App\Jobs\IngestUrlJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\CreatesRelaySourceTables;
use Tests\TestCase;

/**
 * Admission of one ingest file against the disk budget.
 *
 * The probe and the budget answer are the only parts of the job that are
 * replaced here; everything else (status writes, the finally block, the queue
 * hand-off) is the real code path.
 */
class IngestAdmissionTest extends TestCase
{
    use CreatesRelaySourceTables;

    /** @var string[] */
    protected array $parts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSourceTables();
        DB::table('episode_sources')->delete();

        config([
            'media_relay.ingest.retry_max_attempts' => 3,
            'media_relay.ingest.retry_delay_seconds' => 300,
            'media_relay.ingest.disk_percent' => 50,
            'media_relay.ingest.connections' => 4,
            'media_relay.ingest.total_connections' => 4,
        ]);

        Queue::fake();

        AdmissionProbeJob::$probe = ['size' => 0, 'ranges' => false, 'status' => 200];
        AdmissionProbeJob::$reserve = null;
        AdmissionProbeJob::$allow = false;
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

    /** A .part file with real bytes in it, as a previous attempt leaves it. */
    protected function partFile(int $sourceId, int $bytes = 1048576): string
    {
        $dir = storage_path('app/ingest');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $part = $dir . '/ingest-' . $sourceId . '.part';
        file_put_contents($part, str_repeat('x', $bytes));
        $this->parts[] = $part;

        return $part;
    }

    protected function jobFor(EpisodeSource $source): AdmissionProbeJob
    {
        $job = new AdmissionProbeJob('episode', (int) $source->id, (string) $source->source, 'test.mkv');

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

    public function test_the_reservation_is_the_files_content_length_minus_what_is_already_on_disk(): void
    {
        $source = $this->source();
        $this->partFile((int) $source->id, 1048576);

        // 3 MB remote file, 1 MB already flushed to the .part file.
        AdmissionProbeJob::$probe = ['size' => 3 * 1048576, 'ranges' => false, 'status' => 200];

        $this->jobFor($source)->handle();

        $this->assertSame(2 * 1048576, AdmissionProbeJob::$reserve);
    }

    public function test_a_file_that_does_not_fit_is_deferred_without_spending_a_try(): void
    {
        $source = $this->source();
        $part = $this->partFile((int) $source->id, 1048576);

        AdmissionProbeJob::$probe = ['size' => 8 * 1048576, 'ranges' => false, 'status' => 200];

        $job = $this->jobFor($source);
        $job->handle();

        $fresh = $source->fresh();

        $this->assertSame('retrying', (string) $fresh->ingest_status);
        $this->assertSame(0, (int) $fresh->ingest_attempts);
        $this->assertSame(0, (int) $fresh->ingest_fail_count);
        $this->assertNotNull($fresh->ingest_next_retry_at);
        $this->assertStringContainsString('disk budget exhausted', (string) $fresh->ingest_error);

        // No attempt spent, and the partial file is still there to resume.
        $this->assertSame([], $job->job->released);
        $this->assertFileExists($part);

        // A fresh attempt is queued for after the retry delay instead of the
        // current job being released and spending one of its tries.
        Queue::assertPushed(AdmissionProbeJob::class, function ($queued) use ($source) {
            return (int) $queued->sourceId === (int) $source->id && $queued->delay !== null;
        });
    }

    public function test_a_source_without_a_content_length_reserves_the_typical_file(): void
    {
        $source = $this->source();

        AdmissionProbeJob::$probe = ['size' => 0, 'ranges' => false, 'status' => 200];

        $this->jobFor($source)->handle();

        $this->assertGreaterThanOrEqual(64 * 1048576, AdmissionProbeJob::$reserve);
    }

    public function test_an_admitted_file_downloads_and_fails_on_its_own_source(): void
    {
        $source = $this->source();

        AdmissionProbeJob::$allow = true;
        AdmissionProbeJob::$probe = ['size' => 1048576, 'ranges' => false, 'status' => 200];

        $job = $this->jobFor($source);
        $job->handle();

        $fresh = $source->fresh();

        // The budget said yes, so the closed port is what failed the attempt.
        $this->assertSame(1, (int) $fresh->ingest_attempts);
        $this->assertSame(1, (int) $fresh->ingest_fail_count);
        $this->assertSame('retrying', (string) $fresh->ingest_status);
        $this->assertSame([300], $job->job->released);
        $this->assertStringContainsString('could not open the source URL', (string) $fresh->ingest_error);
        Queue::assertNothingPushed();
    }
}

/**
 * The job with its two environment-facing answers replaced: the probe result
 * and the budget decision. Both are recorded so a test can assert what the job
 * asked the budget to reserve.
 */
class AdmissionProbeJob extends IngestUrlJob
{
    /** @var array{size:int,ranges:bool,status:int} */
    public static array $probe = ['size' => 0, 'ranges' => false, 'status' => 200];

    public static ?int $reserve = null;

    public static bool $allow = false;

    protected function probeSource(): array
    {
        return self::$probe;
    }

    protected function diskBudgetAllows(int $reserveBytes): bool
    {
        self::$reserve = $reserveBytes;

        return self::$allow;
    }
}
