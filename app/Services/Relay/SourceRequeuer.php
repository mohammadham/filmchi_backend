<?php

namespace App\Services\Relay;

use App\Content;
use App\ContentSource;
use App\Episode;
use App\EpisodeSource;
use App\Jobs\IngestUrlJob;
use App\Season;
use Illuminate\Support\Facades\Schema;

/**
 * Puts a failed (or stale) source back on the download queue.
 *
 * One implementation behind both the panel button and the
 * `relay:requeue-stale` command, so a requeued row always gets the same
 * resets: the ingest attempts start from zero, otherwise a row that already
 * spent its attempts would be marked failed again on the first try, and the
 * current retry policy would never apply to it. The fail counter is kept as
 * history.
 */
class SourceRequeuer
{
    /**
     * Why this source cannot be requeued right now, or null when it can.
     *
     * @param  bool  $allowRetrying  Also accept rows the queue has given up on.
     */
    public function rejection(string $sourceType, int $sourceId, bool $allowRetrying = false): ?string
    {
        $source = $this->source($sourceType, $sourceId);

        if (!$source) {
            return 'منبع پیدا نشد.';
        }

        $allowed = $allowRetrying ? ['failed', '', 'retrying'] : ['failed', ''];

        if (!in_array((string) $source->ingest_status, $allowed, true)) {
            return 'این منبع در وضعیت فعال است و دوباره صف نمی‌شود.';
        }

        if ((string) ($source->link_status ?? '') === 'dead') {
            return 'این منبع در قرنطینه است (لینک مرده)؛ تا probe موفق بعدی دوباره صف نمی‌شود.';
        }

        if ($sourceType === 'episode'
            && Schema::hasColumn('episode_sources', 'is_blocked')
            && (bool) $source->is_blocked) {
            return 'این منبع بلاک شده و دوباره صف نمی‌شود.';
        }

        if (!str_starts_with((string) $source->source, 'http')) {
            return 'این منبع دیگر یک لینک HTTP معتبر نیست.';
        }

        if ($sourceType === 'episode') {
            $episode = Episode::find($source->episode_id);
            $content = $episode ? $this->contentOf($episode) : null;

            if (!$episode || !$content) {
                return 'قسمت یا عنوان مربوط به این منبع پیدا نشد.';
            }
        }

        return null;
    }

    /**
     * Dispatch the ingest job from the beginning with fresh retry counters.
     *
     * @return array{ok:bool,message:string}
     */
    public function requeue(string $sourceType, int $sourceId, bool $allowRetrying = false): array
    {
        $rejection = $this->rejection($sourceType, $sourceId, $allowRetrying);

        if ($rejection !== null) {
            return ['ok' => false, 'message' => $rejection];
        }

        $source = $this->source($sourceType, $sourceId);

        if ($sourceType === 'episode') {
            $episode = Episode::find($source->episode_id);
            $content = $this->contentOf($episode);
            $name = $this->nameFor($content, $episode, (string) $source->source);
        } else {
            $name = (string) ($source->title ?: 'ingest-' . $source->id);
            $extension = strtolower(pathinfo((string) parse_url((string) $source->source, PHP_URL_PATH), PATHINFO_EXTENSION));

            if ($extension && !str_ends_with(strtolower($name), '.' . $extension)) {
                $name .= '.' . $extension;
            }
        }

        $source->ingest_status = 'queued';
        $source->ingest_progress = 0;
        $source->ingest_error = null;
        $source->ingest_attempts = 0;
        $source->ingest_next_retry_at = null;
        $source->upload_status = null;
        $source->upload_attempts = 0;
        $source->upload_next_retry_at = null;
        $source->save();

        IngestUrlJob::dispatch(
            $sourceType,
            (int) $source->id,
            (string) $source->source,
            $name
        );

        return ['ok' => true, 'message' => 'منبع دوباره به صف اضافه شد.'];
    }

    protected function source(string $sourceType, int $sourceId)
    {
        return $sourceType === 'content'
            ? ContentSource::find($sourceId)
            : EpisodeSource::find($sourceId);
    }

    public function contentOf(Episode $episode): ?Content
    {
        $season = Season::find($episode->season_id);

        return $season ? Content::find($season->content_id) : null;
    }

    public function nameFor(Content $content, Episode $episode, string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'mkv';
        $season = Season::find($episode->season_id);
        $seasonNumber = (int) preg_replace('/\D+/', '', (string) optional($season)->title) ?: (int) optional($season)->id;

        return sprintf('%s - S%02dE%02d.%s', $content->title, $seasonNumber, (int) $episode->number, $extension);
    }
}
