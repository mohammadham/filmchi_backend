<?php

namespace App\Services\Relay;

/**
 * Which chunks of a partially downloaded file are already on disk.
 *
 * The ingest target is pre-sized with ftruncate(), so its length never says how
 * much of it is real. This sidecar records the chunk indexes that were written
 * and flushed, which lets a retried job resume instead of pulling the whole
 * file again. Every manifest is stamped with the total size and the chunk size
 * it was written for: a different remote file, or a changed chunk_mb, makes the
 * old offsets meaningless, so those chunks are dropped instead of trusted.
 */
class ChunkResumeState
{
    public function __construct(protected string $partPath)
    {
    }

    public static function forPart(string $partPath): self
    {
        return new self($partPath);
    }

    public function path(): string
    {
        return $this->partPath . '.done';
    }

    /**
     * Chunk indexes already on disk, as a set for fast lookups.
     *
     * @return array<int, true>
     */
    public function chunks(int $total, int $chunkSize, int $chunkCount): array
    {
        if ($total < 1 || $chunkSize < 1 || $chunkCount < 1) {
            return [];
        }

        $manifest = $this->path();

        if (!is_file($manifest) || !is_file($this->partPath)) {
            return [];
        }

        // A part file of a different length belongs to another attempt; never
        // mix its chunks into this one.
        if ((int) @filesize($this->partPath) !== $total) {
            return [];
        }

        $lines = @file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!is_array($lines) || ($lines[0] ?? null) !== $this->key($total, $chunkSize)) {
            return [];
        }

        $chunks = [];

        foreach (array_slice($lines, 1) as $line) {
            $line = trim((string) $line);

            // A partly written last line, or any junk, is simply not a chunk.
            if ($line === '' || !ctype_digit($line)) {
                continue;
            }

            $index = (int) $line;

            if ($index < $chunkCount) {
                $chunks[$index] = true;
            }
        }

        return $chunks;
    }

    /** Start (or restart) a manifest for the geometry of this attempt. */
    public function begin(int $total, int $chunkSize): void
    {
        @file_put_contents($this->path(), $this->key($total, $chunkSize) . "\n");
    }

    /** Remember one chunk whose bytes are already flushed to disk. */
    public function remember(int $index): void
    {
        @file_put_contents($this->path(), $index . "\n", FILE_APPEND);
    }

    public function forget(): void
    {
        if (is_file($this->path())) {
            @unlink($this->path());
        }
    }

    protected function key(int $total, int $chunkSize): string
    {
        return $total . ':' . $chunkSize;
    }
}
