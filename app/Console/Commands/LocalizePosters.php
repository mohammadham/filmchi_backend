<?php

namespace App\Console\Commands;

use App\Content;
use App\Episode;
use App\Services\PosterStore;
use Illuminate\Console\Command;

/**
 * Pulls title artwork that still points at the scraper source onto our own
 * disk and repoints the content and its episode thumbnails at the copy.
 *
 * Idempotent: rows that already serve from our storage are skipped, so it can
 * be re-run after every catalogue pass.
 */
class LocalizePosters extends Command
{
    protected $signature = 'contents:localize-posters
        {--limit=0 : Stop after this many titles (0 = all)}
        {--type= : Only one content type (1 movie, 2 series)}
        {--sleep=0 : Milliseconds to wait between downloads}';

    protected $description = 'Download remote posters and thumbnails to our storage and update the rows';

    public function handle(PosterStore $store): int
    {
        $limit = (int) $this->option('limit');
        $sleep = max(0, (int) $this->option('sleep'));

        $query = Content::query()
            ->where(function ($q) {
                $q->where('vertical_poster', 'like', 'http%')
                    ->orWhere('horizontal_poster', 'like', 'http%');
            })
            ->orderBy('id');

        if ($this->option('type') !== null) {
            $query->where('type', (int) $this->option('type'));
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $titles = 0;
        $stored = 0;
        $failed = 0;
        $episodes = 0;

        foreach ($query->cursor() as $content) {
            $remote = [];

            foreach (['vertical_poster', 'horizontal_poster'] as $field) {
                $value = trim((string) $content->getAttribute($field));

                if ($value === '' || $store->isLocal($value)) {
                    continue;
                }

                $local = $store->localize($value);

                if ($local === null || $local === $value) {
                    $failed++;
                    $this->warn(sprintf('  #%d %s: could not download %s', $content->id, $field, $value));

                    continue;
                }

                $remote[] = $value;
                $stored++;
                $content->setAttribute($field, $local);
            }

            $titles++;

            if ($content->isDirty()) {
                $content->save();
            }

            if ($remote) {
                $replacement = (string) $content->vertical_poster ?: (string) $content->horizontal_poster;

                // Episodes of this title share the poster as their thumbnail.
                $episodes += Episode::whereIn('thumbnail', $remote)->update(['thumbnail' => $replacement]);
            }

            if ($titles % 25 === 0) {
                $this->info(sprintf('  %d titles, %d images stored, %d failed, %d thumbnails repointed', $titles, $stored, $failed, $episodes));
            }

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        }

        // Episode thumbnails can come from a source other than the title poster
        // (a hand-edited title, an old import). Sweep whatever is still remote.
        $orphans = Episode::query()
            ->whereNotNull('thumbnail')
            ->where('thumbnail', 'like', 'http%')
            ->where('thumbnail', 'not like', $store->baseUrl() . '%')
            ->pluck('thumbnail')
            ->unique();

        foreach ($orphans as $remote) {
            $local = $store->localize($remote);

            if ($local === null || $local === $remote) {
                $failed++;
                continue;
            }

            $episodes += Episode::where('thumbnail', $remote)->update(['thumbnail' => $local]);
            $stored++;
        }

        $this->info(sprintf(
            'Done: %d titles scanned, %d images stored, %d failed, %d thumbnails repointed.',
            $titles,
            $stored,
            $failed,
            $episodes
        ));

        return self::SUCCESS;
    }
}
