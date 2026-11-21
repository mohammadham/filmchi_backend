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
    | Upload destination preflight
    |--------------------------------------------------------------------------
    | Ingestion only fetches a file when it can be stored afterwards. The relay
    | asks Telegram Drive whether the storage chat accepts an upload (a
    | zero-byte session that is cancelled again) and caches the answer: ready
    | for `ttl` seconds, and a failure for the shorter `negative_ttl` so a
    | channel that was just fixed is retried quickly.
    */
    'destination' => [
        'ttl' => (int) env('RELAY_DESTINATION_TTL', 60),
        'negative_ttl' => (int) env('RELAY_DESTINATION_NEGATIVE_TTL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | URL ingestion (pull remote links into the relay)
    |--------------------------------------------------------------------------
    | Sources added with the "ingest" option are queued: the worker downloads
    | the file, dedup-checks it and uploads to Telegram Drive, then points
    | the source at the relay link. The panel controls how many ingest jobs
    | may run at once; each job still uses its own connection budget.
    */
    'ingest' => [
        'enabled' => (bool) env('RELAY_INGEST', true),
        'timeout' => (int) env('RELAY_INGEST_TIMEOUT', 3600),
        // Seconds a download may go without a single byte before it is treated
        // as dead. Per read, so a slow source is unaffected.
        'stall_timeout' => (int) env('RELAY_INGEST_STALL_TIMEOUT', 300),
        // A single-stream download is the last resort for sources that do not
        // support ranges. Do not let one trickling connection occupy a queue
        // slot for hours: below this speed for the whole window, fail the job
        // so it can be retried later with a fresh Worker.
        'min_stream_kbps' => (int) env('RELAY_INGEST_MIN_STREAM_KBPS', 64),
        'stream_stall_seconds' => (int) env('RELAY_INGEST_STREAM_STALL_SECONDS', 180),
        // The scraper host throttles each connection hard, so one file is fetched
        // over several ranged connections. Sustained throughput measured on
        // di.farsanime.ir: 8 conns = 327 KB/s, 24 = 838, 32 = 925, 48 = 1072.
        // Twenty-four keeps most of the gain without a socket pile per file.
        'connections' => (int) env('RELAY_INGEST_CONNECTIONS', 24),
        // Aggregate ceiling shared by all concurrent files. 0 = no ceiling
        // (connections × concurrency); positive = floor(value / concurrency)
        // per file, so raising concurrency cannot multiply the socket count.
        'total_connections' => (int) env('RELAY_INGEST_TOTAL_CONNECTIONS', 0),
        // How many files the worker may pull/upload at the same time. Each
        // concurrent job opens its own connection budget, so the total socket
        // count is roughly connections × concurrency.
        'concurrency' => (int) env('RELAY_INGEST_CONCURRENCY', 1),
        // Free space that ingest may never spend. A percentage alone cannot
        // answer this: it shrinks with the disk, so the closer the host gets to
        // full the less it holds back. Telegram Drive already rejects uploads
        // below 15% free, which turns a nearly full disk into a deadlock (no
        // upload -> .part files never leave -> disk stays full), so the floor
        // has to sit above that watermark plus room for MySQL, Redis and
        // Docker. 0 drops the floor.
        'min_free_mb' => (int) env('RELAY_INGEST_MIN_FREE_MB', 8192),
        // Hard ceiling on the bytes held by .part files that are downloading
        // or waiting to upload. The floor bounds what is left, this bounds the
        // backlog itself. 0 = no ceiling.
        'max_inflight_mb' => (int) env('RELAY_INGEST_MAX_INFLIGHT_MB', 4096),
        // Secondary cap: never spend more than this share of the free space.
        // It used to be the whole budget, which both double-counted the parts
        // already on disk and let the allowance shrink as the disk filled. 0
        // still disables the disk budget entirely.
        'disk_percent' => (int) env('RELAY_INGEST_DISK_PERCENT', 50),
        // A retried download keeps its .part file so the next attempt can
        // resume it. When that retry never comes back the file is dead weight,
        // so a sweeper removes it once neither the source nor the file has
        // moved for this many hours.
        'stale_part_hours' => (int) env('RELAY_INGEST_STALE_PART_HOURS', 12),
        // How often the queue supervisor runs that sweeper. 0 disables it.
        'stale_sweep_seconds' => (int) env('RELAY_INGEST_STALE_SWEEP_SECONDS', 900),
        // Fixed range size for the dynamic chunk queue. Small chunks let a
        // retry repeat only a few MB instead of a hundred-plus MB slice.
        'chunk_mb' => (int) env('RELAY_INGEST_CHUNK_MB', 8),
        // An 8 MB chunk that receives no byte for this long is dead; waiting
        // the old full-file stall timeout would hold a slot for minutes.
        'chunk_stall_timeout' => (int) env('RELAY_INGEST_CHUNK_STALL_TIMEOUT', 60),
        // A dead source can fail every chunk at once; retrying all of them
        // sequentially must not pin a download slot for tens of minutes.
        'chunk_retry_budget' => (int) env('RELAY_INGEST_CHUNK_RETRY_BUDGET', 180),
        // chunk_speed watches aggregate throughput and, on a sustained drop,
        // re-probes every Worker for the next chunks instead of trusting a
        // Worker that has degraded mid-file.
        'speed_check_seconds' => (float) env('RELAY_INGEST_SPEED_CHECK_SECONDS', 5),
        'speed_window_samples' => (int) env('RELAY_INGEST_SPEED_WINDOW_SAMPLES', 6),
        'speed_drop_ratio' => (float) env('RELAY_INGEST_SPEED_DROP_RATIO', 0.70),
        'speed_drop_confirmations' => (int) env('RELAY_INGEST_SPEED_DROP_CONFIRMATIONS', 2),
        'speed_reprobe_chunks' => (int) env('RELAY_INGEST_SPEED_REPROBE_CHUNKS', 2),
        'speed_reprobe_cooldown_seconds' => (int) env('RELAY_INGEST_SPEED_REPROBE_COOLDOWN_SECONDS', 30),
        // Target number of concurrent streams per Worker. The total is still
        // capped by RELAY_INGEST_CONNECTIONS.
        'streams_per_worker' => (int) env('RELAY_WORKER_STREAMS', 2),
        // Maximum Workers one file may use. 0 = the whole healthy pool.
        'max_workers' => (int) env('RELAY_INGEST_MAX_WORKERS', 0),
        // How often a running download refreshes its Worker pool. New or
        // recovered Workers are added to free slots without restarting the
        // transfer.
        'pool_refresh_seconds' => (int) env('RELAY_INGEST_POOL_REFRESH_SECONDS', 10),
        // Maximum number of chunks to pre-allocate in the pending queue.
        // 0 = unlimited (limited by memory). Useful for very large files.
        'max_pending_chunks' => (int) env('RELAY_INGEST_MAX_PENDING_CHUNKS', 0),
        // Minimum chunk size in MB (for very small files).
        'min_chunk_mb' => (int) env('RELAY_INGEST_MIN_CHUNK_MB', 1),
        // Maximum chunk size in MB (for very large files).
        'max_chunk_mb' => (int) env('RELAY_INGEST_MAX_CHUNK_MB', 16),
        // Retry policy for a URL that failed while downloading. The failed job
        // is released back onto the same download queue after this delay, so
        // it waits behind the work that was already queued.
        'retry_max_attempts' => (int) env('RELAY_INGEST_RETRY_MAX_ATTEMPTS', 3),
        'retry_delay_seconds' => (int) env('RELAY_INGEST_RETRY_DELAY_SECONDS', 300),
        // Rows kept per source in ingest_attempt_log. A requeue resets the
        // counters on the source row; this history is what survives.
        'history_limit' => (int) env('RELAY_INGEST_HISTORY_LIMIT', 100),
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
        // Keep the probe tiny: the sitemap is several megabytes and a slow
        // Worker would be marked dead before it could finish downloading it.
        'test_url' => env('RELAY_WORKER_TEST_URL', 'https://farsanime.ir/robots.txt'),
        'timeout' => (int) env('RELAY_WORKER_TIMEOUT', 30),
        // A failed worker is skipped at least this long before the next probe.
        // The actual delay grows exponentially up to backoff_max_seconds.
        'cooldown_seconds' => (int) env('RELAY_WORKER_COOLDOWN', 60),
        // After this many consecutive real-transfer failures the Worker is
        // auto-disabled instead of being re-admitted after a cooldown. A
        // successful transfer resets the counter; a probe does not count as a
        // transfer.
        'auto_disable' => (bool) env('RELAY_WORKER_AUTO_DISABLE', true),
        'failure_threshold' => (int) env('RELAY_WORKER_FAILURE_THRESHOLD', 3),
        'failure_window_seconds' => (int) env('RELAY_WORKER_FAILURE_WINDOW_SECONDS', 600),
        'backoff_base_seconds' => (int) env('RELAY_WORKER_BACKOFF_BASE_SECONDS', 60),
        'backoff_max_seconds' => (int) env('RELAY_WORKER_BACKOFF_MAX_SECONDS', 3600),
        // A recovered Worker must pass this many consecutive probes before it
        // is allowed back into the pool. An operator-triggered test is an
        // explicit override and recovers it immediately on success.
        'probe_success_threshold' => (int) env('RELAY_WORKER_PROBE_SUCCESS_THRESHOLD', 2),
        'probe_interval_seconds' => (int) env('RELAY_WORKER_PROBE_INTERVAL_SECONDS', 60),
        // Keep enough healthy endpoints in the pool. This remains a useful
        // warning floor, but background probes are no longer gated on it: a
        // failed Worker is always probed on its own schedule.
        'min_healthy' => (int) env('RELAY_WORKER_MIN_HEALTHY', 5),
        'recheck_seconds' => (int) env('RELAY_WORKER_RECHECK_SECONDS', 60),
        'recheck_limit' => (int) env('RELAY_WORKER_RECHECK_LIMIT', 3),
        // Worker probes get their own lane so neither download nor upload
        // slots are blocked while a sleeping Worker is being measured.
        'probe_queue' => env('RELAY_WORKER_PROBE_QUEUE', 'probes'),
        'probe_concurrency' => (int) env('RELAY_WORKER_PROBE_CONCURRENCY', 1),
        // A cold Worker/origin answers the first ranged request with a full
        // 200 response. Read a little, wait, and ask again once.
        'cold_retry_delay_ms' => (int) env('RELAY_WORKER_COLD_RETRY_DELAY_MS', 2000),
        'warmup_bytes' => (int) env('RELAY_WORKER_WARMUP_BYTES', 1048576),
        // Optional admission thresholds. 0 keeps a freshly added Worker
        // eligible until it has real throughput samples.
        'min_speed_bps' => (int) env('RELAY_WORKER_MIN_SPEED_BPS', 0),
        'max_latency_ms' => (int) env('RELAY_WORKER_MAX_LATENCY_MS', 0),
        // Worker selection strategy: 'round_robin', 'fastest', 'least_used'
        'selection_strategy' => env('RELAY_WORKER_SELECTION_STRATEGY', 'round_robin'),
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
        //: How many active+queued sources the orchestrator should keep in flight.
        'target_depth' => (int) env('RELAY_AUTOMATION_TARGET_DEPTH', 2),

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
    | Quarantine of dead links
    |--------------------------------------------------------------------------
    | A source that fetched zero bytes over several attempts and several routes
    | is no longer a flaky download: it is a dead link. It is marked dead
    | (link_status) and no requeue path touches it until a probe succeeds.
    | The defaults follow the queue's own retry budget: two attempts are enough
    | for one attempt to be retried, and two distinct Workers are the smallest
    | number that rules out a single bad endpoint.
    */
    'quarantine' => [
        'zero_attempts' => (int) env('RELAY_QUARANTINE_ZERO_ATTEMPTS', 2),
        'zero_workers' => (int) env('RELAY_QUARANTINE_ZERO_WORKERS', 2),
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
        // Uploads run on their own queue and worker pool. Downloads therefore
        // keep two slots even while both upload slots are busy.
        'concurrency' => (int) env('RELAY_UPLOAD_CONCURRENCY', 2),
        'queue' => env('RELAY_UPLOAD_QUEUE', 'uploads'),
        // An upload failure keeps the downloaded .part while attempts remain,
        // then releases the job to the end of the upload queue after this delay.
        'retry_max_attempts' => (int) env('RELAY_UPLOAD_RETRY_MAX_ATTEMPTS', 3),
        'retry_delay_seconds' => (int) env('RELAY_UPLOAD_RETRY_DELAY_SECONDS', 120),
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

    /*
    |--------------------------------------------------------------------------
    | Telegram bot (Flixy)
    |--------------------------------------------------------------------------
    |
    | Everything is off by default: with enabled=false the webhook route answers
    | 404 and no token is used, so an install that never configures the bot
    | behaves exactly like one without the feature.
    |
    */
    'bot' => [
        'enabled'        => (bool) env('RELAY_BOT_ENABLED', false),
        'token'          => (string) env('RELAY_BOT_TOKEN', ''),
        // Telegram's X-Telegram-Bot-Api-Secret-Token value; also the URL path.
        'webhook_secret' => (string) env('RELAY_BOT_WEBHOOK_SECRET', ''),
        // Numeric Telegram user ids, comma/space separated (usernames can change).
        'allowed_ids'    => (string) env('RELAY_BOT_ALLOWED_IDS', ''),
        // Destination channel the bot copies documents into (zero bytes).
        'storage_chat'   => (string) env('RELAY_BOT_STORAGE_CHAT', ''),
        // Telegram Drive channel-registry id used by the manifest fallback.
        'channel_id'     => (int) env('RELAY_BOT_CHANNEL_ID', 0),
        'quality'        => (string) env('RELAY_BOT_QUALITY', '1080'),
        // Files one allowed user may submit per day (0 = unlimited).
        'daily_limit'    => (int) env('RELAY_BOT_DAILY_LIMIT', 20),
        // copyMessage is metadata + a server-side copy: seconds, not minutes.
        'copy_timeout'   => (int) env('RELAY_BOT_COPY_TIMEOUT', 60),
        // Bot imports get their own lane so a big file never blocks the panel.
        'queue'          => env('RELAY_BOT_QUEUE', 'bot'),
    ],

];
