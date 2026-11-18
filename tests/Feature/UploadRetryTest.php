<?php

namespace Tests\Feature;

use App\EpisodeSource;
use App\Jobs\UploadIngestJob;
use App\Services\Relay\RelayStorage;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Concerns\CreatesRelaySourceTables;
use Tests\TestCase;

/**
 * The upload job's retry decision and error counters.
 *
 * RelayStorage is the only dependency replaced: it is what talks to Telegram
 * Drive, and every assertion here is about the row the job leaves behind.
 */
class UploadRetryTest extends TestCase
{
    use CreatesRelaySourceTables;

    protected array $parts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSourceTables();
        DB::table('episode_sources')->delete();

        config([
            'media_relay.upload.retry_max_attempts' => 3,
            'media_relay.upload.retry_delay_seconds' => 120,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->parts as $part) {
            if (is_file($part)) {
                @unlink($part);
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
            'ingest_status' => 'uploading',
            'ingest_progress' => 100,
            'ingest_attempts' => 1,
            'ingest_fail_count' => 0,
            'upload_attempts' => 0,
            'upload_fail_count' => 0,
        ], $attributes))->save();

        return $source;
    }

    /** A downloaded file the upload is meant to store. */
    protected function partFile(int $sourceId = 0): string
    {
        $dir = storage_path('app/ingest');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $part = $dir . '/ingest-' . ($sourceId ?: 0) . '.part';
        file_put_contents($part, 'downloaded-bytes');
        $this->parts[] = $part;

        return $part;
    }

    protected function jobFor(EpisodeSource $source, string $part): UploadIngestJob
    {
        $job = new UploadIngestJob('episode', (int) $source->id, $part, 'test.mkv');

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

    /** Telegram Drive refusing the file, which is a retryable failure. */
    protected function rejectingStorage(): RelayStorage
    {
        return new class extends RelayStorage {
            public function __construct()
            {
            }

            public function storePath(string $path, string $name, string $mime = null, string $extension = null, int $size = 0, string $folder = null, ?array &$metrics = null): string
            {
                throw new RuntimeException('Telegram Drive failed to store the file');
            }
        };
    }

    public function test_a_failed_upload_releases_the_job_and_counts_the_attempt(): void
    {
        $source = $this->source();
        $part = $this->partFile((int) $source->id);
        $job = $this->jobFor($source, $part);

        $job->handle($this->rejectingStorage());

        $fresh = $source->fresh();

        // markStarted() bumps the attempt counter before the upload runs, so a
        // failed attempt is still counted in the panel.
        $this->assertSame(1, (int) $fresh->upload_attempts);
        $this->assertSame(1, (int) $fresh->upload_fail_count);
        $this->assertSame('retrying', (string) $fresh->ingest_status);
        $this->assertSame('retrying', (string) $fresh->upload_status);
        $this->assertSame([120], $job->job->released);
        $this->assertNotNull($fresh->upload_next_retry_at);
        $this->assertStringContainsString('Telegram Drive failed to store the file', (string) $fresh->ingest_error);
        $this->assertFileExists($part);
    }

    public function test_the_last_upload_attempt_fails_the_source_and_drops_the_part(): void
    {
        // Two uploads are already spent, so this one is the third and last.
        $source = $this->source(['upload_attempts' => 2]);
        $part = $this->partFile((int) $source->id);
        $job = $this->jobFor($source, $part);

        $job->handle($this->rejectingStorage());

        $fresh = $source->fresh();

        $this->assertSame(3, (int) $fresh->upload_attempts);
        $this->assertSame(1, (int) $fresh->upload_fail_count);
        $this->assertSame('failed', (string) $fresh->ingest_status);
        $this->assertSame('failed', (string) $fresh->upload_status);
        $this->assertSame([], $job->job->released);
        $this->assertNull($fresh->upload_next_retry_at);
        $this->assertFileDoesNotExist($part);
    }

    public function test_a_missing_part_file_fails_immediately_without_retrying(): void
    {
        $source = $this->source();
        $job = $this->jobFor($source, storage_path('app/ingest/ingest-missing.part'));

        $job->handle($this->rejectingStorage());

        $fresh = $source->fresh();

        // Retrying cannot recreate a local file, so the job must not burn the
        // remaining queue attempts on it. markFailed() still records that one
        // upload was attempted.
        $this->assertSame(1, (int) $fresh->upload_attempts);
        $this->assertSame(1, (int) $fresh->upload_fail_count);
        $this->assertSame('failed', (string) $fresh->ingest_status);
        $this->assertSame([], $job->job->released);
        $this->assertStringContainsString('.part file is missing', (string) $fresh->ingest_error);
    }

    public function test_a_successful_upload_completes_the_source(): void
    {
        $source = $this->source(['upload_attempts' => 1]);
        $part = $this->partFile((int) $source->id);
        $job = $this->jobFor($source, $part);

        $storage = new class extends RelayStorage {
            public function __construct()
            {
            }

            public function storePath(string $path, string $name, string $mime = null, string $extension = null, int $size = 0, string $folder = null, ?array &$metrics = null): string
            {
                return 'https://cdn.example.com/' . $name;
            }
        };

        $job->handle($storage);

        $fresh = $source->fresh();

        $this->assertSame('completed', (string) $fresh->ingest_status);
        $this->assertSame('https://cdn.example.com/test.mkv', (string) $fresh->source);
        $this->assertSame(2, (int) $fresh->upload_attempts);
        $this->assertSame(0, (int) $fresh->upload_fail_count);
        $this->assertFileDoesNotExist($part);
    }
}
