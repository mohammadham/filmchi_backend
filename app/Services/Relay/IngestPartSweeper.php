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
 * queued, downloading or uploading, and the file itself has not been touched
 * for the stale window. A completed source is not active: its bytes are on
 * Telegram Drive and the upload job unlinks the part immediately, so one that
 * survives is a crash artifact.
 *
 * A scheduled retry is deliberately not a reason to keep the file. A retry
 * that is really due writes within seconds and refreshes the file's mtime; a
 * retry that keeps sliding forward without writing is exactly the admission
 * loop that lets dead parts live forever. Being blocked on disk space is not
 * progress either: the source can download again once there is room.
 *
 * The panel's resumable upload has the same failure mode in a different folder:
 * storage/app/tmp-uploads is bind-mounted, no source row owns those parts, and
 * the only code that deletes one runs when the browser finishes or the request
 * fails. sweepTmpUploads() closes that hole on age alone.
 */
class IngestPartSweeper
{
    /** Statuses that mean a job may still read or write this file. */
    public const ACTIVE_STATUSES = ['queued', 'downloading', 'uploading'];

    protected string $dir;

    protected string $tmpDir;

    public function __construct(?string $dir = null, ?string $tmpDir = null)
    {
        $this->dir = rtrim($dir ?? storage_path('app/ingest'), '/\\');
        $this->tmpDir = rtrim($tmpDir ?? storage_path('app/tmp-uploads'), '/\\');
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

            if (!$this->isAbandoned((int) $match[1])) {
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
     * Delete resumable-upload parts no browser can still be writing.
     *
     * An upload that finished (or failed inside its request) unlinks its part
     * itself. A tab closed mid-upload leaves the file behind, and since
     * nothing else knows the id, age is the whole test: a live upload appends
     * chunks and keeps the mtime current.
     *
     * @return array{deleted:int,bytes:int,kept:int}
     */
    public function sweepTmpUploads(?int $hours = null, bool $dryRun = false): array
    {
        $hours = max(1, $hours ?? (int) config('media_relay.ingest.tmp_upload_hours', 12));
        $cutoff = time() - $hours * 3600;

        $result = ['deleted' => 0, 'bytes' => 0, 'kept' => 0];

        foreach (glob($this->tmpDir . '/*.part') ?: [] as $part) {
            $mtime = @filemtime($part);

            if ($mtime === false || $mtime > $cutoff) {
                $result['kept']++;

                continue;
            }

            $result['bytes'] += DiskBudget::fileBytes($part);

            if (!$dryRun) {
                @unlink($part);
            }

            $result['deleted']++;
        }

        return $result;
    }

    /**
     * True when every source row that owns this file has stopped moving.
     *
     * Only a live job counts as movement. A retry timestamp is not enough:
     * the file is already older than the stale window, so whatever is
     * scheduled has not written a byte for that long.
     */
    protected function isAbandoned(int $id): bool
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
                    ->first(['id', 'ingest_status']);
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
