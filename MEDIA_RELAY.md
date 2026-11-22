# Media Relay

A second upload/download path that sits next to the existing storage systems (local, S3, DigitalOcean). It stores file bytes on two remote services and keeps this server as a pipe only.

## خلاصه فارسی

این سیستم در کنار سیستم‌های ذخیره‌سازی فعلی اضافه شده و کاری که می‌کند این است:

- فایل‌ها روی **Telegram Drive** (ذخیره‌سازی اصلی) آپلود می‌شوند.
- هر فایلی که تقاضای کاربران برایش از حد مشخصی بگذرد، به‌صورت خودکار به **9drive** (کش روی گوگل‌درایو) منتقل می‌شود تا استریم و دانلود سریع‌تر باشد.
- لینک عمومی همیشه روی **دامنه خود سایت** است و کاربر فقط `رله` را می‌بیند؛ سرویس مقصد پنهان می‌ماند.
- **هیچ فایلی روی سرور سایت ذخیره نمی‌شود.** آپلود و دانلود به‌صورت استریم عبور می‌کنند و هیچ فایل موقتی روی دیسک نوشته نمی‌شود. حتی انتقال از تلگرام به گوگل‌درایو هم مستقیم و بدون ذخیره روی سرور انجام می‌شود.

برای فعال شدن انتقال خودکار بر اساس تقاضا، باید `CACHE_DRIVER=redis` باشد (یا `RELAY_STATE_STORE=redis`). با درایور `array` شمارنده‌ها بین درخواست‌ها باقی نمی‌مانند و انتقال خودکار هرگز رخ نمی‌دهد.

---

## How it works

Two storage tiers plus a relay in front of them.

| Tier | Service | Role |
|---|---|---|
| primary | Telegram Drive (FastAPI, `fast api tel uploader`) | every file lands here first |
| cache | 9drive (`zenhosta/9drive`, Google Drive) | holds the files users actually watch |
| relay | this Laravel app | public links, tier selection, byte streaming |

### Upload

The panel uploads to Telegram Drive through `POST /api/v1/files/upload`. The row in `relay_files` keeps the Telegram file id and starts in `cache_state = cold`.

### Serving

A visitor opens a link on this site's domain (`/r/{token}` to stream, `/d/{token}` to download). `RelayManager` picks the tier:

- `cache_state = warm` and the 9drive gateway is reachable → bytes come from the 9drive share endpoints.
- otherwise → a short-lived signed URL is minted on Telegram Drive and the bytes come from there.

Either way the response is built by reading the upstream stream in chunks and echoing it. There is no temp file and no local copy. `Range` requests are forwarded, so seeking works on both tiers, and the upstream status (200 or 206), `Content-Length` and `Content-Range` are passed back.

### Promotion (demand-driven tiering)

Every request is counted in the configured cache store (Redis when you point it there). When the count for a file reaches `RELAY_PROMOTE_THRESHOLD` inside `RELAY_DEMAND_WINDOW`, the file is queued for promotion. `CachePromoter` then:

1. opens the source as a live HTTP stream on Telegram Drive,
2. feeds that stream into `POST /api/v1/uploads` on 9drive as the multipart body,
3. mints a long-lived share token and switches the file to `warm`.

The bytes travel from one remote service to the other, so the 2GB copy never touches this server. If 9drive is unreachable the file stays on the primary tier and keeps serving from there.

---

## Files

New:

- [config/media_relay.php](config/media_relay.php)
- [app/RelayFile.php](app/RelayFile.php), [app/RelayLink.php](app/RelayLink.php), [app/RelayEvent.php](app/RelayEvent.php)
- [app/Services/Relay/TelegramDriveClient.php](app/Services/Relay/TelegramDriveClient.php), [NineDriveClient.php](app/Services/Relay/NineDriveClient.php), [RelayManager.php](app/Services/Relay/RelayManager.php), [CachePromoter.php](app/Services/Relay/CachePromoter.php), [DemandTracker.php](app/Services/Relay/DemandTracker.php), [Streams.php](app/Services/Relay/Streams.php), [SizedStream.php](app/Services/Relay/SizedStream.php)
- [app/Jobs/PromoteToCacheJob.php](app/Jobs/PromoteToCacheJob.php)
- [app/Http/Controllers/RelayController.php](app/Http/Controllers/RelayController.php), [app/Http/Controllers/MediaRelayController.php](app/Http/Controllers/MediaRelayController.php)
- [app/Console/Commands/PromoteRelayFiles.php](app/Console/Commands/PromoteRelayFiles.php), [app/Console/Commands/RelayStatus.php](app/Console/Commands/RelayStatus.php)
- [resources/views/mediaRelay.blade.php](resources/views/mediaRelay.blade.php), [public/assets/script/mediaRelay.js](public/assets/script/mediaRelay.js)
- three migrations under [database/migrations](database/migrations) creating `relay_files`, `relay_links`, `relay_events`

Bot (the intake path described under [Telegram bot](#telegram-bot)):

- [app/Http/Controllers/TelegramWebhookController.php](app/Http/Controllers/TelegramWebhookController.php)
- [app/Services/Bot/TelegramBotClient.php](app/Services/Bot/TelegramBotClient.php), [BotFileImporter.php](app/Services/Bot/BotFileImporter.php), [BotManifestSender.php](app/Services/Bot/BotManifestSender.php)
- [app/Services/Relay/StoredMessageImporter.php](app/Services/Relay/StoredMessageImporter.php), [app/Jobs/ImportBotFileJob.php](app/Jobs/ImportBotFileJob.php)
- four migrations under [database/migrations](database/migrations): `2026_11_21_000001` (`relay_bot_*` columns on `global_settings`), `000002` (`bot_updates` dedup key, `bot_sessions`, `bot_submissions`), `000003` (bot columns plus the unique `(storage_chat, message_id)` file-part index), `000005` (`quality`, `is_series`)
- tests: [tests/Feature/TelegramWebhookTest.php](tests/Feature/TelegramWebhookTest.php), [ImportBotFileJobTest.php](tests/Feature/ImportBotFileJobTest.php), [StoredMessageImporterTest.php](tests/Feature/StoredMessageImporterTest.php), [BotFileImporterTest.php](tests/Feature/BotFileImporterTest.php), [BotManifestSenderTest.php](tests/Feature/BotManifestSenderTest.php), [RelayQueueSupervisorTest.php](tests/Feature/RelayQueueSupervisorTest.php)

Touched, additively only:

- [routes/web.php](routes/web.php) — public relay routes, the panel routes, the CSRF-exempt `POST telegram/webhook/{secret}` route and the bot card actions
- [resources/views/include/app.blade.php](resources/views/include/app.blade.php) — sidebar entry "Media Relay"
- [app/Console/Commands/RelayQueueSupervisor.php](app/Console/Commands/RelayQueueSupervisor.php) — a `bot` lane next to download/upload/probe, and stale `bot_submissions` are re-queued
- [app/Http/Controllers/SettingController.php](app/Http/Controllers/SettingController.php) — bot card save, `setBotWebhook` / `deleteBotWebhook`, `botHealth`
- [resources/views/setting.blade.php](resources/views/setting.blade.php), [public/assets/script/setting.js](public/assets/script/setting.js) — the bot card
- [resources/lang/en.json](resources/lang/en.json), [resources/lang/fa.json](resources/lang/fa.json) — labels
- [config/media_relay.php](config/media_relay.php) — the `bot` block next to the relay settings
- [.env.example](.env.example) — configuration blocks

The existing storage paths (`GlobalFunction::saveFileAndGivePath`, the media gallery, content sources) are unchanged.

---

## Setup

Both services need to be running and reachable from this server.

```env
RELAY_ENABLED=true

# Telegram Drive (primary)
TGDRIVE_BASE_URL=http://127.0.0.1:8000
TGDRIVE_USERNAME=admin
TGDRIVE_PASSWORD=...
# or a static API key instead of panel credentials:
TGDRIVE_API_KEY=
TGDRIVE_STORAGE_CHAT=

# 9drive (cache) — the API key needs the files:upload scope
NINEDRIVE_BASE_URL=http://127.0.0.1:4000
NINEDRIVE_API_KEY=
# panel credentials, needed to mint the public share used for serving
NINEDRIVE_EMAIL=
NINEDRIVE_PASSWORD=

RELAY_PROMOTE_THRESHOLD=3
RELAY_DEMAND_WINDOW=86400
RELAY_STREAM_TIMEOUT=1800
```

Then:

```bash
php artisan migrate
php artisan relay:status            # tier health and statistics
```

### Requirements worth knowing

**A persistent cache store.** Demand counters live in the cache. With the default `array` driver they are discarded after every request, so nothing ever reaches the threshold and no file is promoted. Set `CACHE_DRIVER=redis` (or `RELAY_STATE_STORE=redis`). The panel page and `relay:status` both warn when the store is not persistent.

**A queue worker for background promotion.** Promotion runs in `PromoteToCacheJob`. With `QUEUE_CONNECTION=sync` there is no worker, so a file that crosses the threshold is only marked `queued`; run `php artisan relay:promote` (cron or manually) to move it. With a real queue (`redis`, `database`) the job is dispatched and the panel returns immediately.

**9drive panel credentials.** Uploading to 9drive only needs the API key, but serving cached bytes goes through the public share endpoints, which are created with a panel session. Without `NINEDRIVE_EMAIL`/`NINEDRIVE_PASSWORD` a promoted file is marked warm and falls back to the public Google Drive link, which Google throttles with an interstitial for large files. Setting the credentials avoids that.

---

## Telegram bot

An allowed user sends a document to the bot, answers two questions (quality, then
series) and the file is copied into the storage chat and adopted by Telegram
Drive. A fresh install needs these five steps.

**1. Migrations.** `php artisan migrate` is enough; the bot migrations guard with
`hasTable`/`hasColumn`, so they are safe to re-run:

| Migration | Adds |
|---|---|
| `2026_11_21_000001_add_bot_settings_to_global_settings` | `relay_bot_*` columns on `global_settings` |
| `2026_11_21_000002_create_bot_tables` | `bot_updates` (dedup key), `bot_sessions`, `bot_submissions` |
| `2026_11_21_000003_add_origin_and_bot_columns` | bot columns on relay files and the unique file-part index |
| `2026_11_21_000005_add_conversation_answers_to_bot_submissions` | `quality` and `is_series` on `bot_submissions` |

There is no `…_000004`: `bot_updates` keeps only the dedup key, not the update
payload.

**2. Environment.**

```env
RELAY_BOT_ENABLED=true
RELAY_BOT_TOKEN=123456:AA...             # BotFather token
RELAY_BOT_WEBHOOK_SECRET=...             # long random string: URL path + Telegram header
RELAY_BOT_ALLOWED_IDS=1111111,2222222    # numeric Telegram user ids, not usernames
RELAY_BOT_STORAGE_CHAT=@store            # channel the bot copies documents into
RELAY_BOT_CHANNEL_ID=7                   # Telegram Drive channel-registry id (manifest fallback)
RELAY_BOT_QUALITY=1080                   # default label; each file is asked anyway
RELAY_BOT_DAILY_LIMIT=20                 # 0 = unlimited
RELAY_BOT_QUEUE=bot                      # queue name; the supervisor consumes this one
RELAY_BOT_CONCURRENCY=1                  # clamped to 1..2 by the supervisor
```

Optional: `RELAY_BOT_COPY_TIMEOUT` (60), `RELAY_BOT_REPLY_TIMEOUT` (5),
`RELAY_BOT_STALE_SECONDS` (600), `RELAY_BOT_REQUEUE_SECONDS` (300),
`RELAY_BOT_UPDATE_RETENTION_DAYS` (2), `RELAY_BOT_PRUNE_SECONDS` (3600).
The dedup table is pruned by the supervisor: `bot_updates` rows older than the
retention (`received_at`, indexed) are deleted hourly. Retention is clamped to at
least one day, so it always outlives Telegram's 24-hour retry window and a
retried delivery can never start a second flow. The same
fields are editable on the settings page; a blank token/secret field keeps the
stored value, and the panel never returns either secret.

**3. Webhook.** Use **Set webhook** on the bot card (it calls the Bot API for
you), or:

```bash
curl -X POST "https://api.telegram.org/bot$RELAY_BOT_TOKEN/setWebhook" \
  -d "url=https://<panel-host>/telegram/webhook/$RELAY_BOT_WEBHOOK_SECRET" \
  -d "secret_token=$RELAY_BOT_WEBHOOK_SECRET" \
  -d 'allowed_updates=["message","callback_query"]'
```

A wrong path segment is `404` and a wrong header `403`, both without writing a
row. `/ping` answers `pong` and `/start` answers a welcome without touching the
queue, so the endpoint can be checked before any worker exists. **Delete
webhook** removes it again.

**4. Queue lane.** `ImportBotFileJob` dispatches on `RELAY_BOT_QUEUE`, and
`relay:queue-supervisor` reconciles a worker for that lane next to
download/upload/probe. Start it once (it is the `worker` service in
`flixy-compose.yml`):

```bash
php artisan relay:queue-supervisor --queue=default --poll=3
# relay queue supervisor started (download=1, upload=1, probe=1, bot=1)
```

With `QUEUE_CONNECTION=sync` there is no worker at all and the import runs inside
the webhook request; set `redis` or `database` for anything real. That case is
not silent: each inline dispatch logs `bot import runs inline`, and the bot
card's health call reports `queue_sync`, so the warning shows up in the panel
too. The supervisor also re-queues submissions left `queued`/`retrying` for
`RELAY_BOT_STALE_SECONDS`, so a worker that dies does not strand a file.

**5. Smoke test.** Send `/ping` from an allowed account (expect `pong`), then a
small document: the bot asks quality, then series, replies that the file is
queued, and answers with the `/r/{token}` link once the import finishes. The bot
card's health call reports the `getMe` result and the registered webhook.

A half-answered conversation is not a trap: `/cancel` (also `/cancel@BotName`)
drops the `bot_sessions` row, so the parked document is never registered and the
next text starts fresh. Both re-asks name the command.

The answers do not stay in the chat: **Media Relay → Bot submissions** lists the
last 20 rows of `bot_submissions` with the stored `quality`, the `is_series`
flag and the import status, so a wrong or missing answer is visible (and
fixable) from the panel.

## Endpoints

Public, no authentication, both on this site's domain:

| Route | Purpose |
|---|---|
| `GET /r/{token}` | inline stream, Range-capable |
| `GET /d/{token}` | attachment download |
| `HEAD` on either | headers only |

A link can carry a password (`?pw=...` or `X-Link-Password`), an expiry, and a maximum number of downloads. Disabled, expired and exhausted links answer `410`; unknown tokens `404`; blocked files `403`. When a storage tier is unreachable the visitor gets `503` with `Retry-After`, not a stack trace.

Panel, behind the existing `checkLogin` middleware: `mediaRelay` (page), `relayFileList`, `uploadRelayFile`, `promoteRelayFile`, `evictRelayFile`, `deleteRelayFile`, `toggleRelayBlock`, `relayLinkList`, `createRelayLink`, `toggleRelayLink`, `deleteRelayLink`, `relayStatus`.

---

## What was verified

Checked against a stub that stands in for both services over real HTTP, then removed. The suite covered:

- public links on the site domain: unknown token `404`, expired/disabled/exhausted link `410`
- streaming from the primary tier: full body byte-identical to the source, `Content-Length` forwarded, `Content-Disposition` attachment vs inline, `Accept-Ranges`
- `Range: bytes=100-199` → `206` with the correct `Content-Range` and exactly those 100 bytes
- `HEAD` → `200`, correct `Content-Length`, empty body
- tier switch: a warm file is served from the cache tier instead of the primary
- accounting: link hits, `last_access_at`, one event per request, tier recorded per event
- promotion: the upload arrives byte-identical (sha256 match), `sizeBytes` is serialised before the file part, the request carries an explicit `Content-Length`, the share token is minted and stored, re-promoting is a no-op, eviction clears the pointers
- no file written under `storage/app` at any point during serving or promotion

Two defects surfaced during that work and are fixed: an unset 9drive base URL used to send a relative upstream URL instead of falling back to the primary tier, and a hard-coded 3600s stream timeout that exceeds PHP's platform socket limit. `Streams::open` now clamps the timeout and reports the real reason when a connection fails.

Not verified: behaviour against the real Telegram Drive and 9drive deployments, which need live credentials and API keys. The request shapes come from their source (`app/api/files.py`, `app/services/presign.py` and 9drive's `upload.routes.ts`, `file.routes.ts`, `auth.routes.ts`), but the first run against live services should be treated as an integration test.

---

## Commands

```bash
php artisan relay:status                 # health of both tiers, counts per cache state
php artisan relay:promote --limit=5      # promote in-demand files
php artisan relay:promote --id=42        # promote one file
```

---

## Combined storage (panel setting)

`storage_type = 3` ("Combined (Telegram Drive + 9drive)") routes uploads through the relay: the panel sends the file to Telegram Drive, creates a `relay_files` row, and returns a public link on this site's domain (`/r/{token}`). Files are promoted to the 9drive cache tier on demand. If the relay call fails the upload falls back to local storage, so the panel never loses a file.

The option is on **Settings → Storage Setting**, next to local/S3/DigitalOcean. The rest of the relay configuration is editable from the same page (**Multi-storage** card): Telegram Drive base URL/credentials, 9drive base URL/API key/credentials, frontend URL, serve mode, promotion threshold and demand window, plus an enable toggle and a **Test connection** button that hits both upstreams and shows `online`/`offline` badges.

Panel values are stored in `global_settings` (`relay_*` columns) and override `config/media_relay.php` at boot (`AppServiceProvider`), so `.env` still works as the fallback. Blank password/API-key fields keep the stored value.

### Promotion without a queue worker

The production `.env` uses `QUEUE_CONNECTION=sync`, so crossing the threshold only marks a file `queued`. A cron entry drains it:

```cron
*/5 * * * * docker exec flixy php /app/artisan relay:promote --limit=20 >> /var/log/flixy-relay-promote.log 2>&1
```

### Deployment notes

- `flixy-compose.yml` (copied to `docker-compose.yml` on the server by `deploy.yml` before `docker compose up`) bind-mounts `./storage/app` and `./storage/logs`; without them every rebuild wipes uploads and logs.
- Sessions use Redis (`SESSION_DRIVER=redis`) so panel logins survive redeploys.
- `deploy.yml` now runs `php artisan migrate --force` and `optimize:clear` after `docker compose up`.
- `setting.js` is cache-busted with `?v=<filemtime>`; bumping assets no longer requires a hard refresh.
