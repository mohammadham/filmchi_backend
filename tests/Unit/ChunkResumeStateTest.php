<?php

namespace Tests\Unit;

use App\Services\Relay\ChunkResumeState;
use PHPUnit\Framework\TestCase;

class ChunkResumeStateTest extends TestCase
{
    /** @var string[] */
    protected array $paths = [];

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

    /** A pre-sized .part file, exactly like the ingest job creates. */
    protected function part(int $size): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingest-resume-');
        $out = fopen($path, 'c+b');
        ftruncate($out, $size);
        fclose($out);

        $this->paths[] = $path;

        return $path;
    }

    public function test_remembered_chunks_are_returned_for_the_same_geometry(): void
    {
        $part = $this->part(1000);
        $state = ChunkResumeState::forPart($part);

        $state->begin(1000, 100);
        $state->remember(0);
        $state->remember(3);

        $this->assertSame([0 => true, 3 => true], $state->chunks(1000, 100, 10));
    }

    public function test_nothing_is_resumed_without_a_manifest(): void
    {
        $state = ChunkResumeState::forPart($this->part(1000));

        $this->assertSame([], $state->chunks(1000, 100, 10));
    }

    public function test_a_changed_chunk_size_discards_the_manifest(): void
    {
        $part = $this->part(1000);
        $state = ChunkResumeState::forPart($part);

        $state->begin(1000, 100);
        $state->remember(0);

        $this->assertSame([], $state->chunks(1000, 50, 20));
    }

    public function test_a_part_file_of_another_length_is_not_resumed(): void
    {
        $part = $this->part(900);
        $state = ChunkResumeState::forPart($part);

        $state->begin(1000, 100);
        $state->remember(0);

        $this->assertSame([], $state->chunks(1000, 100, 10));
    }

    public function test_a_missing_part_file_is_not_resumed(): void
    {
        $part = $this->part(1000);
        $state = ChunkResumeState::forPart($part);

        $state->begin(1000, 100);
        $state->remember(0);
        unlink($part);

        $this->assertSame([], $state->chunks(1000, 100, 10));
    }

    public function test_junk_and_out_of_range_lines_are_ignored(): void
    {
        $part = $this->part(1000);
        $state = ChunkResumeState::forPart($part);

        file_put_contents($state->path(), "1000:100\n0\nabc\n-2\n12\n9\n");

        $this->assertSame([0 => true, 9 => true], $state->chunks(1000, 100, 10));
    }

    public function test_begin_resets_an_earlier_manifest(): void
    {
        $part = $this->part(1000);
        $state = ChunkResumeState::forPart($part);

        $state->begin(1000, 100);
        $state->remember(0);
        $state->remember(1);
        $state->begin(1000, 100);

        $this->assertSame([], $state->chunks(1000, 100, 10));
    }

    public function test_forget_removes_the_manifest(): void
    {
        $part = $this->part(1000);
        $state = ChunkResumeState::forPart($part);

        $state->begin(1000, 100);
        $state->remember(0);
        $state->forget();

        $this->assertFileDoesNotExist($part . '.done');
        $this->assertSame([], $state->chunks(1000, 100, 10));
    }
}
