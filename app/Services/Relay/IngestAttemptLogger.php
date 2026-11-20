<?php

namespace App\Services\Relay;

use App\IngestAttempt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Keeps the permanent record of ingest attempts.
 *
 * The counters on the source row are disposable by design: a requeue resets
 * them so the retry policy applies again. This logger writes what the row went
 * through to a separate table, so resetting the counters never erases the
 * attempts, their bytes, their Workers, or why they failed.
 */
class IngestAttemptLogger
{
    public function record($source, string $outcome, array $context = []): void
    {
        // A partial deploy (code before migration) must keep ingesting, so a
        // missing table costs the history row, never the download.
        if (!Schema::hasTable('ingest_attempt_log')) {
            return;
        }

        // A quarantined attempt is judged on accumulated evidence (several
        // attempts over several Workers), so the whole evidence wins over this
        // attempt's routes alone.
        $workerIds = $outcome === IngestAttempt::OUTCOME_QUARANTINED
            ? ($source->ingest_zero_workers ?: ($context['workers'] ?? []))
            : ($context['workers'] ?? []);

        $workers = array_values(array_unique(array_filter(array_map('intval', (array) $workerIds))));
        $startedAt = $source->ingest_started_at ?? null;
        $error = trim((string) ($context['error'] ?? $source->ingest_error ?? ''));
        $finishedAt = now();
        $sourceType = $source->getTable() === 'content_sources' ? 'content' : 'episode';
        $sourceId = (int) $source->getKey();

        IngestAttempt::create([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'attempt_no' => max(0, (int) ($context['attempt_no'] ?? $source->ingest_attempts ?? 0)),
            'outcome' => $outcome,
            'bytes' => max(0, (int) ($context['bytes'] ?? $source->ingest_bytes ?? 0)),
            'route' => $workers === [] ? null : implode(',', $workers),
            'error' => $error === '' ? null : Str::limit($error, 1000),
            // Not every source model casts its timestamps; a raw string from
            // the database must not turn a history row into an exception.
            'duration_ms' => $startedAt instanceof \DateTimeInterface
                ? max(0, (int) \Carbon\Carbon::instance($startedAt)->diffInMilliseconds($finishedAt))
                : null,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        // Keep the newest rows per source so one dead link cannot grow forever.
        $keep = max(1, (int) config('media_relay.ingest.history_limit', 100));

        $cutoff = IngestAttempt::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderByDesc('id')
            ->skip($keep - 1)
            ->value('id');

        if ($cutoff) {
            IngestAttempt::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('id', '<', $cutoff)
                ->delete();
        }
    }
}
