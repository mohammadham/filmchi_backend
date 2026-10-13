<?php

/*
|--------------------------------------------------------------------------
| Media Relay (Telegram Drive primary storage + 9drive cache)
|--------------------------------------------------------------------------
|
| Files are uploaded to the Telegram Drive service (primary / cold storage).
| When a file is requested often enough, it is promoted into the 9drive
| cache (Google Drive) so that users stream/download from the faster tier.
|
| The site itself never stores file bytes: both upload and download are
| streamed straight through, and public links are served on the site domain.
|
*/

return [

    // Master switch for the whole subsystem.
    'enabled' => env('RELAY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Telegram Drive — primary storage (FastAPI "TelegramDrive")
    |--------------------------------------------------------------------------
    */
    'telegram' => [
        'base_url'      => env('TGDRIVE_BASE_URL', ''),
        'username'      => env('TGDRIVE_USERNAME', 'admin'),
        'password'      => env('TGDRIVE_PASSWORD', ''),
        // Optional static API key (see /api/v1/keys). When set, login is skipped.
        'api_key'       => env('TGDRIVE_API_KEY', ''),
        'storage_chat'  => env('TGDRIVE_STORAGE_CHAT', ''),
        'default_backend' => env('TGDRIVE_DEFAULT_BACKEND', 'telegram'),
        'timeout'       => (int) env('TGDRIVE_TIMEOUT', 300),
        'connect_timeout' => (int) env('TGDRIVE_CONNECT_TIMEOUT', 15),
        // How long an issued presigned link is asked to live (seconds).
        'link_ttl'      => (int) env('TGDRIVE_LINK_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | 9drive — cache tier (Google Drive gateway)
    |--------------------------------------------------------------------------
    */
    'ninedrive' => [
        'base_url'   => env('NINEDRIVE_BASE_URL', ''),
        // Required for POST /api/v1/uploads  (needs the "files:upload" scope).
        'api_key'    => env('NINEDRIVE_API_KEY', ''),
        // Optional panel credentials, only needed to mint share/preview links.
        'email'      => env('NINEDRIVE_EMAIL', ''),
        'password'   => env('NINEDRIVE_PASSWORD', ''),
        // Target virtual folder inside the "9drive" Drive root (optional).
        'folder_id'  => env('NINEDRIVE_FOLDER_ID', ''),
        // Public frontend base used for share URLs; falls back to base_url.
        'frontend_url' => env('NINEDRIVE_FRONTEND_URL', ''),
        'timeout'    => (int) env('NINEDRIVE_TIMEOUT', 600),
        'connect_timeout' => (int) env('NINEDRIVE_CONNECT_TIMEOUT', 15),
        /*
         * How cached files are served:
         *   share  → 9drive public share/preview endpoints (needs credentials)
         *   drive  → the public Google Drive link created by 9drive at upload
         *   auto   → try share, fall back to drive
         */
        'serve_mode' => env('NINEDRIVE_SERVE_MODE', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Relay links served on the site's own domain
    |--------------------------------------------------------------------------
    */
    'relay' => [
        // Path template for streaming and downloading. {token} is replaced.
        'stream_path'   => env('RELAY_STREAM_PATH', 'r/{token}'),
        'download_path' => env('RELAY_DOWNLOAD_PATH', 'd/{token}'),
        // Default lifetime of a public relay link in seconds (0 = never expires).
        'link_ttl'      => (int) env('RELAY_LINK_TTL', 0),
        /*
         * true  → bytes are proxied through this server (upstream stays hidden,
         *         nothing is ever written to disk)
         * false → the viewer is redirected straight to the signed upstream URL
         */
        'proxy'         => (bool) env('RELAY_PROXY_STREAM', true),
        'chunk_size'    => (int) env('RELAY_CHUNK_SIZE', 262144),
        // Seconds allowed for one upstream transfer. Kept below the platform
        // socket limit (PHP refuses > 2147s on Windows) by Streams::open().
        'stream_timeout' => (int) env('RELAY_STREAM_TIMEOUT', 1800),
        'user_agent'    => env('RELAY_USER_AGENT', 'flixy-media-relay/1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demand tracking + promotion policy ("redis managed" tiering)
    |--------------------------------------------------------------------------
    |
    | Set CACHE_DRIVER=redis (or RELAY_STATE_STORE=redis) to keep the demand
    | counters in Redis. Any Laravel cache store works; the counters are
    | advisory, the durable state lives in the database.
    |
    */
    'cache' => [
        // null = use the application's default cache store.
        'store'            => env('RELAY_STATE_STORE', null),
        'key_prefix'       => env('RELAY_STATE_PREFIX', 'relay:demand'),
        // Requests inside the window before a file is promoted to the cache.
        'promote_threshold' => (int) env('RELAY_PROMOTE_THRESHOLD', 3),
        // Rolling window (seconds) in which demand is counted.
        'window'           => (int) env('RELAY_DEMAND_WINDOW', 86400),
        // Keep warm copies forever by default (0 = no eviction).
        'cache_ttl'        => (int) env('RELAY_CACHE_TTL', 0),
        'auto_promote'     => (bool) env('RELAY_AUTO_PROMOTE', true),
        // Cooldown between promotion attempts for one file (seconds).
        'retry_cooldown'   => (int) env('RELAY_RETRY_COOLDOWN', 3600),
        'max_attempts'     => (int) env('RELAY_MAX_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue used for background promotion jobs
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('RELAY_QUEUE_CONNECTION', null),
        'name'       => env('RELAY_QUEUE_NAME', 'default'),
    ],

];
