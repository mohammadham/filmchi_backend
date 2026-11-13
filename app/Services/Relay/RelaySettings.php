<?php

namespace App\Services\Relay;

use App\GlobalSettings;

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
        'relay_ingest_max_workers' => ['media_relay.ingest.max_workers', 'int'],
        'relay_ingest_max_pending_chunks' => ['media_relay.ingest.max_pending_chunks', 'int'],
        'relay_ingest_chunk_mb' => ['media_relay.ingest.chunk_mb', 'int'],
        'relay_ingest_streams' => ['media_relay.ingest.streams_per_worker', 'int'],
        'relay_ingest_connections' => ['media_relay.ingest.connections', 'int'],
        'relay_ingest_concurrency' => ['media_relay.ingest.concurrency', 'concurrency'],
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
    ];

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
            'relay_ingest_max_workers' => (int) config('media_relay.ingest.max_workers'),
            'relay_ingest_max_pending_chunks' => (int) config('media_relay.ingest.max_pending_chunks'),
            'relay_ingest_chunk_mb' => (int) config('media_relay.ingest.chunk_mb'),
            'relay_ingest_streams' => (int) config('media_relay.ingest.streams_per_worker'),
            'relay_ingest_connections' => (int) config('media_relay.ingest.connections'),
            'relay_ingest_concurrency' => max(1, min(4, (int) config('media_relay.ingest.concurrency', 1))),
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
            case 'kbps':
                // The panel edits KB/s because 50000 B/s is awkward to type;
                // the registry still consumes bytes per second.
                return max(0, (int) $value) * 1000;
            case 'mode':
                $mode = strtolower((string) $value);

                return in_array($mode, ['auto', 'share', 'drive'], true) ? $mode : 'auto';
            case 'strategy':
                $strategy = strtolower((string) $value);

                return in_array($strategy, ['round_robin', 'fastest', 'least_used'], true)
                    ? $strategy
                    : 'round_robin';
            default:
                return (string) $value;
        }
    }
}
