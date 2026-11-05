<?php

namespace App\Console\Commands;

use App\Constants;
use App\Content;
use App\Episode;
use App\EpisodeSource;
use App\Genre;
use App\Language;
use App\Season;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as HttpRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports the Farsanime series catalog.
 *
 * Farsanime exposes one free episode per season (usually episode 1) with every
 * quality it stores for that season. The file server keeps the same naming
 * scheme for the rest of the season, so those links give both the qualities
 * and the URL template. The episode list on the page is paginated into chunks
 * that are labelled like seasons, so it is not used for counting: the last
 * episode is found by probing the file server (exponential + binary search).
 *
 * Each season is identified by its real storage folder and season token, and
 * every season becomes one Flixy season. The command is idempotent.
 */
class ImportFarsanimeSeries extends Command
{
    protected $signature = 'farsanime:import
        {--slug=* : Only import these series slugs}
        {--limit=0 : Stop after this many imported series}
        {--dry-run : Parse and report without writing to the database}
        {--max-episodes=2000 : Safety cap for the episode search}
        {--sleep=400 : Milliseconds between series pages}';

    protected $description = 'Import Farsanime series metadata, seasons, episodes and their download qualities';

    const SITEMAP = 'https://farsanime.ir/series-sitemap.xml';

    /** @var Client */
    protected $http;

    /** @var array<string, int|null> url => size (null = missing) */
    protected $probeResults = [];

    protected $ordinals = [
        1 => 'اول', 2 => 'دوم', 3 => 'سوم', 4 => 'چهارم', 5 => 'پنجم',
        6 => 'ششم', 7 => 'هفتم', 8 => 'هشتم', 9 => 'نهم', 10 => 'دهم',
    ];

    public function handle()
    {
        $this->http = new Client([
            'timeout' => 60,
            'connect_timeout' => 20,
            'http_errors' => false,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; FlixyCatalog/1.0)',
                'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.8',
            ],
        ]);

        $slugs = array_filter((array) $this->option('slug'));
        $limit = (int) $this->option('limit');
        $urls = $this->seriesUrls();

        if (empty($urls)) {
            $this->error('The Farsanime sitemap returned no series.');

            return 1;
        }

        if ($slugs) {
            $urls = array_values(array_filter($urls, function ($url) use ($slugs) {
                return in_array($this->slugOf($url), $slugs, true);
            }));
        }

        $this->info(sprintf('%d series candidate(s).', count($urls)));

        $imported = 0;
        $skipped = 0;
        $episodes = 0;
        $sources = 0;

        foreach ($urls as $url) {
            if ($limit > 0 && $imported >= $limit) {
                break;
            }

            $this->heartbeat();

            $slug = $this->slugOf($url);

            try {
                $page = $this->parse($this->fetch($url));
            } catch (\Throwable $e) {
                $this->warn("[{$slug}] " . $e->getMessage());
                continue;
            }

            if (empty($page['files'])) {
                $this->line("[{$slug}] no free episode links — skipped.");
                $skipped++;
                continue;
            }

            $seasons = $this->prepareSeasons($page['files']);

            if (empty($seasons)) {
                $this->line("[{$slug}] no season resolved — skipped.");
                $skipped++;
                continue;
            }

            $seasonEpisodes = array_sum(array_map(function ($s) {
                return count($s['episodes']);
            }, $seasons));

            $seasonSources = array_sum(array_map(function ($s) {
                return array_sum(array_map(function ($e) {
                    return count($e);
                }, $s['episodes']));
            }, $seasons));

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '[%s] %s — %d season(s), %d episode(s), %d source(s)',
                    $slug,
                    $page['title'],
                    count($seasons),
                    $seasonEpisodes,
                    $seasonSources
                ));
            } else {
                $this->store($page, $seasons, $slug);

                $this->line(sprintf(
                    '[%s] %s — %d season(s), %d episode(s), %d source(s)',
                    $slug,
                    $page['title'],
                    count($seasons),
                    $seasonEpisodes,
                    $seasonSources
                ));
            }

            $imported++;
            $episodes += $seasonEpisodes;
            $sources += $seasonSources;

            usleep(max(0, (int) $this->option('sleep')) * 1000);
        }

        $this->newLine();
        $this->info(sprintf(
            'Done: %d series, %d episodes, %d sources (%d skipped).',
            $imported,
            $episodes,
            $sources,
            $skipped
        ));

        return 0;
    }

    /**
     * Liveness for the orchestrator: a pass whose heartbeat stopped moving
     * belongs to a process that a deploy or an OOM kill has taken down.
     */
    protected function heartbeat(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forever(
                SyncFarsanimeCatalogue::HEARTBEAT_KEY,
                now()->toIso8601String()
            );
        } catch (\Throwable $e) {
            // The cache is a hint only; a cache outage must not stop the import.
        }
    }

    /* ------------------------------------------------------------- fetching */

    protected function seriesUrls(): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#i', $this->fetch(self::SITEMAP), $m);
        $urls = [];

        foreach ($m[1] as $loc) {
            $loc = trim(html_entity_decode($loc));

            if (preg_match('#/series/[^/]+/?$#', $loc)) {
                $urls[] = $loc;
            }
        }

        return array_values(array_unique($urls));
    }

    protected function fetch(string $url, int $attempts = 3): string
    {
        $status = 0;

        for ($i = 1; $i <= $attempts; $i++) {
            $response = $this->http->get($url);
            $status = $response->getStatusCode();

            if ($status === 200) {
                return (string) $response->getBody();
            }

            usleep(700000 * $i);
        }

        throw new \RuntimeException('HTTP ' . $status . ' for ' . $url);
    }

    protected function slugOf(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return (string) substr($path, (int) strrpos($path, '/') + 1);
    }

    /* -------------------------------------------------------------- parsing */

    protected function parse(string $html): array
    {
        return [
            'title' => $this->title($html),
            'year' => $this->year($html),
            'poster' => $this->meta($html, 'og:image'),
            'description' => $this->meta($html, 'og:description'),
            'genres' => $this->genres($html),
            'tags' => $this->tags($html),
            'language' => $this->language($html),
            'files' => $this->freeFiles($html),
        ];
    }

    protected function title(string $html): string
    {
        if (!preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $m)) {
            return '';
        }

        // The year sits inside the heading as its own span; drop the span
        // before stripping tags, or Persian digits stay glued to the name.
        $heading = preg_replace('#<span[^>]*nx-title__year[^>]*>.*?</span>#su', '', $m[1]) ?? $m[1];
        $title = html_entity_decode(strip_tags($heading), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $title));
    }

    protected function year(string $html): string
    {
        if (preg_match('#class="nx-title__year"[^>]*>([^<]+)<#u', $html, $m)) {
            return $this->asciiDigits(trim($m[1]));
        }

        return '';
    }

    protected function meta(string $html, string $property): string
    {
        if (preg_match('#<meta\s+property="' . preg_quote($property, '#') . '"\s+content="([^"]*)"#i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    protected function genres(string $html): array
    {
        if (!preg_match('#<p[^>]*nx-title__genres[^>]*>(.*?)</p>#s', $html, $m)) {
            return [];
        }

        preg_match_all('#<a[^>]*>(.*?)</a>#s', $m[1], $links);

        return $this->cleanList($links[1]);
    }

    protected function tags(string $html): array
    {
        if (!preg_match_all('#<a[^>]+href="[^"]*/series-tags/[^"]*"[^>]*>(.*?)</a>#s', $html, $m)) {
            return [];
        }

        return $this->cleanList($m[1]);
    }

    protected function language(string $html): string
    {
        if (!preg_match('#<ul class="nx-facts">(.*?)</ul>#s', $html, $m)) {
            return '';
        }

        // Each fact is its own <li>; reading the whole block at once glues the
        // language onto the country and the age rating.
        if (!preg_match_all('#<li[^>]*>(.*?)</li>#s', $m[1], $items)) {
            return '';
        }

        foreach ($items[1] as $item) {
            $text = trim(preg_replace(
                '/\s+/u',
                ' ',
                html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            ));

            if (preg_match('/زبان\s*(.+)$/u', $text, $match)) {
                return trim($match[1]);
            }
        }

        return '';
    }

    /**
     * The download box exposes a direct file for the free episode of each
     * season; those are the only URLs the page hands out without a session.
     */
    protected function freeFiles(string $html): array
    {
        preg_match_all('#<li class="nx-ep"[^>]*data-file="(https?://[^"]+)"#s', $html, $m);

        return array_values(array_unique(array_map(function ($url) {
            return html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $m[1])));
    }

    protected function cleanList(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if ($text !== '' && !in_array($text, $clean, true)) {
                $clean[] = $text;
            }
        }

        return $clean;
    }

    protected function asciiDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /* -------------------------------------------------------------- seasons */

    /**
     * @return array<int, array{title:string, season:int, episodes:array<int, array<string,string>>}>
     */
    protected function prepareSeasons(array $files): array
    {
        $groups = [];

        foreach ($files as $file) {
            $quality = $this->qualityOf($file);

            if ($quality === null) {
                continue;
            }

            $key = $this->seasonKey($file);
            $groups[$key]['files'][] = $file;
            $groups[$key]['season'] = $this->seasonNumber($file);
        }

        $seasons = [];
        $used = [];
        $index = 1;

        foreach ($groups as $key => $group) {
            $templates = $this->templates($group['files']);

            if (empty($templates)) {
                continue;
            }

            // The template must answer for episode 1; anything else is a
            // different naming scheme we cannot extrapolate from.
            $first = $this->deriveUrl($templates[0]['url'], 1);

            if ($first === null || ($this->probe([$first])[$first] ?? null) === null) {
                $this->line('  folder ' . $this->folderOf($templates[0]['url']) . ': episode 1 does not resolve, skipped.');
                continue;
            }

            $last = $this->findLastEpisode($templates[0]['url']);

            if ($last < 1) {
                continue;
            }

            // Qualities normally end on the same episode; when one stops
            // earlier (a smaller encode uploaded only up to a point) it gets
            // its own limit instead of being dropped for the whole season.
            $limits = [];

            foreach ($templates as $template) {
                if ($template['quality'] === $templates[0]['quality']) {
                    $limits[$template['quality']] = $last;
                    continue;
                }

                $url = $this->deriveUrl($template['url'], $last);

                $limits[$template['quality']] = ($url !== null && ($this->probe([$url])[$url] ?? null) !== null)
                    ? $last
                    : $this->findLastEpisode($template['url']);
            }

            $highest = $limits ? max($limits) : 0;
            $episodes = [];

            for ($number = 1; $number <= $highest; $number++) {
                $qualities = [];

                foreach ($templates as $template) {
                    if ($number > ($limits[$template['quality']] ?? 0)) {
                        continue;
                    }

                    $url = $this->deriveUrl($template['url'], $number);

                    if ($url !== null) {
                        $qualities[$template['quality']] = $url;
                    }
                }

                if ($qualities) {
                    $episodes[$number] = $qualities;
                }
            }

            if (empty($episodes)) {
                continue;
            }

            $season = (int) ($group['season'] ?: $index);

            while (in_array($season, $used, true)) {
                $season++;
            }

            $used[] = $season;
            $index++;

            $seasons[] = [
                'title' => 'Season ' . $season,
                'season' => $season,
                'episodes' => $episodes,
            ];
        }

        usort($seasons, function ($a, $b) {
            return $a['season'] <=> $b['season'];
        });

        return $seasons;
    }

    /**
     * Exponential + binary search for the highest episode number the file
     * server still answers for. Missing episodes inside the range are rare
     * and are left to the link checker to hide.
     */
    protected function findLastEpisode(string $template): int
    {
        $cap = max(1, (int) $this->option('max-episodes'));
        $exists = function (int $number) use ($template): bool {
            $url = $this->deriveUrl($template, $number);

            return $url !== null && ($this->probe([$url])[$url] ?? null) !== null;
        };

        $low = 1;
        $high = 2;

        while ($high <= $cap && $exists($high)) {
            $low = $high;
            $high *= 2;
        }

        $high = min($high, $cap);

        while ($low + 1 < $high) {
            $mid = intdiv($low + $high, 2);

            if ($exists($mid)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    /**
     * Seasons are keyed by their season token (S01, S2, …) rather than by
     * folder, because the folder name carries the quality on many titles
     * (…Heng1080p, …Heng720p). Titles that use no token at all are treated
     * as a single season, which is how Farsanime publishes them.
     */
    protected function seasonKey(string $url): string
    {
        $token = $this->seasonToken($url);

        return $token > 0 ? 'season-' . $token : 'season-0';
    }

    protected function seasonToken(string $url): int
    {
        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        if (preg_match('#(?:^|[^A-Za-z])S(\d{1,3})(?:[^0-9]|$)#i', $name, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    protected function seasonNumber(string $url): int
    {
        $token = $this->seasonToken($url);

        if ($token > 0) {
            return $token;
        }

        $folder = rawurldecode((string) parse_url(dirname($url), PHP_URL_PATH));

        if (preg_match('#S(\d{1,3})#i', $folder, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * One template per quality. When a folder publishes both soft-sub and
     * hard-sub files, the episode-1 (soft) encoding wins.
     */
    protected function templates(array $files): array
    {
        usort($files, function ($a, $b) {
            $weight = function ($url) {
                $name = basename((string) parse_url($url, PHP_URL_PATH));
                $soft = stripos($name, 'soft') !== false ? 0 : 1;
                $episode = preg_match('#\.E0*1\.#i', $name) ? 0 : 1;

                return [$episode, $soft];
            };

            return $weight($a) <=> $weight($b);
        });

        $templates = [];

        foreach ($files as $file) {
            $quality = $this->qualityOf($file);

            if ($quality !== null && !isset($templates[$quality])) {
                $templates[$quality] = ['quality' => $quality, 'url' => $this->deriveUrl($file, 1) ?: $file];
            }
        }

        return array_values($templates);
    }

    protected function qualityOf(string $url): ?string
    {
        if (preg_match('#(2160|1440|1080|720|480|360)p#i', rawurldecode($url), $m)) {
            return strtolower($m[0]);
        }

        return null;
    }

    protected function folderOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return (string) parse_url($url, PHP_URL_HOST) . dirname($path);
    }

    protected function deriveUrl(string $anchor, int $number): ?string
    {
        $path = (string) parse_url($anchor, PHP_URL_PATH);
        $base = basename($path);

        if (!preg_match('#E(\d{1,4})(\.[^./]+)$#i', $base, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $digits = $m[1][0];
        $offset = $m[1][1];
        $replacement = str_pad((string) $number, strlen($digits), '0', STR_PAD_LEFT);
        $newBase = substr($base, 0, $offset) . $replacement . substr($base, $offset + strlen($digits));

        $segments = explode('/', trim($path, '/'));
        array_pop($segments);
        $segments[] = $newBase;

        $scheme = parse_url($anchor, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($anchor, PHP_URL_HOST);

        return $scheme . '://' . $host . '/' . implode('/', $segments);
    }

    /* --------------------------------------------------------------- probing */

    /**
     * Range-request a batch of URLs and keep their total size.
     *
     * @return array<string, int|null> url => size in bytes (null = missing)
     */
    protected function probe(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls)));
        $pending = [];

        foreach ($urls as $url) {
            if (!array_key_exists($url, $this->probeResults)) {
                $pending[] = $url;
            }
        }

        if ($pending) {
            $client = $this->http;
            $results = &$this->probeResults;

            $requests = function () use ($pending) {
                foreach ($pending as $url) {
                    yield new HttpRequest('GET', $url, [
                        'Range' => 'bytes=0-0',
                        'User-Agent' => 'Mozilla/5.0 (compatible; FlixyCatalog/1.0)',
                    ]);
                }
            };

            $pool = new Pool($client, $requests(), [
                'concurrency' => 4,
                'fulfilled' => function ($response, $index) use ($pending, &$results) {
                    $url = $pending[$index];
                    $status = $response->getStatusCode();
                    $type = strtolower($response->getHeaderLine('Content-Type'));

                    // A missing file may still answer 200 with an HTML error
                    // page, so the type has to look like a media file.
                    $isMedia = str_contains($type, 'video/')
                        || str_contains($type, 'octet-stream')
                        || str_contains($type, 'matroska');

                    if (!$isMedia || ($status !== 200 && $status !== 206)) {
                        $results[$url] = null;

                        return;
                    }

                    $size = null;
                    $range = $response->getHeaderLine('Content-Range');

                    if (preg_match('#/(\d+)$#', $range, $m)) {
                        $size = (int) $m[1];
                    } elseif ($status === 200 && (int) $response->getHeaderLine('Content-Length') > 0) {
                        $size = (int) $response->getHeaderLine('Content-Length');
                    }

                    $results[$url] = $size ?: 1;

                    $body = $response->getBody();

                    if (method_exists($body, 'close')) {
                        $body->close();
                    }
                },
                'rejected' => function ($reason, $index) use ($pending, &$results) {
                    $results[$pending[$index]] = null;
                },
            ]);

            $pool->promise()->wait();
            usleep(120000);
        }

        $found = [];

        foreach ($urls as $url) {
            if (($this->probeResults[$url] ?? null) !== null) {
                $found[$url] = $this->probeResults[$url];
            }
        }

        return $found;
    }

    /* --------------------------------------------------------------- storage */

    protected function store(array $page, array $seasons, string $slug): void
    {
        $content = $this->firstOrNew(Content::class, [
            'title' => $page['title'] ?: $slug,
            'type' => Constants::series,
        ]);

        $content->description = $page['description'] ?: $content->description;
        $content->release_year = $page['year'] ?: $content->release_year;
        $content->tags = empty($page['tags']) ? $content->tags : implode(', ', $page['tags']);
        $content->vertical_poster = $page['poster'] ?: $content->vertical_poster;
        $content->horizontal_poster = $page['poster'] ?: $content->horizontal_poster;
        $content->genre_ids = $this->genreIds($page['genres'], $content->genre_ids);
        $content->language_id = $this->languageId($page['language']) ?: $content->language_id;
        $content->storage_folder = Str::slug($page['title'] ?: $slug) ?: $slug;
        $content->is_show = Constants::showContent;
        $content->is_featured = $content->is_featured ?? 0;
        $content->save();

        foreach ($seasons as $seasonData) {
            $season = $this->firstOrNew(Season::class, [
                'content_id' => $content->id,
                'title' => $seasonData['title'],
            ]);

            if (!$season->exists) {
                $season->trailer_url = $content->trailer_url;
            }

            $season->save();

            foreach ($seasonData['episodes'] as $number => $qualities) {
                $episode = $this->firstOrNew(Episode::class, [
                    'season_id' => $season->id,
                    'number' => $number,
                ]);

                $episode->title = $episode->title ?: 'Episode ' . $number;
                $episode->description = $episode->description ?: ($page['description'] ?: null);
                $episode->thumbnail = $episode->thumbnail ?: $page['poster'];
                $episode->save();

                foreach ($qualities as $quality => $url) {
                    $source = $this->firstOrNew(EpisodeSource::class, [
                        'episode_id' => $episode->id,
                        'quality' => $quality,
                        'provider' => 'Farsanime',
                    ]);

                    $size = $this->probeResults[$url] ?? null;

                    $source->title = $source->title ?: $this->fileName($url);
                    $source->size = $size && $size > 1 ? round($size / 1048576) . 'MB' : $source->size;
                    $source->is_download = 1;
                    $source->access_type = 1;
                    $source->type = $this->sourceType($url);
                    $source->source = $url;

                    if ($size !== null) {
                        $source->link_status = 'alive';
                        $source->link_checked_at = now();
                    }

                    $source->save();
                }
            }
        }
    }

    /**
     * Artisan commands do not run under `Model::unguarded()`, so look the row
     * up and assign the key attributes explicitly.
     */
    protected function firstOrNew(string $class, array $keys)
    {
        $model = $class::where($keys)->first() ?: new $class();

        foreach ($keys as $attribute => $value) {
            $model->{$attribute} = $value;
        }

        return $model;
    }

    protected function fileName(string $url): string
    {
        return rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))) ?: 'source';
    }

    protected function sourceType(string $url): int
    {
        return strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) === 'mp4'
            ? 4
            : 5;
    }

    protected function genreIds(array $names, ?string $existing): ?string
    {
        if (empty($names)) {
            return $existing;
        }

        $ids = $existing ? array_filter(array_map('intval', explode(',', $existing))) : [];

        foreach ($names as $name) {
            $genre = Genre::where('title', $name)->first();

            if (!$genre) {
                $genre = new Genre();
                $genre->title = $name;
                $genre->save();
            }

            $ids[] = (int) $genre->id;
        }

        $ids = array_values(array_unique($ids));

        return $ids ? implode(',', $ids) : $existing;
    }

    protected function languageId(string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        $language = Language::where('title', $name)->first();

        if (!$language) {
            $codes = [
                'چینی' => 'zh', 'ژاپنی' => 'ja', 'کره‌ای' => 'ko', 'انگلیسی' => 'en',
                'فارسی' => 'fa', 'عربی' => 'ar', 'ترکی' => 'tr', 'هندی' => 'hi',
                'تایلندی' => 'th', 'روسی' => 'ru', 'فرانسوی' => 'fr', 'آلمانی' => 'de',
            ];

            $language = new Language();
            $language->title = $name;
            $language->code = $codes[$name] ?? 'und';
            $language->save();
        }

        return (int) $language->id;
    }
}
