<?php

namespace Tests\Feature;

use App\Services\Relay\DiskBudget;
use App\Services\Relay\IngestPartSweeper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreatesRelaySourceTables;
use Tests\TestCase;

/**
 * The stale .part sweeper.
 *
 * Retried downloads keep their partial file on purpose; these cases pin the
 * line between "a retry will resume this" and "nothing will ever touch it
 * again". The directory is a temp dir, so the cases never race the real relay.
 */
class IngestPartSweeperTest extends TestCase
{
    use CreatesRelaySourceTables;

    /** High ids so the cases cannot collide with rows already in the DB. */
    protected array $ids = [990001, 990002, 990003, 990004, 990005, 990006, 990007, 990008, 990009];

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSourceTables();

        $this->dir = sys_get_temp_dir() . '/flixy-ingest-sweep-' . uniqid();

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->dir);

        foreach (['episode_sources', 'content_sources'] as $table) {
            try {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('id', $this->ids)->delete();
                }
            } catch (\Throwable $e) {
                // The test DB is not always reachable; nothing to clean then.
            }
        }

        $this->dropSourceTables();

        parent::tearDown();
    }

    protected function sourceRow(int $id, string $status, ?int $retryInSeconds = null): void
    {
        DB::table('episode_sources')->insert([
            'id' => $id,
            'episode_id' => 1,
            'ingest_status' => $status,
            'ingest_next_retry_at' => $retryInSeconds === null ? null : now()->addSeconds($retryInSeconds),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function part(int $id, int $bytes = 4096, ?int $ageSeconds = null): string
    {
        $path = $this->dir . '/ingest-' . $id . '.part';
        file_put_contents($path, str_repeat('x', $bytes));

        if ($ageSeconds !== null) {
            @touch($path, time() - $ageSeconds);
        }

        return $path;
    }

    protected function sweep(int $staleHours = 12, bool $dryRun = false): array
    {
        return (new IngestPartSweeper($this->dir))->sweep($staleHours, $dryRun);
    }

    public function test_an_abandoned_retrying_part_is_removed_and_its_bytes_reported(): void
    {
        $this->sourceRow(990001, 'retrying');
        $part = $this->part(990001, 4096, 24 * 3600);
        $expected = DiskBudget::fileBytes($part);

        $result = $this->sweep(12);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame($expected, $result['bytes']);
        $this->assertFileDoesNotExist($part);
    }

    public function test_a_retrying_source_with_a_scheduled_retry_keeps_its_part(): void
    {
        $this->sourceRow(990002, 'retrying', 3600);
        $part = $this->part(990002, 4096, 24 * 3600);

        $result = $this->sweep(12);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $result['kept']);
        $this->assertFileExists($part);
    }

    public function test_a_live_download_is_never_swept_however_old_the_file_is(): void
    {
        $this->sourceRow(990003, 'downloading');
        $part = $this->part(990003, 4096, 48 * 3600);

        $result = $this->sweep(12);

        $this->assertSame(0, $result['deleted']);
        $this->assertFileExists($part);
    }

    public function test_a_recent_part_is_kept_even_when_its_source_row_is_gone(): void
    {
        $part = $this->part(990004, 4096, 60);

        $result = $this->sweep(12);

        $this->assertSame(0, $result['deleted']);
        $this->assertFileExists($part);
    }

    public function test_an_orphan_part_is_removed_with_its_chunk_manifest(): void
    {
        $part = $this->part(990005, 4096, 24 * 3600);
        file_put_contents($part . '.done', "4096:4096\n0\n");

        $result = $this->sweep(12);

        $this->assertSame(1, $result['deleted']);
        $this->assertFileDoesNotExist($part);
        $this->assertFileDoesNotExist($part . '.done');
    }

    public function test_a_sparse_part_frees_only_the_bytes_it_really_used(): void
    {
        $this->sourceRow(990006, 'failed');

        $part = $this->dir . '/ingest-990006.part';
        $out = fopen($part, 'c+b');
        ftruncate($out, 64 * 1048576);
        fclose($out);
        @touch($part, time() - 24 * 3600);

        $result = $this->sweep(12);

        $this->assertSame(1, $result['deleted']);
        $this->assertLessThanOrEqual(64 * 1048576, $result['bytes']);
        $this->assertFileDoesNotExist($part);
    }

    public function test_a_dry_run_reports_what_would_be_removed(): void
    {
        $this->sourceRow(990007, 'retrying');
        $part = $this->part(990007, 4096, 24 * 3600);

        $result = $this->sweep(12, true);

        $this->assertSame(1, $result['deleted']);
        $this->assertGreaterThan(0, $result['bytes']);
        $this->assertFileExists($part);
    }

    public function test_a_chunk_manifest_left_behind_by_a_removed_part_is_cleaned_up(): void
    {
        $manifest = $this->dir . '/ingest-990008.part.done';
        file_put_contents($manifest, "1024:1024\n0\n");
        @touch($manifest, time() - 24 * 3600);

        $result = $this->sweep(12);

        $this->assertSame(1, $result['sidecars']);
        $this->assertFileDoesNotExist($manifest);
    }
}
