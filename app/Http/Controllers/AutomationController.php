<?php

namespace App\Http\Controllers;

use App\Content;
use App\Episode;
use App\EpisodeSource;
use App\Jobs\IngestUrlJob;
use App\Season;
use Illuminate\Http\Request;
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
 */
class AutomationController extends Controller
{
    /** Qualities in the order the catalogue grows: small files first. */
    protected const QUALITY_ORDER = ['480p', '720p', '1080p', '360p', '2160p'];

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
            ->get(['id', 'quality', 'ingest_status', 'ingest_progress', 'ingest_error', 'updated_at'])
            ->map(function (EpisodeSource $source) {
                return [
                    'source_id' => (int) $source->id,
                    'quality' => (string) $source->quality,
                    'status' => (string) $source->ingest_status,
                    'progress' => (int) $source->ingest_progress,
                    'error' => $source->ingest_error ? mb_substr((string) $source->ingest_error, 0, 180) : null,
                    'updated_at' => optional($source->updated_at)->toIso8601String(),
                ];
            })
            ->all();

        return response()->json([
            'ok' => true,
            'pending' => $this->pendingQuery()->count(),
            'queued' => (int) ($counts['queued'] ?? 0),
            'active' => (int) ($counts['downloading'] ?? 0) + (int) ($counts['uploading'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'current' => $active,
            'disk_free_gb' => $this->diskFreeGb(),
            'min_free_gb' => (int) config('media_relay.automation.min_free_gb', 25),
            'queue_depth' => $this->queueDepth(),
        ]);
    }

    public function queue(Request $request)
    {
        $maxBatch = max(1, (int) config('media_relay.automation.max_batch', 3));
        $limit = (int) $request->input('limit', 1);
        $limit = max(1, min($maxBatch, $limit));

        $freeGb = $this->diskFreeGb();
        $minGb = (int) config('media_relay.automation.min_free_gb', 25);

        if ($freeGb !== null && $freeGb < $minGb) {
            return response()->json([
                'ok' => false,
                'reason' => 'disk_low',
                'message' => sprintf('Only %.1f GB free; the automation floor is %d GB.', $freeGb, $minGb),
                'disk_free_gb' => $freeGb,
                'min_free_gb' => $minGb,
            ], 409);
        }

        // One download at a time is what the worker does anyway; refuse to grow
        // the queue beyond a small buffer so a stuck file cannot pile up work.
        $queued = EpisodeSource::where('ingest_status', 'queued')->count();
        $active = EpisodeSource::whereIn('ingest_status', ['downloading', 'uploading'])->count();

        if ($queued + $active >= $maxBatch) {
            return response()->json([
                'ok' => true,
                'queued' => [],
                'skipped' => 'queue_busy',
                'pending' => $this->pendingQuery()->count(),
            ], 202);
        }

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

        return response()->json([
            'ok' => true,
            'queued' => $dispatched,
            'skipped' => $skipped,
            'pending' => $this->pendingQuery()->count(),
            'disk_free_gb' => $freeGb,
        ]);
    }

    /**
     * Sources still waiting for their Telegram Drive copy: remote links that
     * have never been ingested. Ordered series → quality → season → episode so
     * one season finishes before the next quality starts, exactly like the
     * catalogue is meant to grow.
     */
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
