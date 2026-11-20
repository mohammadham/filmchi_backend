<?php

namespace Tests\Feature;

use App\Jobs\IngestUrlJob;
use App\Services\Relay\ChunkResumeState;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\ReadyDestination;
use Tests\TestCase;

/**
 * A retried download must fetch only the chunks that are still missing.
 *
 * The source here is a closed port, so every range fails instantly without
 * touching the network; what matters is which ranges were attempted at all.
 */
class IngestResumeTest extends TestCase
{
    use ReadyDestination;
    /** @var string[] */
    protected array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('relay_workers')->delete();

        config([
            'media_relay.ingest.chunk_mb' => 1,
            'media_relay.ingest.min_chunk_mb' => 1,
            'media_relay.ingest.max_chunk_mb' => 1,
            'media_relay.ingest.chunk_stall_timeout' => 10,
            'media_relay.ingest.chunk_retry_budget' => 30,
            'media_relay.ingest.connections' => 4,
            'media_relay.ingest.total_connections' => 4,
            'media_relay.ingest.max_workers' => 0,
        ]);

        $this->stubReadyDestination();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            foreach ([$path, $path . '.done'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        parent::tearDown();
    }

    /** A pre-sized .part file, as the ingest job leaves behind. */
    protected function part(int $total): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingest-resume-job-');
        $out = fopen($path, 'c+b');
        ftruncate($out, $total);
        fclose($out);

        $this->paths[] = $path;

        return $path;
    }

    /** The job only touches these attributes while downloading. */
    protected function source(): object
    {
        return new class {
            public int $ingest_connections = 0;
            public string $ingest_status = 'downloading';
            public int $ingest_progress = 0;
            public int $ingest_bytes = 0;
            public int $ingest_speed_bps = 0;

            public function save(): bool
            {
                return true;
            }
        };
    }

    protected function job(): IngestUrlJob
    {
        return new class ('episode', 999, 'http://127.0.0.1:9/file.mkv', 'resume-test') extends IngestUrlJob {
            public function runSegmented($source, string $part, int $total): void
            {
                $this->downloadSegmented($source, $part, $total);
            }
        };
    }

    public function test_a_retry_fetches_only_the_chunks_that_are_missing(): void
    {
        $total = 3 * 1048576;
        $part = $this->part($total);

        $state = ChunkResumeState::forPart($part);
        $state->begin($total, 1048576);
        $state->remember(1);

        try {
            $this->job()->runSegmented($this->source(), $part, $total);
            $this->fail('an unreachable source must fail the download');
        } catch (\RuntimeException $e) {
            // Chunks 0 and 2, never the completed chunk 1.
            $this->assertStringContainsString('2 chunk(s) failed', $e->getMessage());
        }

        // The completed chunk is still recorded, so the next attempt resumes
        // from the same place instead of starting over.
        $this->assertSame([1 => true], $state->chunks($total, 1048576, 3));
    }

    public function test_without_a_manifest_every_chunk_is_fetched(): void
    {
        $total = 3 * 1048576;
        $part = $this->part($total);

        try {
            $this->job()->runSegmented($this->source(), $part, $total);
            $this->fail('an unreachable source must fail the download');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('3 chunk(s) failed', $e->getMessage());
        }

        $this->assertSame([], ChunkResumeState::forPart($part)->chunks($total, 1048576, 3));
    }
}
