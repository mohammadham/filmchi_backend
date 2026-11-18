<?php

namespace App\Console\Commands;

use App\ContentSource;
use App\EpisodeSource;
use App\Services\Relay\SourceRequeuer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Put rows left behind by older failures back on the download queue.
 *
 * Nothing is touched without --apply. A row is only picked up when it has been
 * idle for --older-than minutes, so a retry the queue still has scheduled is
 * never duplicated, and the queue itself is inspected to skip ids that already
 * have a waiting job (two jobs on one source would write the same .part file).
 */
class RelayRequeueStale extends Command
{
    /** @var string */
    protected $signature = 'relay:requeue-stale
        {--status=failed,retrying : Ingest states to pick up}
        {--older-than=60 : Only rows untouched for this many minutes}
        {--limit=25 : Maximum rows in one run}
        {--error-like= : Only rows whose ingest_error contains this text}
        {--exclude-error-like= : Skip rows whose ingest_error contains this text}
        {--apply : Dispatch the jobs; without it the command only reports}';

    /** @var string */
    protected $description = 'Requeue old failed and stale retrying sources in batches (dry run by default)';

    public function handle(SourceRequeuer $requeuer): int
    {
        $statuses = array_values(array_intersect(
            array_values(array_filter(array_map('trim', explode(',', (string) $this->option('status'))))),
            ['failed', 'retrying']
        ));

        if ($statuses === []) {
            $this->error('--status must contain failed and/or retrying.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subMinutes(max(0, (int) $this->option('older-than')));
        $apply = (bool) $this->option('apply');

        $rows = $this->candidates($statuses, $cutoff, $limit);

        if ($rows->isEmpty()) {
            $this->info('No rows matched.');

            return self::SUCCESS;
        }

        $queued = $this->queuedSourceKeys();

        if ($queued === null) {
            $this->warn('The queue could not be inspected; relying on timestamps alone.');
        }

        $queued = $queued ?? [];
        $ready = [];
        $skipped = [];

        foreach ($rows as $row) {
            $pair = $row->sourceType . ':' . $row->id;

            if (isset($queued[$pair])) {
                $skipped[] = $pair . ' already has a waiting job';

                continue;
            }

            // A retrying row whose own retry is still ahead of it is healthy.
            if ($row->status === 'retrying'
                && $row->nextRetryAt !== null
                && $row->nextRetryAt->gt(now()->subMinutes(5))) {
                $skipped[] = $pair . ' retry still scheduled for ' . $row->nextRetryAt->toDateTimeString();

                continue;
            }

            $rejection = $requeuer->rejection($row->sourceType, $row->id, true);

            if ($rejection !== null) {
                $skipped[] = $pair . ' ' . $rejection;

                continue;
            }

            $ready[] = $row;
        }

        $this->table(['Status', 'Source', 'Error'], array_map(function ($row) {
            return [
                $row->status,
                $row->sourceType . ':' . $row->id,
                mb_substr($row->error, 0, 60),
            ];
        }, array_slice($ready, 0, 20)));

        if (count($ready) > 20) {
            $this->line('... and ' . (count($ready) - 20) . ' more');
        }

        if ($skipped !== []) {
            $this->warn(count($skipped) . ' row(s) skipped:');

            foreach (array_slice($skipped, 0, 10) as $reason) {
                $this->line('  - ' . $reason);
            }
        }

        if (!$apply) {
            $this->info(count($ready) . ' row(s) would be requeued. Re-run with --apply to dispatch.');

            return self::SUCCESS;
        }

        $done = 0;

        foreach ($ready as $row) {
            $result = $requeuer->requeue($row->sourceType, $row->id, true);

            if ($result['ok']) {
                $done++;

                continue;
            }

            $this->warn($row->sourceType . ':' . $row->id . ' ' . $result['message']);
        }

        $this->info($done . ' row(s) requeued with fresh retry counters.');

        return self::SUCCESS;
    }

    /**
     * Rows in the requested states that have been idle past the cutoff.
     *
     * @param  string[]  $statuses
     * @return Collection<int, object>
     */
    protected function candidates(array $statuses, $cutoff, int $limit): Collection
    {
        $rows = collect();

        foreach ([[EpisodeSource::class, 'episode'], [ContentSource::class, 'content']] as [$model, $type]) {
            $table = (new $model())->getTable();

            if (!Schema::hasTable($table)) {
                continue;
            }

            $query = $model::query()
                ->whereIn('ingest_status', $statuses)
                ->where('updated_at', '<=', $cutoff);

            if ((string) $this->option('error-like') !== '') {
                $query->where('ingest_error', 'like', '%' . (string) $this->option('error-like') . '%');
            }

            if ((string) $this->option('exclude-error-like') !== '') {
                $exclude = (string) $this->option('exclude-error-like');
                $query->where(function ($inner) use ($exclude) {
                    $inner->whereNull('ingest_error')
                        ->orWhere('ingest_error', 'not like', '%' . $exclude . '%');
                });
            }

            foreach ($query->orderBy('updated_at')->limit($limit)->get() as $row) {
                $rows->push((object) [
                    'sourceType' => $type,
                    'id' => (int) $row->id,
                    'status' => (string) $row->ingest_status,
                    'nextRetryAt' => $row->ingest_next_retry_at,
                    'error' => (string) $row->ingest_error,
                ]);
            }
        }

        return $rows->take($limit)->values();
    }

    /**
     * Ids that already have a job waiting in the queue.
     *
     * A bulk requeue must never dispatch a second job for a source whose job is
     * still queued or delayed: both would download into the same .part file.
     *
     * @return array<string, true>|null null when the queue cannot be inspected
     */
    protected function queuedSourceKeys(): ?array
    {
        if ((string) config('queue.default') !== 'redis') {
            return null;
        }

        $queue = (string) config('media_relay.queue.name', 'default');
        $found = [];

        try {
            foreach (["queues:{$queue}", "queues:{$queue}:delayed", "queues:{$queue}:reserved"] as $key) {
                foreach ($this->payloads($key) as $payload) {
                    if (preg_match('/"sourceType";s:\d+:"(episode|content)";/', $payload, $type)
                        && preg_match('/"sourceId";i:(\d+);/', $payload, $id)) {
                        $found[$type[1] . ':' . (int) $id[1]] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $found;
    }

    /**
     * Raw members of one queue key, whatever data type redis holds it as.
     *
     * @return string[]
     */
    protected function payloads(string $key): array
    {
        try {
            // Waiting jobs are lists, delayed and reserved jobs are sorted sets.
            $members = Redis::zrange($key, 0, -1);
        } catch (\Throwable $e) {
            try {
                $members = Redis::lrange($key, 0, -1);
            } catch (\Throwable $e) {
                return [];
            }
        }

        return array_map('strval', (array) $members);
    }
}
