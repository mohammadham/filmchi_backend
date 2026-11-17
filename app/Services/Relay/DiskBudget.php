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
 * phases: new downloads may only start while their expected bytes fit inside
 * the configured percentage of the disk that is free right now.
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

    public static function totalBytes(): int
    {
        $total = @disk_total_space(storage_path('app/ingest'));

        if ($total === false) {
            $total = @disk_total_space(storage_path());
        }

        return max(0, (int) $total);
    }

    /** Bytes currently held by downloaded or pending-upload .part files. */
    public static function ingestBytes(): int
    {
        $bytes = 0;

        foreach (glob(storage_path('app/ingest/*.part')) ?: [] as $file) {
            $size = @filesize($file);

            if ($size !== false) {
                $bytes += (int) $size;
            }
        }

        return $bytes;
    }

    /** The disk budget available for new ingest files, in bytes. */
    public static function budgetBytes(): int
    {
        $percent = self::percent();

        if ($percent <= 0) {
            return 0;
        }

        return (int) floor(self::freeBytes() * $percent / 100);
    }

    /**
     * Typical size of a file in this relay, used to turn a byte budget into a
     * slot count. Median is deliberate: one huge file must not make every
     * other file wait.
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
                    $size = @filesize($file);

                    if ($size !== false && $size > 0) {
                        $sizes[] = (int) $size;
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
     * How many downloads fit in the disk budget right now.
     *
     * A zero percentage keeps the fixed concurrency from the panel; otherwise
     * the count is derived from free space and the typical file size, capped
     * by the panel's maximum.
     */
    public static function desiredDownloads(int $max): int
    {
        $max = max(1, $max);

        if (self::percent() <= 0) {
            return $max;
        }

        $budget = self::budgetBytes();
        $typical = max(1, self::typicalBytes());

        if ($budget <= 0) {
            return 1;
        }

        return max(1, min($max, (int) floor($budget / $typical)));
    }

    /** @return array{free:int,total:int,percent:int,budget:int,ingest:int,typical:int,downloads:int} */
    public static function status(int $max): array
    {
        return [
            'free' => self::freeBytes(),
            'total' => self::totalBytes(),
            'percent' => self::percent(),
            'budget' => self::budgetBytes(),
            'ingest' => self::ingestBytes(),
            'typical' => self::typicalBytes(),
            'downloads' => self::desiredDownloads($max),
        ];
    }
}
