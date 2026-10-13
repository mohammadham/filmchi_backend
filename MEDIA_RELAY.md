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

Touched, additively only:

- [routes/web.php](routes/web.php) — public relay routes plus the panel routes
- [resources/views/include/app.blade.php](resources/views/include/app.blade.php) — sidebar entry "Media Relay"
- [resources/lang/en.json](resources/lang/en.json) — labels
- [.env.example](.env.example) — configuration block

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
