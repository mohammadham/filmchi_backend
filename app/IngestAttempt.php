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

    public function source()
    {
        return $this->source_type === 'content'
            ? ContentSource::find($this->source_id)
            : EpisodeSource::find($this->source_id);
    }
}
