<?php

namespace App\Services\Relay;

use App\GlobalSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Bridges the relay connection settings stored in the panel (global_settings)
 * onto the media_relay config, so every existing client keeps reading config()
 * while the values can be edited from the web panel.
 *
 * Precedence: explicit overrides > saved panel values > .env / config defaults.
 */
class RelaySettings
{
    /**
     * Column => [config key, type].
     *
     * @var array<string, array{0:string,1:string}>
     */
    protected static $map = [
        'relay_enabled' => ['media_relay.enabled', 'bool'],
        'relay_worker_enabled' => ['media_relay.workers.enabled', 'bool'],
        'relay_worker_selection_strategy' => ['media_relay.workers.selection_strategy', 'strategy'],
        'relay_worker_auto_disable' => ['media_relay.workers.auto_disable', 'bool'],
        'relay_worker_failure_threshold' => ['media_relay.workers.failure_threshold', 'positive'],
        'relay_worker_failure_window' => ['media_relay.workers.failure_window_seconds', 'positive'],
        'relay_worker_backoff_base' => ['media_relay.workers.backoff_base_seconds', 'positive'],
        'relay_worker_backoff_max' => ['media_relay.workers.backoff_max_seconds', 'positive'],
        'relay_worker_probe_success_threshold' => ['media_relay.workers.probe_success_threshold', 'positive'],
        'relay_worker_probe_interval' => ['media_relay.workers.probe_interval_seconds', 'positive'],
        'relay_upload_concurrency' => ['media_relay.upload.concurrency', 'concurrency'],
        'relay_ingest_max_workers' => ['media_relay.ingest.max_workers', 'int'],
        'relay_ingest_max_pending_chunks' => ['media_relay.ingest.max_pending_chunks', 'int'],
        'relay_ingest_chunk_mb' => ['media_relay.ingest.chunk_mb', 'int'],
        'relay_ingest_streams' => ['media_relay.ingest.streams_per_worker', 'int'],
        'relay_ingest_connections' => ['media_relay.ingest.connections', 'int'],
        'relay_ingest_total_connections' => ['media_relay.ingest.total_connections', 'int'],
        'relay_ingest_concurrency' => ['media_relay.ingest.concurrency', 'concurrency'],
        'relay_ingest_disk_percent' => ['media_relay.ingest.disk_percent', 'percent'],
        'relay_ingest_retry_max_attempts' => ['media_relay.ingest.retry_max_attempts', 'attempts'],
        'relay_ingest_retry_delay' => ['media_relay.ingest.retry_delay_seconds', 'positive'],
        'relay_upload_retry_max_attempts' => ['media_relay.upload.retry_max_attempts', 'attempts'],
        'relay_upload_retry_delay' => ['media_relay.upload.retry_delay_seconds', 'positive'],
        'relay_ingest_chunk_retry_budget' => ['media_relay.ingest.chunk_retry_budget', 'positive'],
        'relay_ingest_chunk_stall_timeout' => ['media_relay.ingest.chunk_stall_timeout', 'positive'],
        'relay_ingest_speed_check_seconds' => ['media_relay.ingest.speed_check_seconds', 'speed_seconds'],
        'relay_ingest_speed_window_samples' => ['media_relay.ingest.speed_window_samples', 'positive'],
        'relay_ingest_speed_drop_ratio' => ['media_relay.ingest.speed_drop_ratio', 'ratio'],
        'relay_ingest_speed_drop_confirmations' => ['media_relay.ingest.speed_drop_confirmations', 'positive'],
        'relay_ingest_speed_reprobe_chunks' => ['media_relay.ingest.speed_reprobe_chunks', 'positive'],
        'relay_ingest_speed_reprobe_cooldown' => ['media_relay.ingest.speed_reprobe_cooldown_seconds', 'positive'],
        'relay_ingest_stall_timeout' => ['media_relay.ingest.stall_timeout', 'positive'],
        'relay_ingest_stream_min_kbps' => ['media_relay.ingest.min_stream_kbps', 'int'],
        'relay_ingest_stream_stall_seconds' => ['media_relay.ingest.stream_stall_seconds', 'positive'],
        'relay_ingest_pool_refresh_seconds' => ['media_relay.ingest.pool_refresh_seconds', 'positive'],
        'relay_ingest_timeout' => ['media_relay.ingest.timeout', 'positive'],
        'relay_ingest_min_chunk_mb' => ['media_relay.ingest.min_chunk_mb', 'chunk_mb'],
        'relay_ingest_max_chunk_mb' => ['media_relay.ingest.max_chunk_mb', 'chunk_mb'],
        'relay_worker_probe_concurrency' => ['media_relay.workers.probe_concurrency', 'probe_concurrency'],
        'relay_worker_min_speed_kbps' => ['media_relay.workers.min_speed_bps', 'kbps'],
        'relay_worker_max_latency_ms' => ['media_relay.workers.max_latency_ms', 'int'],
        'relay_tg_base_url' => ['media_relay.telegram.base_url', 'string'],
        'relay_tg_username' => ['media_relay.telegram.username', 'string'],
        'relay_tg_password' => ['media_relay.telegram.password', 'string'],
        'relay_tg_api_key' => ['media_relay.telegram.api_key', 'string'],
        'relay_tg_storage_chat' => ['media_relay.telegram.storage_chat', 'string'],
        'relay_nine_base_url' => ['media_relay.ninedrive.base_url', 'string'],
        'relay_nine_api_key' => ['media_relay.ninedrive.api_key', 'string'],
        'relay_nine_email' => ['media_relay.ninedrive.email', 'string'],
        'relay_nine_password' => ['media_relay.ninedrive.password', 'string'],
        'relay_nine_folder_id' => ['media_relay.ninedrive.folder_id', 'string'],
        'relay_nine_frontend_url' => ['media_relay.ninedrive.frontend_url', 'string'],
        'relay_nine_serve_mode' => ['media_relay.ninedrive.serve_mode', 'mode'],
        'relay_promote_threshold' => ['media_relay.cache.promote_threshold', 'int'],
        'relay_demand_window' => ['media_relay.cache.window', 'int'],
        'relay_bot_enabled' => ['media_relay.bot.enabled', 'bool'],
        'relay_bot_token' => ['media_relay.bot.token', 'string'],
        'relay_bot_webhook_secret' => ['media_relay.bot.webhook_secret', 'string'],
        'relay_bot_allowed_ids' => ['media_relay.bot.allowed_ids', 'string'],
        'relay_bot_storage_chat' => ['media_relay.bot.storage_chat', 'string'],
        'relay_bot_channel_id' => ['media_relay.bot.channel_id', 'int'],
        'relay_bot_quality' => ['media_relay.bot.quality', 'string'],
        'relay_bot_daily_limit' => ['media_relay.bot.daily_limit', 'int'],
        'relay_bot_max_size_mb' => ['media_relay.bot.max_size_mb', 'int'],
    ];

    /**
     * Connection secrets. Their inputs render empty, so a blank box means
     * "keep the stored value" rather than "erase it".
     *
     * @var array<int, string>
     */
    public const SECRET_COLUMNS = [
        'relay_tg_password', 'relay_tg_api_key', 'relay_nine_password', 'relay_nine_api_key',
        'relay_bot_token', 'relay_bot_webhook_secret',
    ];

    /**
     * Drop the blank secret boxes from a filtered card payload.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function withoutBlankSecrets(array $values): array
    {
        foreach (self::SECRET_COLUMNS as $column) {
            if (array_key_exists($column, $values) && trim((string) $values[$column]) === '') {
                unset($values[$column]);
            }
        }

        return $values;
    }

    /**
     * Columns that may be saved from the panel.
     *
     * @return array<int, string>
     */
    public static function columns(): array
    {
        return array_keys(static::$map);
    }

    /**
     * Values currently in effect for the settings VIEW.
     *
     * Connection secrets are never returned to the browser: the inputs render
     * empty and blank submissions keep the stored value (see save()). The
     * *_set booleans let the view show whether a secret exists.
     *
     * @return array<string, mixed>
     */
    public static function effective(): array
    {
        return [
            'relay_enabled' => (bool) config('media_relay.enabled'),
            'relay_worker_enabled' => (bool) config('media_relay.workers.enabled'),
            'relay_worker_selection_strategy' => (string) config('media_relay.workers.selection_strategy'),
            'relay_worker_auto_disable' => (bool) config('media_relay.workers.auto_disable', true),
            'relay_worker_failure_threshold' => max(1, (int) config('media_relay.workers.failure_threshold', 3)),
            'relay_worker_failure_window' => max(30, (int) config('media_relay.workers.failure_window_seconds', 600)),
            'relay_worker_backoff_base' => max(1, (int) config('media_relay.workers.backoff_base_seconds', 60)),
            'relay_worker_backoff_max' => max(1, (int) config('media_relay.workers.backoff_max_seconds', 3600)),
            'relay_worker_probe_success_threshold' => max(1, (int) config('media_relay.workers.probe_success_threshold', 2)),
            'relay_worker_probe_interval' => max(10, (int) config('media_relay.workers.probe_interval_seconds', 60)),
            'relay_upload_concurrency' => max(1, min(4, (int) config('media_relay.upload.concurrency', 2))),
            'relay_ingest_max_workers' => (int) config('media_relay.ingest.max_workers'),
            'relay_ingest_max_pending_chunks' => (int) config('media_relay.ingest.max_pending_chunks'),
            'relay_ingest_chunk_mb' => (int) config('media_relay.ingest.chunk_mb'),
            'relay_ingest_streams' => (int) config('media_relay.ingest.streams_per_worker'),
            'relay_ingest_connections' => (int) config('media_relay.ingest.connections'),
            'relay_ingest_total_connections' => (int) config('media_relay.ingest.total_connections', 0),
            'relay_ingest_concurrency' => max(1, min(4, (int) config('media_relay.ingest.concurrency', 1))),
            'relay_ingest_disk_percent' => max(0, min(95, (int) config('media_relay.ingest.disk_percent', 50))),
            'relay_ingest_retry_max_attempts' => max(1, min(10, (int) config('media_relay.ingest.retry_max_attempts', 3))),
            'relay_ingest_retry_delay' => max(1, (int) config('media_relay.ingest.retry_delay_seconds', 300)),
            'relay_upload_retry_max_attempts' => max(1, min(10, (int) config('media_relay.upload.retry_max_attempts', 3))),
            'relay_upload_retry_delay' => max(1, (int) config('media_relay.upload.retry_delay_seconds', 120)),
            'relay_ingest_chunk_retry_budget' => max(30, (int) config('media_relay.ingest.chunk_retry_budget', 180)),
            'relay_ingest_chunk_stall_timeout' => max(10, (int) config('media_relay.ingest.chunk_stall_timeout', 60)),
            'relay_ingest_speed_check_seconds' => max(3, (float) config('media_relay.ingest.speed_check_seconds', 5)),
            'relay_ingest_speed_window_samples' => max(2, (int) config('media_relay.ingest.speed_window_samples', 6)),
            'relay_ingest_speed_drop_ratio' => max(0.10, min(1.0, (float) config('media_relay.ingest.speed_drop_ratio', 0.70))),
            'relay_ingest_speed_drop_confirmations' => max(1, (int) config('media_relay.ingest.speed_drop_confirmations', 2)),
            'relay_ingest_speed_reprobe_chunks' => max(1, (int) config('media_relay.ingest.speed_reprobe_chunks', 2)),
            'relay_ingest_speed_reprobe_cooldown' => max(5, (int) config('media_relay.ingest.speed_reprobe_cooldown_seconds', 30)),
            'relay_ingest_stall_timeout' => max(30, (int) config('media_relay.ingest.stall_timeout', 300)),
            'relay_ingest_stream_min_kbps' => max(0, (int) config('media_relay.ingest.min_stream_kbps', 64)),
            'relay_ingest_stream_stall_seconds' => max(30, (int) config('media_relay.ingest.stream_stall_seconds', 180)),
            'relay_ingest_pool_refresh_seconds' => max(3, (int) config('media_relay.ingest.pool_refresh_seconds', 10)),
            'relay_ingest_timeout' => max(60, (int) config('media_relay.ingest.timeout', 3600)),
            'relay_ingest_min_chunk_mb' => max(1, min(64, (int) config('media_relay.ingest.min_chunk_mb', 1))),
            'relay_ingest_max_chunk_mb' => max(1, min(64, (int) config('media_relay.ingest.max_chunk_mb', 16))),
            'relay_worker_probe_concurrency' => max(1, min(2, (int) config('media_relay.workers.probe_concurrency', 1))),
            'relay_worker_min_speed_kbps' => (int) round(((int) config('media_relay.workers.min_speed_bps', 0)) / 1000),
            'relay_worker_max_latency_ms' => (int) config('media_relay.workers.max_latency_ms', 0),
            'relay_tg_base_url' => (string) config('media_relay.telegram.base_url'),
            'relay_tg_username' => (string) config('media_relay.telegram.username'),
            'relay_tg_password' => '',
            'relay_tg_password_set' => (string) config('media_relay.telegram.password') !== '',
            'relay_tg_api_key' => '',
            'relay_tg_api_key_set' => (string) config('media_relay.telegram.api_key') !== '',
            'relay_tg_storage_chat' => (string) config('media_relay.telegram.storage_chat'),
            'relay_nine_base_url' => (string) config('media_relay.ninedrive.base_url'),
            'relay_nine_api_key' => '',
            'relay_nine_api_key_set' => (string) config('media_relay.ninedrive.api_key') !== '',
            'relay_nine_email' => (string) config('media_relay.ninedrive.email'),
            'relay_nine_password' => '',
            'relay_nine_password_set' => (string) config('media_relay.ninedrive.password') !== '',
            'relay_nine_folder_id' => (string) config('media_relay.ninedrive.folder_id'),
            'relay_nine_frontend_url' => (string) config('media_relay.ninedrive.frontend_url'),
            'relay_nine_serve_mode' => (string) config('media_relay.ninedrive.serve_mode'),
            'relay_promote_threshold' => (int) config('media_relay.cache.promote_threshold'),
            'relay_demand_window' => (int) config('media_relay.cache.window'),
            'relay_bot_enabled' => (bool) config('media_relay.bot.enabled', false),
            'relay_bot_token' => '',
            'relay_bot_token_set' => (string) config('media_relay.bot.token') !== '',
            'relay_bot_webhook_secret' => '',
            'relay_bot_webhook_secret_set' => (string) config('media_relay.bot.webhook_secret') !== '',
            'relay_bot_allowed_ids' => (string) config('media_relay.bot.allowed_ids'),
            'relay_bot_storage_chat' => (string) config('media_relay.bot.storage_chat'),
            'relay_bot_channel_id' => (int) config('media_relay.bot.channel_id', 0),
            'relay_bot_quality' => (string) config('media_relay.bot.quality', '1080'),
            'relay_bot_daily_limit' => (int) config('media_relay.bot.daily_limit', 20),
            // 0 is a real choice: no ceiling (see RELAY_BOT_MAX_SIZE_MB).
            'relay_bot_max_size_mb' => max(0, (int) config('media_relay.bot.max_size_mb', 2048)),
        ];
    }

    /**
     * Apply the saved (and optionally unsaved) panel values onto the config.
     *
     * @param  GlobalSettings|null  $setting
     * @param  array<string, mixed> $overrides  keyed by column name
     */
    public static function apply($setting = null, array $overrides = []): void
    {
        if ($setting === null) {
            try {
                $setting = GlobalSettings::first();
            } catch (\Throwable $e) {
                $setting = null;
            }
        }

        foreach (static::$map as $column => list($key, $type)) {
            $value = array_key_exists($column, $overrides)
                ? $overrides[$column]
                : ($setting ? $setting->{$column} : null);

            if ($value === null || $value === '') {
                continue;
            }

            $cast = static::cast($value, $type);

            if ($cast === null) {
                continue;
            }

            config([$key => $cast]);
        }
    }

    /**
     * Keep only the relay columns present in the request.
     *
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function filterInput(array $input): array
    {
        $out = [];

        foreach (static::columns() as $column) {
            if (array_key_exists($column, $input)) {
                $out[$column] = $input[$column];
            }
        }

        return $out;
    }

    /**
     * Persist panel values, then make them effective for the current request.
     *
     * @param  GlobalSettings       $setting
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function save(GlobalSettings $setting, array $input): array
    {
        $values = static::filterInput($input);

        foreach ($values as $column => $value) {
            if (!array_key_exists($column, static::$map)) {
                continue;
            }

            $setting->{$column} = $value;
        }

        $setting->save();

        static::apply($setting);
        Cache::forget(DiskBudget::CACHE_KEY . ':typical');

        return $values;
    }

    /**
     * @param  mixed  $value
     * @param  string $type
     * @return mixed
     */
    protected static function cast($value, string $type)
    {
        switch ($type) {
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'int':
                return max(0, (int) $value);
            case 'concurrency':
                return max(1, min(4, (int) $value));
            case 'percent':
                return max(0, min(95, (int) $value));
            case 'positive':
                return max(1, (int) $value);
            case 'attempts':
                return max(1, min(10, (int) $value));
            case 'chunk_mb':
                return max(1, min(64, (int) $value));
            case 'probe_concurrency':
                return max(1, min(2, (int) $value));
            case 'speed_seconds':
                return max(3, (float) $value);
            case 'ratio':
                return max(0.10, min(1.0, (float) $value));
            case 'kbps':
                // The panel edits KB/s because 50000 B/s is awkward to type;
                // the registry still consumes bytes per second.
                return max(0, (int) $value) * 1000;
            case 'mode':
                $mode = strtolower((string) $value);

                return in_array($mode, ['auto', 'share', 'drive'], true) ? $mode : 'auto';
            case 'strategy':
                $strategy = strtolower((string) $value);

                return in_array($strategy, ['round_robin', 'fastest', 'least_used', 'chunk_speed'], true)
                    ? $strategy
                    : 'round_robin';
            default:
                return (string) $value;
        }
    }
}
