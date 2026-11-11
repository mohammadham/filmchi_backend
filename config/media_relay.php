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
    | URL ingestion (pull remote links into the relay)
    |--------------------------------------------------------------------------
    | Sources added with the "ingest" option are queued: the worker downloads
    | the file, dedup-checks it and uploads to Telegram Drive, then points
    | the source at the relay link. Ingests run serially (one worker) so the
    | server's bandwidth is not shredded by parallel pulls.
    */
    'ingest' => [
        'enabled' => (bool) env('RELAY_INGEST', true),
        'timeout' => (int) env('RELAY_INGEST_TIMEOUT', 3600),
        // Seconds a download may go without a single byte before it is treated
        // as dead. Per read, so a slow source is unaffected.
        'stall_timeout' => (int) env('RELAY_INGEST_STALL_TIMEOUT', 300),
        // The scraper host throttles each connection hard, so one file is fetched
        // over several ranged connections. Sustained throughput measured on
        // di.farsanime.ir: 8 conns = 327 KB/s, 24 = 838, 32 = 925, 48 = 1072.
        // Twenty-four keeps most of the gain without a socket pile per file.
        'connections' => (int) env('RELAY_INGEST_CONNECTIONS', 24),
        // Fixed range size for the dynamic chunk queue. Small chunks let a
        // retry repeat only a few MB instead of a hundred-plus MB slice.
        'chunk_mb' => (int) env('RELAY_INGEST_CHUNK_MB', 8),
        // An 8 MB chunk that receives no byte for this long is dead; waiting
        // the old full-file stall timeout would hold a slot for minutes.
        'chunk_stall_timeout' => (int) env('RELAY_INGEST_CHUNK_STALL_TIMEOUT', 60),
        // Target number of concurrent streams per Worker. The total is still
        // capped by RELAY_INGEST_CONNECTIONS.
        'streams_per_worker' => (int) env('RELAY_WORKER_STREAMS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Worker proxy for source downloads
    |--------------------------------------------------------------------------
    | The panel can manage several Worker endpoints. Only links on the hosts
    | below are routed through them; everything else keeps using the direct
    | source so an unrelated URL cannot be sent to a Worker with a different
    | allow-list. Requests use the healthy pool ordered by measured throughput,
    | latency and priority; a failed endpoint is cooled down and retried.
    */
    'workers' => [
        'enabled' => (bool) env('RELAY_WORKERS_ENABLED', true),
        'hosts' => env('RELAY_WORKER_HOSTS', 'farsanime.ir,di.farsanime.ir'),
        'test_url' => env('RELAY_WORKER_TEST_URL', 'https://farsanime.ir/sitemap.xml'),
        'timeout' => (int) env('RELAY_WORKER_TIMEOUT', 20),
        // A failed worker is skipped for this long before it is tried again.
        'cooldown_seconds' => (int) env('RELAY_WORKER_COOLDOWN', 120),
        // A cold Worker/origin answers the first ranged request with a full
        // 200 response. Read a little, wait, and ask again once.
        'cold_retry_delay_ms' => (int) env('RELAY_WORKER_COLD_RETRY_DELAY_MS', 2000),
        'warmup_bytes' => (int) env('RELAY_WORKER_WARMUP_BYTES', 1048576),
        // Optional admission thresholds. 0 keeps a freshly added Worker
        // eligible until it has real throughput samples.
        'min_speed_bps' => (int) env('RELAY_WORKER_MIN_SPEED_BPS', 0),
        'max_latency_ms' => (int) env('RELAY_WORKER_MAX_LATENCY_MS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automation API (n8n orchestrator)
    |--------------------------------------------------------------------------
    | These endpoints let an external scheduler (n8n) read the ingest backlog
    | and queue the next slice of sources without an admin session. The shared
    | token is required; an empty value disables the endpoints entirely.
    |
    | The disk floor stops the workflow long before the worker fills the disk,
    | and max_batch caps how much work may sit in the queue at once.
    */
    'automation' => [
        'token' => env('RELAY_AUTOMATION_TOKEN', ''),
        'min_free_gb' => (int) env('RELAY_AUTOMATION_MIN_FREE_GB', 8),
        'max_batch' => (int) env('RELAY_AUTOMATION_MAX_BATCH', 3),

        /*
         * How often the orchestrator may start a catalogue pass (new series,
         * new episodes/qualities). The pass runs as a detached CLI process, so
         * it never sits in front of the download queue; sleep_ms keeps its
         * series-page crawl gentle on the source site.
         */
        'catalogue' => [
            'interval_hours' => (int) env('RELAY_AUTOMATION_CATALOGUE_HOURS', 6),
            'sleep_ms' => (int) env('RELAY_AUTOMATION_CATALOGUE_SLEEP', 250),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | External link health checks
    |--------------------------------------------------------------------------
    | sources:check probes external source links; a link is declared dead
    | only after this many consecutive failures, and dead links are hidden
    | from the app while they stay visible in the panel.
    */
    'linkcheck' => [
        'timeout' => (int) env('RELAY_LINKCHECK_TIMEOUT', 15),
        'dead_after' => (int) env('RELAY_LINKCHECK_DEAD_AFTER', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    | Maximum accepted upload size in megabytes, enforced by the panel
    | endpoints before the bytes ever leave the browser. Keep this at or
    | below the nginx client_max_body_size (2048m in the image).
    */
    'upload' => [
        'max_size' => (int) env('RELAY_MAX_UPLOAD_MB', 2048),
    ],

    /*
    |--------------------------------------------------------------------------
    | Poster frames
    |--------------------------------------------------------------------------
    | A one-frame preview is extracted with ffmpeg while the upload still
    | exists as a local temp file, and stored under storage/app/public
    | (a few kilobytes per file, mounted persistently).
    */
    'thumbnails' => [
        'enabled' => (bool) env('RELAY_THUMBNAILS', true),
        'ffmpeg'  => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'ffprobe' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
        'width'   => (int) env('RELAY_THUMB_WIDTH', 480),
    ],

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
        // Seconds to wait for a queued upload to reach "ready" on Telegram
        // (large files split into parts; this can take minutes).
        'ready_timeout' => (int) env('TGDRIVE_READY_TIMEOUT', 1800),
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
        /*
         * true  → streaming is handed to nginx via X-Accel-Redirect, so the
         *         bytes never pass through PHP (needs the relay-upstream
         *         location shipped in the image)
         * false → PHP reads the upstream in chunks and echoes it
         */
        'x_accel'       => (bool) env('RELAY_XACCEL', true),
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
        /*
         * Cache budget in megabytes. When the total size of warm (cached)
         * files grows past it, the least recently used warm files are
         * demoted back to the primary tier by `relay:evict` until the
         * usage fits again. 0 = unlimited (no automatic eviction).
         */
        'max_cache_bytes'  => (int) env('RELAY_MAX_CACHE_MB', 0) * 1048576,
        // Files accessed within this window are never auto-evicted.
        'evict_min_idle'   => (int) env('RELAY_EVICT_MIN_IDLE', 3600),
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
