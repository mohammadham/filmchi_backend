<?php

namespace App\Services\Relay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admission budget for large ingest files.
 *
 * Every download writes a .part file, and an upload keeps that same file on
 * disk until Telegram Drive has stored it. The budget therefore covers both
 * phases: a new download may only start while the bytes it is actually going
 * to write still fit in the room left by three brakes — the free-space floor,
 * the percentage of free space, and the ceiling on the in-flight backlog.
 * Those bytes are already missing from freeBytes(), so nothing is subtracted
 * twice.
 *
 * Two rules keep this honest:
 *
 *  - Sizes are read from the allocated 512-byte blocks, never from the file
 *    length. An ingest target is pre-sized with ftruncate() to the whole remote
 *    file, so a source that has downloaded 300 MB of a 107 GB episode has an
 *    apparent size of 107 GB and an allocated size of 300 MB. Counting lengths
 *    both overstates usage and hides a genuinely full disk.
 *  - The decision is per file, from that file's own Content-Length. A median
 *    file size cannot answer "does this one fit": a single 2 GB episode does
 *    not fit in 900 MB that are actually left, however small the median is.
 */
class DiskBudget
{
    public const CACHE_KEY = 'relay:disk';

    public static function percent(): int
    {
        return max(0, min(95, (int) config('media_relay.ingest.disk_percent', 50)));
    }

    public static function freeBytes(): int
    {
        $free = @disk_free_space(storage_path('app/ingest'));

        if ($free === false) {
            $free = @disk_free_space(storage_path());
        }

        return max(0, (int) $free);
    }

    /**
     * Bytes a file really occupies on disk.
     *
     * stat() reports allocated blocks in 512-byte units, which is what the
     * filesystem has actually handed out: a sparse ftruncate()'d file that has
     * no data in it counts as zero even though its length is gigabytes. Zero
     * blocks is therefore a real answer and must not be turned back into the
     * length; only a platform that cannot report blocks at all (-1, as on
     * Windows) falls back to the length.
     */
    public static function fileBytes(string $path): int
    {
        $stat = @stat($path);

        if (!is_array($stat)) {
            return 0;
        }

        $blocks = (int) ($stat['blocks'] ?? -1);

        if ($blocks >= 0) {
            return $blocks * 512;
        }

        return max(0, (int) ($stat['size'] ?? 0));
    }

    /**
     * Bytes actually written into a file: the same allocated blocks, but zero
     * when the platform cannot report them.
     *
     * This is the number a reservation may subtract. A .part file is
     * pre-sized with ftruncate() to the whole remote file, so its length says
     * nothing about how much has been fetched; treating that length as
     * progress would reserve nothing at all for a file that is still empty.
     */
    public static function writtenBytes(string $path): int
    {
        $stat = @stat($path);

        if (!is_array($stat)) {
            return 0;
        }

        $blocks = (int) ($stat['blocks'] ?? -1);

        return $blocks >= 0 ? $blocks * 512 : 0;
    }

    /** Bytes currently held by downloaded or pending-upload .part files. */
    public static function ingestBytes(): int
    {
        $bytes = 0;

        foreach (glob(storage_path('app/ingest/*.part')) ?: [] as $file) {
            $bytes += self::fileBytes($file);
        }

        return $bytes;
    }

    /** Free space that ingest is never allowed to spend, in bytes. */
    public static function minFreeBytes(): int
    {
        $mb = (int) config('media_relay.ingest.min_free_mb', 8192);

        return max(0, $mb) * 1048576;
    }

    /** Ceiling for the bytes held by .part files in flight. 0 = no ceiling. */
    public static function maxInflightBytes(): int
    {
        $mb = (int) config('media_relay.ingest.max_inflight_mb', 4096);

        return max(0, $mb) * 1048576;
    }

    /**
     * Room for more ingest bytes, in bytes.
     *
     * Three independent brakes, all expressed as "how much more may be
     * written" so they can be compared and not mixed with the bytes already
     * on disk:
     *
     *  - the floor: never take free space below min_free_mb;
     *  - the percentage: never spend more than disk_percent of what is free;
     *  - the ceiling: never start new work that would push the in-flight
     *    backlog past max_inflight_mb.
     *
     * The percentage used to be measured against a budget that then had the
     * parts on disk subtracted again. Those parts are already missing from
     * freeBytes(), so that subtraction counted them twice and a host with
     * plenty of room could sit at zero downloads.
     *
     * $ceiling can be dropped for work that is already on disk. A file that
     * has been started is part of the backlog whether or not the ceiling is
     * respected; refusing its retry because the backlog is already above the
     * ceiling freezes every part at once, with nothing uploading and nothing
     * deleting them. Measured on the live host this stranded 4.5 GB in 54
     * half-downloaded files while the panel showed zero downloads.
     */
    public static function roomBytes(bool $ceiling = true): int
    {
        $free = self::freeBytes();
        $percent = self::percent();

        $room = max(0, $free - self::minFreeBytes());

        if ($percent > 0) {
            $room = min($room, (int) floor($free * $percent / 100));
        }

        if ($ceiling) {
            $cap = self::maxInflightBytes();

            if ($cap > 0) {
                $room = min($room, max(0, $cap - self::ingestBytes()));
            }
        }

        return max(0, $room);
    }

    /**
     * Total bytes the in-flight files may hold.
     *
     * Expressed as a total (what is on disk plus what may still be added) so
     * that canAdmit() and slots() keep comparing like with like.
     */
    public static function budgetBytes(bool $ceiling = true): int
    {
        if (self::percent() <= 0) {
            return 0;
        }

        return self::ingestBytes() + self::roomBytes($ceiling);
    }

    /**
     * What is left of that budget once the files already on disk are counted.
     *
     * Those bytes are in flight: they are downloaded and not yet uploaded, so
     * they cannot be spent on another download.
     */
    public static function remainingBytes(): int
    {
        return max(0, self::budgetBytes() - self::ingestBytes());
    }

    /**
     * Typical size of a file in this relay.
     *
     * Only two narrow uses are left: turning what is left of the budget into a
     * worker count, and reserving something when a source does not report a
     * Content-Length at all. Admission of a file with a known length never
     * uses it.
     */
    public static function typicalBytes(): int
    {
        return (int) Cache::remember(self::CACHE_KEY . ':typical', 30, function () {
            $sizes = [];

            foreach (['episode_sources', 'content_sources'] as $table) {
                try {
                    if (!Schema::hasTable($table)) {
                        continue;
                    }

                    $rows = DB::table($table)
                        ->where('ingest_status', 'completed')
                        ->where('ingest_bytes', '>', 0)
                        ->orderByDesc('id')
                        ->limit(30)
                        ->pluck('ingest_bytes')
                        ->all();

                    foreach ($rows as $value) {
                        $sizes[] = (int) $value;
                    }
                } catch (\Throwable $e) {
                    // A table can be absent while a deploy is migrating.
                }
            }

            if (empty($sizes)) {
                foreach (glob(storage_path('app/ingest/*.part')) ?: [] as $file) {
                    $size = self::fileBytes($file);

                    if ($size > 0) {
                        $sizes[] = $size;
                    }
                }
            }

            if (empty($sizes)) {
                return 512 * 1048576;
            }

            sort($sizes);
            $middle = intdiv(count($sizes), 2);
            $median = count($sizes) % 2 === 0
                ? intdiv($sizes[$middle - 1] + $sizes[$middle], 2)
                : $sizes[$middle];

            return max(64 * 1048576, (int) $median);
        });
    }

    /**
     * Does one more file of this size fit in the ingest budget?
     *
     * $reserveBytes is the number of bytes the caller is about to write (the
     * file's Content-Length minus whatever its .part file already holds), and
     * $inFlight is what the files on disk already occupy. A zero percentage
     * disables the budget entirely.
     *
     * $ceiling is false for a file that already has bytes on disk: it has to
     * be allowed to finish and free itself. See roomBytes().
     */
    public static function canAdmit(
        int $reserveBytes,
        ?int $budget = null,
        ?int $inFlight = null,
        bool $ceiling = true
    ): bool {
        if (self::percent() <= 0) {
            return true;
        }

        return max(0, $inFlight ?? self::ingestBytes())
            + max(0, $reserveBytes)
            <= max(0, $budget ?? self::budgetBytes($ceiling));
    }

    /**
     * How many files of the typical size still fit.
     *
     * Returning 0 is a real answer: when the files already on disk fill the
     * budget, no download may start. The old floor of one was exactly what let
     * the relay keep adding files to a full disk.
     */
    public static function slots(int $budget, int $inFlight, int $typical, int $max): int
    {
        $max = max(0, $max);
        $remaining = max(0, $budget) - max(0, $inFlight);

        if ($max === 0 || $remaining <= 0) {
            return 0;
        }

        return min($max, intdiv($remaining, max(1, $typical)));
    }

    /**
     * How many downloads fit in the disk budget right now.
     *
     * A zero percentage keeps the fixed concurrency from the panel; otherwise
     * the count is derived from what is left of the budget, capped by the
     * panel's maximum. Zero means the pool is stopped until uploads drain.
     */
    public static function desiredDownloads(int $max): int
    {
        $max = max(0, $max);

        if (self::percent() <= 0) {
            return $max;
        }

        // Without the ceiling on purpose: the pool exists to finish what is
        // already on disk as much as to start new files, and a zero here stops
        // every worker, so nothing would ever upload and drain the backlog.
        return self::slots(self::budgetBytes(false), self::ingestBytes(), self::typicalBytes(), $max);
    }

    /** @return array{free:int,percent:int,budget:int,ingest:int,downloads:int} */
    public static function status(int $max): array
    {
        return [
            'free' => self::freeBytes(),
            'percent' => self::percent(),
            // The budget the download count is derived from, so the panel
            // cannot show "budget used up" next to a positive slot count.
            'budget' => self::budgetBytes(false),
            'ingest' => self::ingestBytes(),
            'downloads' => self::desiredDownloads($max),
        ];
    }
}
