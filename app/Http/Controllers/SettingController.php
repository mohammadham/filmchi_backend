<?php

namespace App\Http\Controllers;

use App\Actor;
use App\Admin;
use App\Admob;
use App\Content;
use App\CustomAd;
use App\Episode;
use App\Genre;
use App\EpisodeSource;
use App\GlobalSettings;
use App\Language;
use App\RelayWorker;
use App\Notification;
use App\Season;
use App\Pages;
use App\Services\Relay\NineDriveClient;
use App\Services\Relay\RelaySettings;
use App\Services\Relay\TelegramDriveClient;
use App\Console\Commands\RelayQueueSupervisor;
use App\TVCategory;
use App\TVChannel;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

class SettingController extends Controller
{
    function index()
    {
        $totalUser = User::count();
        $contents = Content::count();
        $actors = Actor::count();
        $genres = Genre::count();
        $languages = Language::count();
        $liveTvCategories = TVCategory::count();
        $liveTvChannels = TVChannel::count();
        $notifications = Notification::count();
        $admob = Admob::count();
        $customAds = CustomAd::count();

        return view('index', [
            'totalUser' => $totalUser,
            'contents' => $contents,
            'actors' => $actors,
            'genres' => $genres,
            'languages' => $languages,
            'liveTvCategories' => $liveTvCategories,
            'liveTvChannels' => $liveTvChannels,
            'notifications' => $notifications,
            'admob' => $admob,
            'customAds' => $customAds,
        ]);
    }

    function setting()
    {
        $setting = GlobalSettings::first();
        $awsConfig = [
            'AWS_ACCESS_KEY_ID' => env('AWS_ACCESS_KEY_ID'),
            'AWS_SECRET_ACCESS_KEY' => env('AWS_SECRET_ACCESS_KEY'),
            'AWS_DEFAULT_REGION' => env('AWS_DEFAULT_REGION'),
            'AWS_BUCKET' => env('AWS_BUCKET')
        ];
        $doConfig = [
            'DO_SPACE_ACCESS_KEY_ID' => env('DO_SPACE_ACCESS_KEY_ID'),
            'DO_SPACE_SECRET_ACCESS_KEY' => env('DO_SPACE_SECRET_ACCESS_KEY'),
            'DO_SPACE_REGION' => env('DO_SPACE_REGION'),
            'DO_SPACE_BUCKET' => env('DO_SPACE_BUCKET')
        ];
        $relay = RelaySettings::effective();
        $relayRuntime = $this->relayRuntimeStatus();

        try {
            $workers = RelayWorker::query()->orderBy('priority')->orderBy('id')->get();
        } catch (\Throwable $e) {
            // Keep the settings page usable while a deploy is migrating.
            $workers = collect();
        }

        return view('setting', compact('setting', 'awsConfig', 'doConfig', 'relay', 'workers', 'relayRuntime'));
    }

    /**
     * Live ingest indicators for the settings card. Active rows use the same
     * 20-minute freshness rule as the automation API; the queue-worker count
     * comes from the supervisor heartbeat, not from a stale database row.
     */
    protected function relayRuntimeStatus(): array
    {
        $active = 0;
        $queued = 0;

        try {
            $active = EpisodeSource::whereIn('ingest_status', ['downloading', 'uploading'])
                ->where('updated_at', '>=', now()->subMinutes(20))
                ->count();
            $queued = EpisodeSource::where('ingest_status', 'queued')->count();
        } catch (\Throwable $e) {
            // Keep the settings page usable during a migration.
        }

        $live = 0;
        $desired = max(1, (int) config('media_relay.ingest.concurrency', 1));

        try {
            $heartbeat = Cache::get(RelayQueueSupervisor::STATUS_KEY);

            if (is_array($heartbeat)) {
                $desired = max(0, (int) ($heartbeat['desired'] ?? $desired));
                $beatAt = !empty($heartbeat['updated_at']) ? Carbon::parse($heartbeat['updated_at']) : null;

                if ($beatAt && $beatAt->gt(now()->subSeconds(20))) {
                    $live = max(0, (int) ($heartbeat['workers'] ?? 0));
                }
            }
        } catch (\Throwable $e) {
            // No heartbeat available; report 0 live.
        }

        return [
            'active' => $active,
            'queued' => $queued,
            'workers_live' => $live,
            'workers_desired' => $desired,
            'downloads' => $this->activeDownloadRows(),
        ];
    }

    /**
     * The active rows shown in the settings card. Names are built from the
     * same title/season/episode convention as the dispatcher, and the speed is
     * the average rate the ingest job has measured since the transfer began.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function activeDownloadRows(): array
    {
        try {
            $sources = EpisodeSource::whereIn('ingest_status', ['downloading', 'uploading'])
                ->where('updated_at', '>=', now()->subMinutes(20))
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        return $sources->map(function (EpisodeSource $source) {
            $episode = Episode::find($source->episode_id);
            $season = $episode ? Season::find($episode->season_id) : null;
            $content = $season ? Content::find($season->content_id) : null;

            $path = parse_url((string) $source->source, PHP_URL_PATH) ?: '';
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'mkv';
            $fallback = basename($path) ?: ('source-' . $source->id);
            $seasonNumber = (int) preg_replace('/\D+/', '', (string) optional($season)->title)
                ?: (int) optional($season)->id;

            $name = $content && $episode
                ? sprintf(
                    '%s - S%02dE%02d.%s',
                    (string) $content->title,
                    $seasonNumber,
                    (int) $episode->number,
                    $extension
                )
                : $fallback;

            $uploading = (string) $source->ingest_status === 'uploading';
            $speedBps = $uploading
                ? (int) $source->upload_speed_bps
                : (int) $source->ingest_speed_bps;

            return [
                'id' => (int) $source->id,
                'name' => $name,
                'quality' => (string) $source->quality,
                'status' => (string) $source->ingest_status,
                'status_label' => $uploading ? __('relayRuntimeUploading') : __('relayRuntimeDownloading'),
                'progress' => max(0, min(100, (int) $source->ingest_progress)),
                'speed_bps' => $speedBps,
                'speed' => $this->humanSpeed($speedBps),
            ];
        })->values()->all();
    }

    protected function humanSpeed(int $bps): string
    {
        if ($bps <= 0) {
            return '-';
        }

        if ($bps >= 1073741824) {
            return number_format($bps / 1073741824, 2) . ' GB/s';
        }

        if ($bps >= 1048576) {
            return number_format($bps / 1048576, 2) . ' MB/s';
        }

        if ($bps >= 1024) {
            return number_format($bps / 1024, 1) . ' KB/s';
        }

        return $bps . ' B/s';
    }

    public function relayRuntime()
    {
        return response()->json(array_merge(['status' => true], $this->relayRuntimeStatus()));
    }
    
    function fetchSettings()
    {
        $setting = GlobalSettings::first();
        $genres = Genre::get();
        $languages = Language::get();
        $admob = Admob::get();

        return response()->json([
            'status' => true,
            'message' => 'Fetch Setting Successfully',
            'setting' => $setting,
            'genres' => $genres,
            'languages' => $languages,
            'admob' => $admob
        ]);
    }

    public function saveSettings(Request $request)
    {
        $setting = GlobalSettings::first();

        if($setting == null) {
            return response()->json([
                'status' => false,
                'message' => 'setting Not Found',
            ]);
        }
        if($request->has('app_name')) {
            $setting->app_name = $request->app_name;
            $request->session()->put('app_name', $setting['app_name']);
        }
        if($request->has('videoad_skip_time')) {
            $setting->videoad_skip_time = $request->videoad_skip_time;
        }
        if($request->has('is_live_tv_enable')) {
            $setting->is_live_tv_enable = $request->is_live_tv_enable;
        }
        if($request->has('is_admob_android')) {
            $setting->is_admob_android = $request->is_admob_android;
        }
        if($request->has('is_admob_ios')) {
            $setting->is_admob_ios = $request->is_admob_ios;
        }
        if($request->has('is_custom_android')) {
            $setting->is_custom_android = $request->is_custom_android;
        }
        if($request->has('is_custom_ios')) {
            $setting->is_custom_ios = $request->is_custom_ios;
        }
        if($request->has('storage_type')) {
            $setting->storage_type = $request->storage_type;
        }
        if($request->has('panel_lang') && in_array($request->panel_lang, ['en', 'fa'], true)) {
            $setting->panel_lang = $request->panel_lang;
        }
        $setting->save();

        return response()->json([
            'status' => true,
            'message' => 'Setting Updated Successfully',
        ]);

        
    }

    /**
     * Save the Telegram Drive + 9drive connection settings from the panel.
     */
    public function saveRelaySettings(Request $request)
    {
        $setting = GlobalSettings::first();

        if ($setting == null) {
            return response()->json([
                'status' => false,
                'message' => 'setting Not Found',
            ]);
        }

        $input = RelaySettings::filterInput($request->all());

        // Never blank out a secret by accident: an empty box keeps the saved value.
        foreach (['relay_tg_password', 'relay_tg_api_key', 'relay_nine_password', 'relay_nine_api_key'] as $secret) {
            if (array_key_exists($secret, $input) && trim((string) $input[$secret]) === '') {
                unset($input[$secret]);
            }
        }

        $input['relay_enabled'] = $request->boolean('relay_enabled');

        RelaySettings::save($setting, $input);

        return response()->json([
            'status' => true,
            'message' => 'Relay settings updated successfully',
        ]);
    }

    /**
     * Probe both relay services with the values currently in the form.
     */
    public function testRelayConnection(Request $request)
    {
        $setting = GlobalSettings::first();

        $overrides = RelaySettings::filterInput($request->all());
        $overrides['relay_enabled'] = $request->boolean('relay_enabled');

        RelaySettings::apply($setting, $overrides);

        $telegram = new TelegramDriveClient((array) config('media_relay.telegram'));
        $nine = new NineDriveClient((array) config('media_relay.ninedrive'));

        return response()->json([
            'status' => true,
            'enabled' => (bool) config('media_relay.enabled'),
            'telegram' => $telegram->health(),
            'ninedrive' => $nine->health(),
        ]);
    }

    public function changePassword(Request $request)
    {
        $admin = Admin::where('user_type', 1)->first();
        if ($admin) {
            if ($request->has('user_password')) {
                if ($admin->user_password == $request->user_password) {
                    $admin->user_password = $request->new_password;
                    $admin->save();
                    return response()->json([
                        'status' => true,
                        'message' => 'Change Password',
                    ]);
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'Old Password does not match',
                    ]);
                }
            }
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found',
            ]);
        }
    }

    public function privacyPolicy()
    {
        $data = Pages::first();
        return view('pages.privacyPolicy', [
            'data' => $data->privacy
        ]);
    }

    public function termsOfUse()
    {
        $data = Pages::first();
        return view('pages.termsOfUse', [
            'data' => $data->termsofuse
        ]);
    }

    public function viewTerms()
    {
        $data = Pages::first();
        return view('pages.viewTerms', ['data' => $data->termsofuse]);
    }

    public function updatePrivacy(Request $request)
    {
        $data = Pages::first();
        $data->privacy = $request->content;
        $data->save();

        return response()->json([
            'status' => true,
            'message' => 'Updated Successfully',
        ]);
    }

    public function updateTerms(Request $request)
    {
        $data = Pages::first();
        $data->termsofuse = $request->content;
        $data->save();

        return response()->json([
            'status' => true,
            'message' => 'Updated Successfully',
        ]);
    }

    public function viewPrivacy()
    {
        $data = Pages::first();
        return view('pages.viewPrivacy', ['data' => $data->privacy]);
    }

    public function addContentForm(Request $request)
    {
        $privacy = Pages::first();
        if ($privacy) {
            if ($request->has('privacy')) {
                $privacy->privacy = $request->privacy;
            }
        }
        $privacy->save();

        return response()->json([
            'status' => true,
            'message' => 'Updated Successfully',
        ]);
    }

    public function addTermsForm(Request $request)
    {
        $terms = Pages::first();
        if ($terms) {
            if ($request->has('termsofuse')) {
                $terms->termsofuse = $request->termsofuse;
            }
        }
        $terms->save();
        
        return response()->json([
            'status' => true,
            'message' => 'Updated Successfully',
        ]);
    }

    public function fetchContentFromTMDB()
    {
        $genres = Genre::orderBy('created_at', 'DESC')->get();
        $languages = Language::orderBy('created_at', 'DESC')->get();
        return view('fetchContentFromTMDB', [
                'genres' => $genres,
                'languages' => $languages,
            ]
        );
    }
    
}
