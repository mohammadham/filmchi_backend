<?php

namespace App\Services\Relay;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Removes abandoned ingest .part files.
 *
 * A retried download keeps its .part file so the next attempt can resume the
 * chunks that were already flushed instead of pulling the whole file again.
 * When that retry never comes back (a lost queue message, a source that fails
 * before it reaches the file, a worker killed mid-run), the part stays on disk
 * forever: a few hundred retrying sources are gigabytes of dead weight and
 * eventually the disk that also holds MySQL and Redis.
 *
 * A part is deleted only when nothing can still write to it: its source is not
 * queued, downloading, uploading or completed, no retry is scheduled inside
 * the stale window, and the file itself has not been touched for that window.
 */
class IngestPartSweeper
{
    /** Statuses that mean a job may still read or write this file. */
    public const ACTIVE_STATUSES = ['queued', 'downloading', 'uploading', 'completed'];

    protected string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? storage_path('app/ingest'), '/\\');
    }

    /**
     * Delete abandoned parts. Returns what was found and, unless $dryRun,
     * what was freed.
     *
     * @return array{deleted:int,bytes:int,sidecars:int,kept:int}
     */
    public function sweep(?int $staleHours = null, bool $dryRun = false): array
    {
        $hours = max(1, $staleHours ?? (int) config('media_relay.ingest.stale_part_hours', 12));
        $cutoff = time() - $hours * 3600;

        $result = ['deleted' => 0, 'bytes' => 0, 'sidecars' => 0, 'kept' => 0];

        foreach (glob($this->dir . '/ingest-*.part') ?: [] as $part) {
            if (!preg_match('/ingest-(\d+)\.part$/', $part, $match)) {
                continue;
            }

            $mtime = @filemtime($part);

            // A file written recently may belong to a running attempt.
            if ($mtime === false || $mtime > $cutoff) {
                $result['kept']++;

                continue;
            }

            if (!$this->isAbandoned((int) $match[1], $cutoff)) {
                $result['kept']++;

                continue;
            }

            $result['bytes'] += DiskBudget::fileBytes($part);

            if (!$dryRun) {
                @unlink($part);
                @unlink($part . '.done');
            }

            $result['deleted']++;
        }

        // Chunk manifests whose part file is already gone would otherwise pile
        // up as a second class of orphan.
        foreach (glob($this->dir . '/*.part.done') ?: [] as $manifest) {
            if (is_file(substr($manifest, 0, -5))) {
                continue;
            }

            $mtime = @filemtime($manifest);

            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }

            if (!$dryRun) {
                @unlink($manifest);
            }

            $result['sidecars']++;
        }

        return $result;
    }

    /**
     * True when every source row that owns this file has stopped moving and
     * has no retry left to run.
     */
    protected function isAbandoned(int $id, int $cutoff): bool
    {
        $rows = $this->rows($id);

        // No row at all: the source was deleted or renumbered, nothing can
        // ever resume this file.
        if (empty($rows)) {
            return true;
        }

        foreach ($rows as $row) {
            if (in_array((string) ($row->ingest_status ?? ''), self::ACTIVE_STATUSES, true)) {
                return false;
            }

            $retryAt = $row->ingest_next_retry_at ?? null;

            if ($retryAt !== null) {
                $timestamp = strtotime((string) $retryAt);

                // A retry that is due, or was due inside the stale window, is
                // still going to resume this file.
                if ($timestamp !== false && $timestamp > $cutoff) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return object[] */
    protected function rows(int $id): array
    {
        $rows = [];

        // Both tables share the id space the part filename uses, so a row in
        // either one can still own the file.
        foreach (['episode_sources', 'content_sources'] as $table) {
            try {
                if (!Schema::hasTable($table)) {
                    continue;
                }

                $row = DB::table($table)
                    ->where('id', $id)
                    ->first(['id', 'ingest_status', 'ingest_next_retry_at']);
            } catch (Throwable $e) {
                continue;
            }

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
