<?php

namespace App\Http\Controllers;

use App\Content;
use App\Episode;
use App\EpisodeSource;
use App\Jobs\IngestUrlJob;
use App\Services\Relay\UploadMetrics;
use App\Season;
use App\Console\Commands\SyncFarsanimeCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Machine-facing endpoints used by the n8n orchestrator.
 *
 * The workflow asks for the catalogue state (status) and then asks this API to
 * queue the next slice of episode sources (queue). Nothing is downloaded here:
 * the job goes on the Redis queue and the single worker pulls files one at a
 * time, so the workflow cannot accidentally shred the server's bandwidth.
 *
 * Files are stored on Telegram Drive only. The 9drive copy is created later by
 * the demand-based promotion policy (RELAY_PROMOTE_THRESHOLD), never here.
 *
 * The catalogue endpoint keeps the list itself fresh: new series, new seasons
 * and new episodes/qualities are discovered by the importer, which runs as a
 * detached CLI pass so it never blocks the download queue.
 */
class AutomationController extends Controller
{
    /** Qualities in the order the catalogue grows: small files first. */
    protected const QUALITY_ORDER = ['480p', '720p', '1080p', '360p', '2160p'];

    /**
     * A downloading/uploading row is updated every few percent, so a row that
     * has not moved for this long belongs to a worker that was restarted (a
     * deploy, an OOM kill). Such a row is released instead of blocking the
     * workflow forever.
     */
    protected const STALE_MINUTES = 20;

    /** A catalogue pass that has not reported progress for this long is dead. */
    protected const CATALOGUE_STALE_MINUTES = 15;

    public function status()
    {
        $counts = EpisodeSource::query()
            ->selectRaw('ingest_status as status, count(*) as total')
            ->groupBy('ingest_status')
            ->pluck('total', 'status')
            ->all();

        $active = EpisodeSource::whereIn('ingest_status', ['downloading', 'uploading'])
            ->orderBy('updated_at')
            ->limit(5)
            ->get(['id', 'quality', 'ingest_status', 'ingest_progress', 'ingest_error', 'upload_status', 'upload_started_at', 'upload_finished_at', 'upload_bytes', 'upload_duration_ms', 'upload_speed_bps', 'upload_attempts', 'updated_at'])
            ->map(function (EpisodeSource $source) {
                return [
                    'source_id' => (int) $source->id,
                    'quality' => (string) $source->quality,
                    'status' => (string) $source->ingest_status,
                    'progress' => (int) $source->ingest_progress,
                    'error' => $source->ingest_error ? mb_substr((string) $source->ingest_error, 0, 180) : null,
                    'updated_at' => optional($source->updated_at)->toIso8601String(),
                    'upload_status' => $source->upload_status,
                    'upload_started_at' => optional($source->upload_started_at)->toIso8601String(),
                    'upload_finished_at' => optional($source->upload_finished_at)->toIso8601String(),
                    'upload_bytes' => (int) $source->upload_bytes,
                    'upload_duration_ms' => $source->upload_duration_ms ? (int) $source->upload_duration_ms : null,
                    'upload_speed_bps' => $source->upload_speed_bps ? (int) $source->upload_speed_bps : null,
                    'upload_attempts' => (int) $source->upload_attempts,
                ];
            })
            ->all();

        $pending = $this->pendingQuery()->count();
        $queued = (int) ($counts['queued'] ?? 0);
        $activeCount = $this->freshActive()->count();
        $freeGb = $this->diskFreeGb();
        $minGb = (int) config('media_relay.automation.min_free_gb', 25);
        $maxBatch = max(1, (int) config('media_relay.automation.max_batch', 3));
        $targetDepth = max(1, min($maxBatch, (int) config('media_relay.automation.target_depth', 2)));
        $diskOk = $freeGb === null || $freeGb >= $minGb;
        $capacity = max(0, $targetDepth - $activeCount - $queued);
        $recommended = $diskOk ? min($maxBatch, $capacity) : 0;

        return response()->json([
            'ok' => true,
            'pending' => $pending,
            'queued' => $queued,
            'active' => $activeCount,
            'stale' => $this->staleActive()->count(),
            'completed' => (int) ($counts['completed'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'failed_retryable' => $this->failedQuery()->count(),
            'current' => $active,
            'disk_free_gb' => $freeGb,
            'min_free_gb' => $minGb,
            'target_depth' => $targetDepth,
            'capacity' => $diskOk ? $capacity : 0,
            'recommended_batch' => $recommended,
            'accepting' => $recommended > 0,
            'retry_after_s' => $recommended > 0 ? 0 : 60,
            'queue_depth' => $this->queueDepth(),
            'upload' => UploadMetrics::summary(EpisodeSource::class),
            'catalogue' => $this->catalogueState(),
        ]);
    }

    /**
     * Start a catalogue pass when one is due.
     *
     * The pass adds new series, new seasons and new episodes/qualities to the
     * same tables the queue reads, so the next `queue` call naturally continues
     * with whatever the pass just found. `force` ignores the interval.
     */
    public function catalogue(Request $request)
    {
        $state = $this->catalogueState();

        if ($state['running']) {
            return response()->json([
                'ok' => true,
                'started' => false,
                'reason' => 'already_running',
                'catalogue' => $state,
            ], 202);
        }

        if (!$request->boolean('force') && !$state['due']) {
            return response()->json([
                'ok' => true,
                'started' => false,
                'reason' => 'not_due',
                'catalogue' => $state,
            ], 202);
        }

        // Two orchestrator ticks must never spawn two passes.
        if (!Cache::add('automation:catalogue:spawning', true, now()->addMinutes(3))) {
            return response()->json([
                'ok' => true,
                'started' => false,
                'reason' => 'spawning',
                'catalogue' => $state,
            ], 202);
        }

        // setsid puts the pass in its own session, so a recycled php-fpm worker
        // (or a request that ends) can no longer take it down with its group.
        $log = storage_path('logs/catalogue-sync.log');
        $setsid = @is_executable('/usr/bin/setsid') ? 'setsid ' : '';
        $command = sprintf(
            'nohup %s%s %s farsanime:sync --sleep=%d >> %s 2>&1 &',
            $setsid,
            escapeshellarg((string) env('PHP_CLI_BINARY', 'php')),
            escapeshellarg(base_path('artisan')),
            (int) config('media_relay.automation.catalogue.sleep_ms', 250),
            escapeshellarg($log)
        );

        try {
            @exec($command);
        } catch (Throwable $e) {
            Cache::forget('automation:catalogue:spawning');

            return response()->json([
                'ok' => false,
                'started' => false,
                'reason' => 'spawn_failed',
                'message' => mb_substr($e->getMessage(), 0, 200),
                'catalogue' => $state,
            ], 500);
        }

        // Let the child take the lock so the answer reflects reality instead of
        // a spawn attempt the caller has to poll for.
        usleep(2000000);

        $state = $this->catalogueState();

        return response()->json([
            'ok' => true,
            'started' => $state['running'],
            'reason' => $state['running'] ? 'started' : 'spawn_unconfirmed',
            'catalogue' => $state,
        ], 202);
    }

    public function queue(Request $request)
    {
        $maxBatch = max(1, (int) config('media_relay.automation.max_batch', 3));
        $targetDepth = max(1, min($maxBatch, (int) config('media_relay.automation.target_depth', 2)));
        $limit = (int) $request->input('limit', 1);
        $limit = max(1, min($targetDepth, $limit));

        $freeGb = $this->diskFreeGb();
        $minGb = (int) config('media_relay.automation.min_free_gb', 25);

        if ($freeGb !== null && $freeGb < $minGb) {
            return response()->json([
                'ok' => false,
                'reason' => 'disk_low',
                'message' => sprintf('Only %.1f GB free; the automation floor is %d GB.', $freeGb, $minGb),
                'disk_free_gb' => $freeGb,
                'min_free_gb' => $minGb,
                'target_depth' => $targetDepth,
                'capacity' => 0,
                'recommended_batch' => 0,
            ], 409);
        }

        // A worker killed mid-download never flips its row back, so release the
        // abandoned rows first; otherwise the guard below would block forever.
        $released = 0;

        foreach ($this->staleActive()->get() as $stale) {
            $stale->ingest_status = 'pending';
            $stale->ingest_error = 'released: the worker stopped mid-transfer';
            $stale->save();
            $released++;
        }

        // One download at a time is what the worker does anyway; refuse to grow
        // the queue beyond a small buffer so a stuck file cannot pile up work.
        $queued = EpisodeSource::where('ingest_status', 'queued')->count();
        $active = $this->freshActive()->count();
        $capacity = max(0, $targetDepth - $queued - $active);

        if ($capacity <= 0) {
            return response()->json([
                'ok' => true,
                'queued' => [],
                'skipped' => 'queue_busy',
                'pending' => $this->pendingQuery()->count(),
                'target_depth' => $targetDepth,
                'capacity' => 0,
                'recommended_batch' => 0,
                'retry_after_s' => 60,
            ], 202);
        }

        $limit = min($limit, $capacity);

        $sources = $this->pendingQuery()
            ->limit($limit)
            ->get();

        $dispatched = [];
        $skipped = 0;

        foreach ($sources as $source) {
            $episode = Episode::find($source->episode_id);

            if (!$episode) {
                $skipped++;
                continue;
            }

            $content = $this->contentOf($episode);

            if (!$content) {
                $skipped++;
                continue;
            }

            $source->ingest_status = 'queued';
            $source->ingest_progress = 0;
            $source->ingest_error = null;
            $source->save();

            IngestUrlJob::dispatch(
                'episode',
                (int) $source->id,
                (string) $source->source,
                $this->nameFor($content, $episode, (string) $source->source)
            );

            $dispatched[] = [
                'source_id' => (int) $source->id,
                'content' => (string) $content->title,
                'quality' => (string) $source->quality,
            ];
        }

        $remainingCapacity = max(
            0,
            $targetDepth
                - EpisodeSource::where('ingest_status', 'queued')->count()
                - $this->freshActive()->count()
        );

        return response()->json([
            'ok' => true,
            'queued' => $dispatched,
            'skipped' => $skipped,
            'pending' => $this->pendingQuery()->count(),
            'released_stale' => $released,
            'disk_free_gb' => $freeGb,
            'target_depth' => $targetDepth,
            'capacity' => $remainingCapacity,
            'recommended_batch' => $remainingCapacity,
        ]);
    }

    /**
     * Manually put one failed pre-upload source back on the queue.
     *
     * Tdrive failures are retried in the Tdrive panel; this endpoint only
     * handles rows that failed before they ever reached an upload job.
     */
    public function requeueSource(Request $request)
    {
        $validated = $request->validate([
            'source_id' => 'required|integer',
        ]);

        $source = EpisodeSource::find((int) $validated['source_id']);

        if (!$source) {
            return back()->with('error', 'منبع پیدا نشد.');
        }

        if (!in_array((string) $source->ingest_status, ['failed', ''], true)) {
            return back()->with('error', 'این منبع در وضعیت فعال است و دوباره صف نمی‌شود.');
        }

        if ($source->upload_status === UploadMetrics::STATUS_FAILED) {
            return back()->with('error', 'این فایل در مرحله آپلود شکست خورده؛ آن را از پنل Tdrive دوباره تلاش کنید.');
        }

        if (Schema::hasColumn('episode_sources', 'is_blocked') && (bool) $source->is_blocked) {
            return back()->with('error', 'این منبع بلاک شده و دوباره صف نمی‌شود.');
        }

        if (!str_starts_with((string) $source->source, 'http')) {
            return back()->with('error', 'این منبع دیگر یک لینک HTTP معتبر نیست.');
        }

        $episode = Episode::find($source->episode_id);
        $content = $episode ? $this->contentOf($episode) : null;

        if (!$episode || !$content) {
            return back()->with('error', 'قسمت یا عنوان مربوط به این منبع پیدا نشد.');
        }

        $source->ingest_status = 'queued';
        $source->ingest_progress = 0;
        $source->ingest_error = null;
        $source->upload_status = null;
        $source->upload_attempts = 0;
        $source->save();

        IngestUrlJob::dispatch(
            'episode',
            (int) $source->id,
            (string) $source->source,
            $this->nameFor($content, $episode, (string) $source->source)
        );

        return back()->with('success', 'منبع دوباره به صف اضافه شد.');
    }

    /**
     * A pass that a deploy (or a kill) took down leaves its flag behind. Clear
     * it and schedule the retry an hour later, so a crash loop cannot turn into
     * a pass every ten minutes.
     */
    protected function reapDeadPass(): void
    {
        $flagged = (bool) Cache::get(SyncFarsanimeCatalogue::RUNNING_KEY);
        $heartbeat = Cache::get(SyncFarsanimeCatalogue::HEARTBEAT_KEY);

        // The lock, not the cache flag, says whether a process is alive: a
        // flushed cache must not make a running pass look dead (or the other
        // way around).
        $lock = Cache::lock(SyncFarsanimeCatalogue::LOCK_KEY, 10);

        if (!$lock->get()) {
            $beat = $heartbeat ? Carbon::parse($heartbeat) : null;
            $alive = $beat && $beat->gt(now()->subMinutes(self::CATALOGUE_STALE_MINUTES));

            if ($alive) {
                if (!$flagged) {
                    Cache::put(SyncFarsanimeCatalogue::RUNNING_KEY, true, 7200);
                    Cache::put(SyncFarsanimeCatalogue::STARTED_KEY, $heartbeat, 7200);
                }

                return;
            }

            // A deploy kills the child without releasing its lock, and the lock
            // lives for two hours; releasing it here keeps a silent pass from
            // blocking the next one for the rest of the afternoon.
            Cache::lock(SyncFarsanimeCatalogue::LOCK_KEY)->forceRelease();
            $flagged = true;
            $heartbeat = $beat ? $beat->toIso8601String() : null;
        } else {
            $lock->release();
        }

        if (!$flagged) {
            return;
        }

        $intervalHours = max(1, (int) config('media_relay.automation.catalogue.interval_hours', 6));
        $diedAt = $heartbeat ? Carbon::parse($heartbeat) : now();

        Cache::put(
            SyncFarsanimeCatalogue::LAST_PASS_KEY,
            $diedAt->copy()->subHours($intervalHours - 1)->toIso8601String(),
            now()->addDays(30)
        );
        Cache::put(SyncFarsanimeCatalogue::LAST_EXIT_KEY, 'aborted', now()->addDays(30));
        Cache::forget(SyncFarsanimeCatalogue::RUNNING_KEY);
        Cache::forget(SyncFarsanimeCatalogue::STARTED_KEY);
        Cache::forget(SyncFarsanimeCatalogue::HEARTBEAT_KEY);
    }

    /**
     * Whether a catalogue pass is running, when the last one finished and when
     * the next one is due. A pass whose heartbeat stopped moving is treated as
     * dead (a deploy kills the detached child).
     */
    protected function catalogueState(): array
    {
        // Heal the flag against the pass lock before reporting it, so the
        // orchestrator never sees a dead pass as running (or a live one as
        // idle) just because the cache was flushed.
        $this->reapDeadPass();

        $intervalHours = max(1, (int) config('media_relay.automation.catalogue.interval_hours', 6));

        $running = (bool) Cache::get(SyncFarsanimeCatalogue::RUNNING_KEY);
        $heartbeat = Cache::get(SyncFarsanimeCatalogue::HEARTBEAT_KEY);
        $lastPass = Cache::get(SyncFarsanimeCatalogue::LAST_PASS_KEY);

        if ($running && $heartbeat) {
            $beat = Carbon::parse($heartbeat);

            if ($beat->lt(now()->subMinutes(self::CATALOGUE_STALE_MINUTES))) {
                $running = false;
            }
        }

        $next = $lastPass ? Carbon::parse($lastPass)->addHours($intervalHours) : null;

        return [
            'running' => $running,
            'started_at' => Cache::get(SyncFarsanimeCatalogue::STARTED_KEY),
            'heartbeat_at' => $heartbeat,
            'last_pass_at' => $lastPass,
            'last_exit_code' => Cache::get(SyncFarsanimeCatalogue::LAST_EXIT_KEY),
            'interval_hours' => $intervalHours,
            'due' => !$running && ($next === null || $next->isPast()),
            'next_run_at' => $next ? $next->toIso8601String() : null,
        ];
    }

    /**
     * Transfers that are still moving bytes right now.
     */
    protected function freshActive()
    {
        return EpisodeSource::whereIn('ingest_status', ['downloading', 'uploading'])
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_MINUTES));
    }

    /**
     * Transfers nobody is working on any more (restarted worker, killed job).
     */
    protected function staleActive()
    {
        return EpisodeSource::whereIn('ingest_status', ['downloading', 'uploading'])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES));
    }

    /**
     * Sources still waiting for their Telegram Drive copy: remote links that
     * have never been ingested. Ordered series → quality → season → episode so
     * one season finishes before the next quality starts, exactly like the
     * catalogue is meant to grow.
     */
    protected function failedQuery()
    {
        $query = EpisodeSource::query()
            ->where('episode_sources.ingest_status', 'failed')
            ->where('episode_sources.source', 'like', 'http%')
            ->where(function ($q) {
                $q->whereNull('episode_sources.upload_status')
                    ->orWhere('episode_sources.upload_status', '');
            });

        if (Schema::hasColumn('episode_sources', 'is_blocked')) {
            $query->where('episode_sources.is_blocked', false);
        }

        return $query;
    }

    protected function pendingQuery()
    {
        $qualityRank = 'CASE episode_sources.quality '
            . "WHEN '480p' THEN 1 WHEN '720p' THEN 2 WHEN '1080p' THEN 3 "
            . "WHEN '360p' THEN 0 WHEN '2160p' THEN 4 ELSE 9 END";

        $query = EpisodeSource::query()
            ->select('episode_sources.*')
            ->join('episodes', 'episodes.id', '=', 'episode_sources.episode_id')
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->join('contents', 'contents.id', '=', 'seasons.content_id')
            ->where('episode_sources.source', 'like', 'http%')
            ->where(function ($q) {
                $q->whereNull('episode_sources.ingest_status')
                    ->orWhere('episode_sources.ingest_status', 'pending');
            })
            ->orderBy('contents.id')
            ->orderByRaw($qualityRank)
            ->orderBy('seasons.id')
            ->orderBy('episodes.number');

        if (Schema::hasColumn('episode_sources', 'is_blocked')) {
            $query->where('episode_sources.is_blocked', false);
        }

        return $query;
    }

    protected function contentOf(Episode $episode): ?Content
    {
        $season = Season::find($episode->season_id);

        return $season ? Content::find($season->content_id) : null;
    }

    protected function nameFor(Content $content, Episode $episode, string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'mkv';
        $season = Season::find($episode->season_id);
        $seasonNumber = (int) preg_replace('/\D+/', '', (string) optional($season)->title) ?: (int) optional($season)->id;

        return sprintf('%s - S%02dE%02d.%s', $content->title, $seasonNumber, (int) $episode->number, $extension);
    }

    protected function diskFreeGb(): ?float
    {
        $free = @disk_free_space(storage_path());

        return $free === false ? null : round($free / 1073741824, 1);
    }

    protected function queueDepth(): ?int
    {
        try {
            return (int) Queue::size();
        } catch (Throwable $e) {
            return null;
        }
    }
}
