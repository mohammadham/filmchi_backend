<?php

namespace App\Http\Controllers;

use App\Actor;
use App\Admin;
use App\Admob;
use App\Content;
use App\ContentSource;
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
use App\Services\Bot\BotImportQueue;
use App\Services\Bot\TelegramBotClient;
use App\Services\Relay\DiskBudget;
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
        try {
            RelaySettings::apply();
        } catch (\Throwable $e) {
            // Keep the page usable if the settings table is mid-migration.
        }

        $active = 0;
        $queued = 0;
        $retrying = 0;
        $failed = 0;

        try {
            // Count both ingest source tables so the runtime badges match the
            // failed/retrying card on the Media Relay page.
            foreach ([EpisodeSource::class, ContentSource::class] as $sourceClass) {
                $active += $sourceClass::whereIn('ingest_status', ['downloading', 'uploading'])
                    ->where('updated_at', '>=', now()->subMinutes(20))
                    ->count();
                $queued += $sourceClass::where('ingest_status', 'queued')->count();
                $retrying += $sourceClass::where('ingest_status', 'retrying')->count();
                $failed += $sourceClass::where('ingest_status', 'failed')->count();
            }
        } catch (\Throwable $e) {
            // Keep the settings page usable during a migration.
        }

        $connectionsInUse = 0;
        $totalSpeedBps = 0;

        try {
            foreach ([EpisodeSource::class, ContentSource::class] as $sourceClass) {
                $aggregate = $sourceClass::whereIn('ingest_status', ['downloading', 'uploading'])
                    ->where('updated_at', '>=', now()->subMinutes(20))
                    ->selectRaw(
                        "SUM(CASE WHEN ingest_status = 'downloading' THEN COALESCE(ingest_connections, 0) ELSE 0 END) as connections, "
                        . "SUM(CASE WHEN ingest_status = 'uploading' THEN COALESCE(upload_speed_bps, 0) ELSE COALESCE(ingest_speed_bps, 0) END) as speed_bps"
                    )
                    ->first();

                if ($aggregate) {
                    $connectionsInUse += max(0, (int) $aggregate->connections);
                    $totalSpeedBps += max(0, (int) $aggregate->speed_bps);
                }
            }
        } catch (\Throwable $e) {
            // The page still works while a deploy is adding the column.
        }

        $live = 0;
        $desired = max(1, (int) config('media_relay.ingest.concurrency', 1));
        $roleCounts = [];

        // The heartbeat already publishes live and target per lane; the card
        // shows each lane on its own so a stopped bot pool is visible while
        // download and upload keep running.
        foreach (RelayQueueSupervisor::ROLES as $role) {
            $roleCounts[$role] = ['live' => 0, 'desired' => 0];
        }

        $heartbeatAt = null;
        $heartbeatAge = null;
        $heartbeatLabel = __('relayRuntimeHeartbeatNever');
        $supervisorDown = true;

        try {
            $heartbeat = Cache::get(RelayQueueSupervisor::STATUS_KEY);

            if (is_array($heartbeat)) {
                $desired = max(0, (int) ($heartbeat['desired'] ?? $desired));
                $beatAt = !empty($heartbeat['updated_at']) ? Carbon::parse($heartbeat['updated_at']) : null;

                if ($beatAt) {
                    $heartbeatAt = $beatAt->toIso8601String();
                    $heartbeatAge = max(0, now()->getTimestamp() - $beatAt->getTimestamp());
                    $heartbeatLabel = $heartbeatAge <= 2
                        ? __('relayRuntimeHeartbeatNow')
                        : __('relayRuntimeHeartbeatAgo', ['seconds' => $heartbeatAge]);

                    if ($heartbeatAge <= 20) {
                        $live = max(0, (int) ($heartbeat['workers'] ?? 0));
                        $supervisorDown = false;

                        foreach ($roleCounts as $role => $_) {
                            $roleCounts[$role] = [
                                'live' => max(0, (int) ($heartbeat[$role . '_workers'] ?? 0)),
                                'desired' => max(0, (int) ($heartbeat[$role . '_desired'] ?? 0)),
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // No heartbeat available; report 0 live and show the alert.
        }

        $diskMax = max(1, min(4, (int) config('media_relay.ingest.concurrency', 1)));
        $disk = DiskBudget::status($diskMax);

        return [
            'disk_free_gb' => round($disk['free'] / 1073741824, 1),
            'disk_budget_gb' => round($disk['budget'] / 1073741824, 1),
            'disk_ingest_gb' => round($disk['ingest'] / 1073741824, 1),
            'disk_percent' => (int) $disk['percent'],
            'disk_downloads' => (int) $disk['downloads'],
            'active' => $active,
            'queued' => $queued,
            'retrying' => $retrying,
            'failed' => $failed,
            'workers_live' => $live,
            'workers_desired' => $desired,
            'roles' => $roleCounts,
            'heartbeat_at' => $heartbeatAt,
            'heartbeat_age_s' => $heartbeatAge,
            'heartbeat_label' => $heartbeatLabel,
            'supervisor_down' => $supervisorDown,
            'connections_in_use' => $connectionsInUse,
            'total_speed_bps' => $totalSpeedBps,
            'total_speed' => $this->humanSpeed($totalSpeedBps),
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

        RelaySettings::save($setting, $this->relayCardInput($request));

        return response()->json([
            'status' => true,
            'message' => 'Relay settings updated successfully',
        ]);
    }

    /**
     * Bot card badges: the Bot API answers online/offline plus the webhook
     * Telegram currently has, measured against the URL this panel would use.
     *
     * Read-only: it never writes the card, so a badge only describes what is
     * stored. Edits in the boxes reach Telegram through Save or Set webhook.
     */
    public function botHealth(Request $request)
    {
        $bot = new TelegramBotClient();
        $health = $bot->health();

        $info = ['url' => '', 'pending' => 0, 'last_error' => ''];

        if ($health['ok']) {
            try {
                $info = $bot->webhookInfo();
            } catch (\Throwable $e) {
                $info['last_error'] = $e->getMessage();
            }
        }

        $secret = trim((string) config('media_relay.bot.webhook_secret'));
        $expected = $secret !== '' ? $this->botWebhookUrl($secret) : '';
        $registered = (string) $info['url'];

        return response()->json([
            'status' => true,
            'enabled' => (bool) config('media_relay.bot.enabled'),
            'token_set' => (string) config('media_relay.bot.token') !== '',
            'secret_set' => $secret !== '',
            // sync runs the import inside the request and null drops it: both
            // leave no worker to pick the job up.
            'queue_connection' => BotImportQueue::driver(),
            'queue_sync' => BotImportQueue::runsInline(),
            'ok' => $health['ok'],
            'message' => $health['message'],
            // The secret is the path segment of this URL: the panel says whether
            // Telegram has the right one, never what it is.
            'webhook_url' => $this->maskSecret($registered, $secret),
            'webhook_ok' => $expected !== '' && $registered === $expected,
            'expected_webhook_url' => $this->maskSecret($expected, $secret),
            'pending_updates' => (int) $info['pending'],
            'last_error' => (string) $info['last_error'],
        ]);
    }

    /**
     * Save the card, then register its secret as both the webhook path and the
     * header: Telegram and the webhook route must never hold different values.
     */
    public function setBotWebhook(Request $request)
    {
        $error = $this->saveBotCard($request);

        if ($error !== null) {
            return $error;
        }

        $secret = trim((string) config('media_relay.bot.webhook_secret'));

        if ($secret === '') {
            return response()->json([
                'status' => false,
                'message' => 'webhook secret is empty: fill it in and save the card first',
            ]);
        }

        $url = $this->botWebhookUrl($secret);

        try {
            $bot = new TelegramBotClient();
            $bot->setWebhook($url, $secret);
            // The "/" menu lives on Telegram's side, so it is registered here
            // with the webhook rather than re-sent on every message.
            $bot->setMyCommands(TelegramBotClient::COMMANDS);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Card saved, webhook and command menu set: ' . $this->maskSecret($url, $secret),
            'webhook_url' => $this->maskSecret($url, $secret),
        ]);
    }

    public function deleteBotWebhook(Request $request)
    {
        // Same reason as set: the token typed here has to be stored, not left
        // in the browser, or the next call would use a different bot.
        $error = $this->saveBotCard($request);

        if ($error !== null) {
            return $error;
        }

        try {
            $bot = new TelegramBotClient();
            $bot->deleteWebhook();
            // The "/" menu lives on Telegram's side and outlives the webhook:
            // left as is, it keeps offering commands nothing answers any more.
            $bot->setMyCommands([]);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['status' => true, 'message' => 'Card saved, webhook and command menu removed.']);
    }

    /**
     * The card payload every save path shares: blank secret boxes keep their
     * stored value, and a flag missing from the request keeps its stored state
     * (unchecking a toggle posts the hidden 0).
     *
     * @return array<string, mixed>
     */
    protected function relayCardInput(Request $request): array
    {
        $input = RelaySettings::withoutBlankSecrets(RelaySettings::filterInput($request->all()));

        foreach (['relay_enabled', 'relay_bot_enabled'] as $flag) {
            if ($request->has($flag)) {
                $input[$flag] = $request->boolean($flag);
            }
        }

        return $input;
    }

    /**
     * Persist the bot card before any Bot API call the card makes, so what is
     * checked or registered is what this install stores. Returns the error
     * response to send back, or null to go on.
     */
    protected function saveBotCard(Request $request): ?\Illuminate\Http\JsonResponse
    {
        try {
            RelaySettings::save(GlobalSettings::first() ?: $this->settingsRow(), $this->relayCardInput($request));
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'could not save the bot card, so nothing was sent: ' . $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * The row the panel writes to when the install has none yet.
     *
     * A migrated-only schema (fresh install, the CI test database) starts
     * without a global_settings row, and the migration declares
     * is_live_tv_enable NOT NULL with no default. The first card save is an
     * insert, so it has to carry that column or the save fails on SQLite and on
     * strict MySQL alike.
     */
    protected function settingsRow(): GlobalSettings
    {
        $settings = new GlobalSettings();
        $settings->is_live_tv_enable = 0;

        return $settings;
    }

    protected function botWebhookUrl(string $secret): string
    {
        return route('telegramWebhook', ['secret' => $secret]);
    }

    /** Display form of a webhook URL; the panel never returns the secret itself. */
    protected function maskSecret(string $url, string $secret): string
    {
        return $secret === '' || $url === '' ? $url : str_replace($secret, '***', $url);
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
