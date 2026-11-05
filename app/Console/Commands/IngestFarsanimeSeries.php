<?php

namespace App\Console\Commands;

use App\Content;
use App\Episode;
use App\Jobs\IngestUrlJob;
use App\Season;
use Illuminate\Console\Command;

/**
 * Pulls a stored series into the combined storage: every selected episode
 * source is downloaded once by the worker, uploaded to Telegram Drive in the
 * series' folder and swapped for a relay link. The 9drive copy is created in
 * the same folder when the file is promoted on demand.
 *
 * Use a small --episodes value first: one episode of a 1080p donghua is
 * roughly 1 GB, and the queue is processed serially by one worker.
 */
class IngestFarsanimeSeries extends Command
{
    protected $signature = 'farsanime:ingest
        {content : Content id or exact title}
        {--quality=480p : Quality to pull (480p, 720p, 1080p)}
        {--episodes=1 : First N episodes per season (0 = every episode)}
        {--season= : Only this season id}
        {--dry-run : List what would be queued}';

    protected $description = 'Queue the relay ingestion of a series, into its per-title storage folder';

    public function handle()
    {
        $needle = (string) $this->argument('content');

        $content = ctype_digit($needle)
            ? Content::find((int) $needle)
            : Content::where('title', $needle)->first();

        if (!$content) {
            $this->error('No content matched "' . $needle . '".');

            return 1;
        }

        $quality = (string) $this->option('quality');
        $limit = (int) $this->option('episodes');
        $seasonId = $this->option('season');
        $dry = (bool) $this->option('dry-run');

        $seasons = Season::where('content_id', $content->id)
            ->when($seasonId, function ($q) use ($seasonId) {
                $q->where('id', (int) $seasonId);
            })
            ->orderBy('id')
            ->get();

        if ($seasons->isEmpty()) {
            $this->warn('This content has no seasons.');

            return 0;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($seasons as $season) {
            $episodes = Episode::where('season_id', $season->id)
                ->orderBy('number')
                ->when($limit > 0, function ($q) use ($limit) {
                    $q->limit($limit);
                })
                ->get();

            foreach ($episodes as $episode) {
                $source = $episode->sources()
                    ->where('quality', $quality)
                    ->where('provider', 'Farsanime')
                    ->first();

                if (!$source) {
                    $skipped++;
                    continue;
                }

                if (str_starts_with((string) $source->source, url('/'))) {
                    $skipped++;
                    continue;
                }

                if ($dry) {
                    $this->line(sprintf(
                        '[dry] %s S%dE%02d %s %.0f MB -> %s',
                        $content->title,
                        $season->id,
                        $episode->number,
                        $quality,
                        $this->sizeInMb($source->size),
                        $content->storage_folder ?: $content->title
                    ));
                    $queued++;
                    continue;
                }

                $source->ingest_status = 'queued';
                $source->ingest_progress = 0;
                $source->ingest_error = null;
                $source->save();

                IngestUrlJob::dispatch(
                    'episode',
                    $source->id,
                    (string) $source->source,
                    $this->nameFor($content->title, $season, $episode, $source->source)
                );

                $queued++;
            }
        }

        $this->info(sprintf('%d job(s) queued, %d skipped.', $queued, $skipped));

        return 0;
    }

    protected function nameFor(string $title, Season $season, Episode $episode, string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'mkv';
        $seasonNumber = (int) preg_replace('/\D+/', '', (string) $season->title) ?: $season->id;

        return sprintf('%s - S%02dE%02d.%s', $title, $seasonNumber, $episode->number, $extension);
    }

    protected function sizeInMb(?string $size): float
    {
        if (!$size || !preg_match('/[\d.]+/', $size, $m)) {
            return 0;
        }

        return (float) $m[0];
    }
}
