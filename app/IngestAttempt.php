<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * One finished ingest attempt for an episode/content source.
 *
 * Written by App\Services\Relay\IngestAttemptLogger; the source row keeps only
 * the live counters, which a requeue resets.
 */
class IngestAttempt extends Model
{
    public const OUTCOME_DOWNLOADED = 'downloaded';

    public const OUTCOME_RETRYING = 'retrying';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_DEFERRED = 'deferred';

    public const OUTCOME_QUARANTINED = 'quarantined';

    public const OUTCOME_REQUEUED = 'requeued';

    protected $table = 'ingest_attempt_log';

    protected $guarded = [];

    protected $casts = [
        'attempt_no' => 'integer',
        'bytes' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * The newest attempts for a set of "type:id" keys.
     *
     * The read is capped at $perSource × sources, so a panel list never loads
     * a long history: the newest rows of every listed source always sit in the
     * top of that slice.
     *
     * @param  array<int,string>  $keys
     * @return array<string, array<int, IngestAttempt>>
     */
    public static function latestPerSource(array $keys, int $perSource = 3): array
    {
        $ids = ['episode' => [], 'content' => []];

        foreach ($keys as $key) {
            [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, null);

            if (isset($ids[$type]) && (int) $id > 0) {
                $ids[$type][] = (int) $id;
            }
        }

        $ids = array_filter($ids);

        if ($ids === []) {
            return [];
        }

        $rows = static::query()
            ->where(function ($query) use ($ids) {
                foreach ($ids as $type => $sourceIds) {
                    $query->orWhere(function ($inner) use ($type, $sourceIds) {
                        $inner->where('source_type', $type)->whereIn('source_id', $sourceIds);
                    });
                }
            })
            ->orderByDesc('id')
            ->limit($perSource * array_sum(array_map('count', $ids)))
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $key = $row->source_type . ':' . $row->source_id;

            if (count($grouped[$key] ?? []) < $perSource) {
                $grouped[$key][] = $row;
            }
        }

        return $grouped;
    }
}
