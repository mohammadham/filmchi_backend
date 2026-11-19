<?php

namespace Tests\Feature;

use App\Services\Relay\DiskBudget;
use Tests\TestCase;

/**
 * The ingest disk budget: which bytes are counted and whether a file fits.
 *
 * Every case here is arithmetic on purpose. The old rule turned the budget
 * into a slot count and then floored it at one, so a full disk still received
 * a download; these assertions pin the two properties that stop that: usage is
 * the allocated size, and the answer may be "nothing fits".
 */
class DiskBudgetTest extends TestCase
{
    /** @var string[] */
    protected array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /** A temp file, either pre-sized like an ingest target or with content. */
    protected function tempFile(int $size = 0, ?string $content = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingest-budget-');

        if ($content !== null) {
            file_put_contents($path, $content);
        } elseif ($size > 0) {
            $out = fopen($path, 'c+b');
            ftruncate($out, $size);
            fclose($out);
        }

        $this->paths[] = $path;

        return $path;
    }

    public function test_a_pre_sized_part_counts_its_allocated_blocks_not_its_length(): void
    {
        // Only filesystems that really report allocated blocks can tell a
        // sparse ftruncate() from data. Windows reports -1 (unknown), so the
        // case has nothing to measure there.
        $written = $this->tempFile(0, str_repeat('x', 1048576));
        $probe = stat($written);

        if (!is_array($probe) || (int) ($probe['blocks'] ?? -1) <= 0) {
            $this->markTestSkipped('this platform does not report allocated blocks');
        }

        // Exactly what the job does before the first byte arrives: ftruncate()
        // to the whole remote file. The length says 64 MB, the disk holds none.
        $size = 64 * 1048576;
        $sparse = $this->tempFile($size);

        $this->assertSame($size, (int) filesize($sparse));
        $this->assertLessThan(1048576, DiskBudget::fileBytes($sparse));
    }

    public function test_a_written_part_counts_its_content(): void
    {
        $path = $this->tempFile(0, str_repeat('x', 1048576));
        $bytes = DiskBudget::fileBytes($path);

        // Allocation rounds up to a block, so allow one filesystem block of slack.
        $this->assertGreaterThanOrEqual(1048576, $bytes);
        $this->assertLessThanOrEqual(1048576 + 65536, $bytes);
    }

    public function test_a_missing_file_costs_nothing(): void
    {
        $this->assertSame(0, DiskBudget::fileBytes(sys_get_temp_dir() . '/flixy-absent-' . uniqid()));
    }

    public function test_a_file_larger_than_what_is_left_is_refused(): void
    {
        $budget = 900 * 1048576;

        // A 2 GB episode does not fit in 900 MB, however typical it is.
        $this->assertFalse(DiskBudget::fits(2 * 1048576 * 1024, $budget, 0));
        $this->assertTrue(DiskBudget::fits(899 * 1048576, $budget, 0));
    }

    public function test_bytes_already_in_flight_are_claimed_first(): void
    {
        $budget = 8192;

        $this->assertTrue(DiskBudget::fits(4096, $budget, 4096));
        $this->assertFalse(DiskBudget::fits(4097, $budget, 4096));

        // Nothing left: only a request that needs no new bytes is admitted.
        $this->assertTrue(DiskBudget::fits(0, $budget, $budget));
        $this->assertFalse(DiskBudget::fits(1, $budget, $budget));
        $this->assertFalse(DiskBudget::fits(1, $budget, $budget + 1));
    }

    public function test_no_download_slot_is_granted_when_the_budget_is_used_up(): void
    {
        $this->assertSame(0, DiskBudget::slots(1000, 1000, 100, 4));
        $this->assertSame(0, DiskBudget::slots(1000, 4000, 100, 4));
    }

    public function test_slots_follow_the_remaining_budget_without_a_floor_of_one(): void
    {
        // 250 bytes left against a 1000-byte typical file: the old floor let
        // this start anyway, which is what filled the disk.
        $this->assertSame(0, DiskBudget::slots(1250, 1000, 1000, 4));

        $this->assertSame(2, DiskBudget::slots(3000, 1000, 1000, 4));
        $this->assertSame(4, DiskBudget::slots(9000, 1000, 1000, 4));
        $this->assertSame(0, DiskBudget::slots(9000, 0, 1000, 0));
    }

    public function test_a_zero_percentage_disables_the_budget(): void
    {
        config(['media_relay.ingest.disk_percent' => 0]);

        $this->assertSame(4, DiskBudget::desiredDownloads(4));
        $this->assertTrue(DiskBudget::canAdmit(1024));
    }

    public function test_admission_is_decided_from_the_remaining_budget(): void
    {
        config(['media_relay.ingest.disk_percent' => 50]);

        $this->assertTrue(DiskBudget::canAdmit(1000, 8192, 4096));
        $this->assertFalse(DiskBudget::canAdmit(5000, 8192, 4096));
        $this->assertFalse(DiskBudget::canAdmit(1, 8192, 8192));
    }

    public function test_a_zero_second_after_the_panel_allows_no_downloads_at_all(): void
    {
        config(['media_relay.ingest.disk_percent' => 50]);

        $this->assertSame(0, DiskBudget::desiredDownloads(0));
    }
}
