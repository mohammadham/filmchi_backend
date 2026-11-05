<?php

namespace Database\Seeders;

use App\Constants;
use App\Content;
use App\ContentSource;
use App\Episode;
use App\EpisodeSource;
use App\Season;
use Illuminate\Database\Seeder;

/**
 * A small, real catalog used to exercise the panel end to end:
 *
 *  - one movie with two 1080p mirrors from different providers (the case the
 *    panel could not express before: several links for the same quality);
 *  - one series with three qualities per episode, using the direct Farsanime
 *    links the operator tested with.
 *
 * Re-running it updates the same rows instead of duplicating them.
 */
class RealCatalogSeeder extends Seeder
{
    const SERIES_POSTER = 'https://cdn.myanimelist.net/images/anime/1190/158569.jpg';

    const MOVIE_POSTER = 'https://upload.wikimedia.org/wikipedia/commons/8/8f/Sintel_poster.jpg';

    public function run()
    {
        $this->seedMovie();
        $this->seedSeries();
    }

    protected function seedMovie()
    {
        $content = Content::firstOrNew(['title' => 'Sintel', 'type' => Constants::movie]);

        $content->description = 'A wandering warrior befriends a young dragon, and sets out across the world when it is taken from her. An open animated film by the Blender Foundation.';
        $content->duration = '15 min';
        $content->release_year = '2010';
        $content->ratings = '7.6';
        $content->language_id = 1;
        $content->genre_ids = '2';
        $content->trailer_url = 'eRsGyueVLvQ';
        $content->vertical_poster = self::MOVIE_POSTER;
        $content->horizontal_poster = self::MOVIE_POSTER;
        $content->is_featured = 1;
        $content->is_show = 1;
        $content->save();

        $sources = [
            ['480p', 'Archive.org', '74MB', 'https://archive.org/download/Sintel/sintel-2048-stereo_512kb.mp4'],
            ['1080p', 'Archive.org (Stereo)', '270MB', 'https://archive.org/download/Sintel/sintel-2048-stereo.mp4'],
            ['1080p', 'Archive.org (Surround)', '296MB', 'https://archive.org/download/Sintel/sintel-2048-surround.mp4'],
        ];

        foreach ($sources as $row) {
            $source = ContentSource::firstOrNew([
                'content_id' => $content->id,
                'quality' => $row[0],
                'provider' => $row[1],
            ]);

            $source->title = 'Sintel (2010)';
            $source->size = $row[2];
            $source->is_download = 1;
            $source->access_type = 1;
            $source->type = 4; // Mp4 Url
            $source->source = $row[3];
            $source->link_status = 'alive';
            $source->link_checked_at = now();
            $source->save();
        }
    }

    protected function seedSeries()
    {
        $content = Content::firstOrNew(['title' => "Beyond Time's Gaze", 'type' => Constants::series]);

        $content->description = 'Xu Qing survives the apocalypse and keeps walking the path of cultivation while the shattered world watches him. Season 2 of the donghua, with Persian hard subtitles on Farsanime.';
        $content->duration = null;
        $content->release_year = '2026';
        $content->ratings = '8.2';
        $content->language_id = 1;
        $content->genre_ids = '2';
        $content->trailer_url = 'GozLsbxwCso';
        $content->vertical_poster = self::SERIES_POSTER;
        $content->horizontal_poster = self::SERIES_POSTER;
        $content->is_featured = 1;
        $content->is_show = 1;
        $content->save();

        $season = Season::firstOrNew([
            'content_id' => $content->id,
            'title' => 'Season 2',
        ]);
        $season->trailer_url = $content->trailer_url;
        $season->save();

        // Real bytes, measured from the Farsanime file server before seeding.
        $qualities = [
            '480p' => [357306575, 236673432, 277408183, 298086678, 246648881, 225032621],
            '720p' => [701046265, 468255945, 552395325, 594261812, 490789496, 448313561],
            '1080p' => [1390515604, 932846413, 1106125454, 1190746199, 985361542, 900386867],
        ];

        for ($number = 1; $number <= 6; $number++) {
            $episode = Episode::firstOrNew([
                'season_id' => $season->id,
                'number' => $number,
            ]);

            $episode->title = 'Episode ' . $number;
            $episode->description = "Beyond Time's Gaze — Season 2, Episode {$number}.";
            $episode->duration = '25 min';
            $episode->thumbnail = self::SERIES_POSTER;
            $episode->save();

            $code = str_pad((string) $number, 2, '0', STR_PAD_LEFT);

            foreach ($qualities as $quality => $sizes) {
                $source = EpisodeSource::firstOrNew([
                    'episode_id' => $episode->id,
                    'quality' => $quality,
                    'provider' => 'Farsanime',
                ]);

                $source->title = "Beyond.TimesGaze.S2.E{$code}";
                $source->size = round($sizes[$number - 1] / 1048576) . 'MB';
                $source->is_download = 1;
                $source->access_type = 1;
                $source->type = 5; // Mkv Url
                $source->source = "https://di.farsanime.ir/Beyond.TimesGaze.S2.{$quality}/farsanime.irBeyond.TimesGaze.S2.{$quality}.E{$code}.mkv";
                $source->link_status = 'alive';
                $source->link_checked_at = now();
                $source->save();
            }
        }
    }
}
