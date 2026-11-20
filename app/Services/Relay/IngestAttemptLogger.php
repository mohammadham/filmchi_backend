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
    public function record($source, string $outcome, array $context = []): ?IngestAttempt
    {
        // A partial deploy (code before migration) must keep ingesting, so a
        // missing table costs the history row, never the download.
        if (!Schema::hasTable('ingest_attempt_log')) {
            return null;
        }

        $workers = [];

        foreach ((array) ($context['workers'] ?? []) as $workerId) {
            $workerId = (int) $workerId;

            if ($workerId > 0) {
                $workers[$workerId] = $workerId;
            }
        }

        $startedAt = $source->ingest_started_at ?? null;
        $finishedAt = now();

        // Not every source model casts the timestamps; a raw string from the
        // database must not turn a history row into an exception.
        $durationMs = null;

        if ($startedAt instanceof \DateTimeInterface) {
            $durationMs = max(0, (int) \Carbon\Carbon::instance($startedAt)->diffInMilliseconds($finishedAt));
        }

        $row = IngestAttempt::create([
            'source_type' => $source->getTable() === 'content_sources' ? 'content' : 'episode',
            'source_id' => (int) $source->getKey(),
            'attempt_no' => max(0, (int) ($context['attempt_no'] ?? $source->ingest_attempts ?? 0)),
            'outcome' => $outcome,
            'bytes' => max(0, (int) ($context['bytes'] ?? $source->ingest_bytes ?? 0)),
            'route' => $workers === [] ? null : implode(',', array_values($workers)),
            'error' => $this->errorText($context['error'] ?? $source->ingest_error ?? null),
            'duration_ms' => $durationMs,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        $this->prune($row->source_type, (int) $row->source_id);

        return $row;
    }

    /**
     * @return \Illuminate\Support\Collection<int, IngestAttempt>
     */
    public function history($source, int $limit = 20)
    {
        if (!Schema::hasTable('ingest_attempt_log')) {
            return collect();
        }

        return IngestAttempt::query()
            ->where('source_type', $source->getTable() === 'content_sources' ? 'content' : 'episode')
            ->where('source_id', (int) $source->getKey())
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    protected function errorText($error): ?string
    {
        $error = trim((string) $error);

        return $error === '' ? null : Str::limit($error, 1000);
    }

    /** Keep the newest rows per source so one dead link cannot grow forever. */
    protected function prune(string $sourceType, int $sourceId): void
    {
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
