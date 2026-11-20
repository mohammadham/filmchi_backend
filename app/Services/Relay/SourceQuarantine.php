<?php

namespace App\Services\Relay;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Quarantines a source whose link is dead.
 *
 * A download that fails is not automatically a dead link: a Worker can be
 * asleep, a CDN can hiccup, and the same URL often works on the next attempt.
 * So the relay keeps evidence instead of guessing — how many attempts fetched
 * zero bytes, and which routes served them. Only when repeated zero-byte
 * attempts were spread over more than one route is the source itself blamed
 * and marked `link_status = dead`, which stops every requeue path until a
 * successful probe says the link answers again.
 *
 * Attempts that did write bytes are proof the link works: they clear the
 * evidence, so a source is never quarantined on a mix of failures and progress.
 */
class SourceQuarantine
{
    public function isQuarantined($source): bool
    {
        return strtolower((string) ($source->link_status ?? '')) === 'dead';
    }

    /** The panel-ready reason for a quarantined row, or null for a live one. */
    public function reasonFor($source): ?string
    {
        return $this->isQuarantined($source) ? $this->message($source) : null;
    }

    /**
     * Record one attempt that moved zero bytes.
     *
     * @param  array<int,int>  $workerIds  Workers that served the attempt (empty for a direct route).
     * @return int The number of consecutive zero-byte attempts so far.
     */
    public function noteZeroByteAttempt($source, array $workerIds, ?string $lastError = null): int
    {
        if (!$this->canStoreEvidence($source)) {
            return 0;
        }

        $workers = $this->workers($source);

        foreach ($workerIds as $workerId) {
            $workerId = (int) $workerId;

            if ($workerId > 0) {
                $workers[$workerId] = true;
            }
        }

        $attempts = (int) ($source->ingest_zero_attempts ?? 0) + 1;
        $source->ingest_zero_attempts = $attempts;
        $source->ingest_zero_workers = array_keys($workers);
        $source->save();

        return $attempts;
    }

    /** The link delivered bytes: the previous zero-byte attempts are stale. */
    public function clearEvidence($source): void
    {
        if (!$this->canStoreEvidence($source)) {
            return;
        }

        if ((int) ($source->ingest_zero_attempts ?? 0) === 0 && empty($source->ingest_zero_workers)) {
            return;
        }

        $source->ingest_zero_attempts = 0;
        $source->ingest_zero_workers = [];
        $source->save();
    }

    /**
     * Enough zero-byte evidence has piled up to blame the link.
     *
     * The attempt threshold is the first gate. A second gate only applies when
     * Workers were actually in play: one broken Worker must not condemn a URL
     * that the rest of the pool never tried, so a proxied source needs the
     * configured number of distinct Workers. A direct route has no Worker to
     * blame — the origin itself answered nothing — so repeated attempts are
     * proof enough.
     */
    public function shouldQuarantine($source): bool
    {
        $attempts = max(1, (int) config('media_relay.quarantine.zero_attempts', 2));

        if ((int) ($source->ingest_zero_attempts ?? 0) < $attempts) {
            return false;
        }

        $workers = $this->workers($source);

        if (empty($workers) || !app(WorkerRegistry::class)->isEligible((string) $source->source)) {
            return true;
        }

        return count($workers) >= max(1, (int) config('media_relay.quarantine.zero_workers', 2));
    }

    /** Mark the link dead and leave a panel-ready explanation on the row. */
    public function markDead($source, ?string $lastError = null): bool
    {
        if ($this->isQuarantined($source)) {
            return false;
        }

        $source->link_status = 'dead';
        $source->link_checked_at = now();
        $source->ingest_status = 'failed';
        $source->ingest_error = $this->message($source, $lastError);
        $source->ingest_next_retry_at = null;
        $source->ingest_connections = 0;
        $source->ingest_speed_bps = 0;
        $source->upload_status = null;
        $source->save();

        Log::warning('source quarantined: no route delivered a byte', [
            'source_table' => $source->getTable(),
            'source_id' => $source->getKey(),
            'zero_attempts' => (int) ($source->ingest_zero_attempts ?? 0),
            'workers' => array_keys($this->workers($source)),
        ]);

        return true;
    }

    public function message($source, ?string $lastError = null): string
    {
        $attempts = max(1, (int) ($source->ingest_zero_attempts ?? 1));
        $workers = count($this->workers($source));

        $route = $workers > 0
            ? sprintf('روی %d ورکر', $workers)
            : 'روی مسیر مستقیم';

        return Str::limit(sprintf(
            'منبع قرنطینه شد: در %d تلاش %s صفر بایت دریافت شد%s — تا probe موفق بعدی دوباره صف نمی‌شود.',
            $attempts,
            $route,
            $lastError ? ' (' . $lastError . ')' : ''
        ), 500);
    }

    /**
     * The columns ship with a migration; a worker that boots mid-deploy must
     * keep ingesting (without quarantine) instead of failing every save.
     */
    protected function canStoreEvidence($source): bool
    {
        $table = $source->getTable();

        // Not cached: a schema can be dropped and recreated between requests in
        // tests and during a partial deploy, and a stale answer here would
        // silently disable quarantine for the rest of the process.
        return Schema::hasColumn($table, 'ingest_zero_attempts')
            && Schema::hasColumn($table, 'ingest_zero_workers');
    }

    /** @return array<int,bool> Worker ids as keys, so a list stays unique. */
    protected function workers($source): array
    {
        $stored = $source->ingest_zero_workers ?? [];

        if (is_string($stored)) {
            $stored = json_decode($stored, true) ?: [];
        }

        $workers = [];

        foreach ((array) $stored as $workerId) {
            $workerId = (int) $workerId;

            if ($workerId > 0) {
                $workers[$workerId] = true;
            }
        }

        return $workers;
    }
}
