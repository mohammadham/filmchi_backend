# نقشه راه: سیستم اشتراک‌گذاری، ذخیره‌سازی و استریم

این فهرست بر اساس وضعیت فعلی همین ریپو نوشته شده: Laravel 9 + relay بین Telegram Drive و 9drive، پنل مدیریت، اپ Flutter، و استقرار Docker روی یک سرور.

## وضعیت فعلی

- آپلود ترکیبی (`storage_type=3`) کار می‌کند: فایل روی Telegram Drive می‌نشیند و لینک عمومی روی دامنه سایت است.
- کش تقاضامحور کار می‌کند: شمارنده در Redis، promotion به 9drive، سرو از tier گرم.
- پرده‌ی `Range`/`HEAD`، لینک با پسورد/انقضا/سقف دانلود، و رویدادها وجود دارد.
- تنظیمات relay از پنل قابل ویرایش و تست است؛ مقادیر DB بر `.env` اولویت دارند.
- استقرار: rsync در محل + rebuild ایمیج + migrate؛ storage و لاگ‌ها persist شده‌اند؛ سشن روی Redis است.

ضعف‌های اصلی: صف واقعی نیست (`QUEUE_CONNECTION=sync`)، اعتبارنامه‌ها در DB رمزنگاری نشده‌اند، استریم از داخل PHP پروکسی می‌شود، CDN/HLS/transcode ندارد، سهمیه و eviction برای 9drive تعریف نشده، و تست خودکار برای relay وجود ندارد.

---

## P0 — قبل از ترافیک واقعی (پایداری، امنیت، داده)

| # | کار | جزئیات | تخمین |
|---|---|---|---|
| 1 | صف واقعی | `QUEUE_CONNECTION=redis`، سرویس worker با supervisor، صف `promote` جدا. job با `tries=3`، `backoff=[60,300,900]`، `failed_jobs` مانیتور شود. cron `relay:promote` فقط به‌عنوان fallback بماند. | M |
| 2 | رمزنگاری اعتبارنامه‌ها | cast `encrypted` روی `relay_tg_password`، `relay_tg_api_key`، `relay_nine_password`، `relay_nine_api_key` در `GlobalSettings`. فرم پنل مقدار را نمایش ندهد، فقط «ذخیره شده» + امکان پاک‌کردن. | S |
| 3 | محدودیت آپلود | `max:...` در `uploadRelayFile`/`addMedia`، `client_max_body_size` در nginx، `upload_max_filesize`/`post_max_size` در PHP، سقف حجم روزانه هر ادمین. | S |
| 4 | بکاپ و restore | dump روزانه MySQL + نگه‌داری ۱۴ روزه، بکاپ `relay_files`/`relay_links` و `storage/app`، و یک بار تست واقعی restore روی staging. | M |
| 5 | healthcheck و هشدار | endpoint `/healthz` (DB، Redis، tdrive، 9drive) + `healthcheck` در compose؛ هشدار تلگرام برای down شدن یا صف بیش از حد. | S |
| 6 | rate limit و ضدسوءاستفاده | `throttle` روی `/r` و `/d`، محدودیت per-IP برای لینک‌های پسورددار، hotlink protection، و امکان revoke همه لینک‌های یک فایل. | S |
| 7 | حذف fallback سایلنت | اگر relay خطا داد و `storage_type=3` است، فایل نباید بی‌صدا روی دیسک محلی بنشیند؛ باید job صف شود یا خطای شفاف به پنل برگردد و پرچم `storage_degraded` ثبت شود. | S |
| 8 | rotate کلیدها | کلید 9drive و پسوردهای tdrive که در فایل‌های عملیاتی لوکال هستند یک‌بار چرخانده شوند؛ همه‌ی رازها فقط در env/1Panel. | S |

## P1 — استریم و اشتراک‌گذاری در سطح محصول

| # | کار | جزئیات | تخمین |
|---|---|---|---|
| 9 | بازنویسی مسیر استریم | به‌جای خواندن chunk در PHP: `X-Accel-Redirect` به nginx یا upstream مستقیم با URL امضاشده‌ی کوتاه‌عمر. هدف: TTFB زیر ۴۰۰ms و حذف مصرف حافظه‌ی PHP-FPM. | L |
| 10 | هدرهای کش و اعتبارسنجی | `ETag`, `Last-Modified`, `Accept-Ranges`, `Cache-Control` درست برای warm/cold، پشتیبانی `If-Range` و `416`. تست خودکار برای هر حالت. | S |
| 11 | CDN | Cloudflare جلوی `/r` با cache rule فقط برای فایل‌های warm، به‌همراه قاعده‌ی Range و بایپس برای لینک‌های پسورددار. | M |
| 12 | HLS و کیفیت‌های چندگانه | job ترنسکد ffmpeg (720p/1080p) با master playlist، ذخیره روی 9drive یا CDN. پلیر اپ به HLS سوییچ کند. | L |
| 13 | تصویر و پیش‌نمایش | تولید خودکار poster/thumbnail و sprite با ffmpeg در آپلود؛ استفاده در پنل و اپ. | M |
| 14 | صفحه‌ی اشتراک حرفه‌ای | صفحه‌ی share با OG/Twitter card، پلیر embed قابل iframe، QR، و متن SEO. | M |
| 15 | لینک پیشرفته | محدودیت IP/کشور، اتصال به یک دستگاه، شمارش دقیق دانلود، انقضای خودکار، و «لینک جدید با همان فایل». | M |
| 16 | آمار و داشبورد | نمودار بازدید/پهنای‌باند per file و per link از `relay_events`، جدول پربازدیدها، هزینه‌ی تقریبی پهنای‌باند. | M |
| 17 | واترمارک پیش‌نمایش | واترمارک متنی/لوگو روی پیش‌نمایش ویدیو برای جلوگیری از بازنشر. | M |

## P2 — کیفیت ذخیره‌سازی ترکیبی

| # | کار | جزئیات | تخمین |
|---|---|---|---|
| 18 | dedup | هش sha256 فایل؛ آپلود تکراری فقط رکورد و لینک جدید بسازد، نه آپلود دوباره. | M |
| 19 | آپلود resumable | پشتیبانی tus یا chunked upload برای فایل‌های چند گیگابایتی + ادامه پس از قطعی. | L |
| 20 | eviction هوشمند | سهمیه‌ی 9drive از API خوانده شود؛ وقتی پر شد قدیمی‌ترین/کم‌بازدیدترین warm ها به cold برگردند. policy حجم قابل تنظیم در پنل. | M |
| 21 | بودجه‌ی promotion | سقف تعداد/حجم promote در روز، cooldown برای فایل‌های ناموفق، اولویت بر اساس تقاضا. | S |
| 22 | صحت انتقال | پس از promote، sha256 مقایسه شود؛ در صورت mismatch فایل cold بماند و رویداد خطا ثبت شود. | S |
| 23 | retry و dead-letter | دکمه‌ی «تلاش دوباره» در پنل برای فایل‌های `failed`، مشاهده‌ی آخرین خطا، و پاک‌سازی خودکار رکوردهای یتیم. | S |
| 24 | صفحه‌ی کامل Media Relay | فیلتر بر اساس state/tier/تاریخ، جستجو، عملیات گروهی (promote/evict/delete)، و نمودار صف. | M |

## P3 — پنل، API و مقیاس

| # | کار | جزئیات | تخمین |
|---|---|---|---|
| 25 | فارسی و RTL کامل | کلیدهای `fa.json`، جهت راست‌به‌چپ، اعداد فارسی در داشبورد؛ اکنون فقط `en.json` کامل است. | M |
| 26 | نقش و مجوز | admin/operator/viewer، محدودسازی تنظیمات relay به admin، لاگ تغییرات تنظیمات (who/what/when). | M |
| 27 | 2FA ادمین | TOTP برای ورود پنل + محدودیت IP اختیاری. | S |
| 28 | API نسخه‌دار اپ | `POST /api/v1/uploads` (init/complete)، `GET /api/v1/files`، `POST /api/v1/links`، `GET /api/v1/files/{id}/status` با توکن؛ به‌جای تکیه بر وب‌پنل. | L |
| 29 | وضعیت زنده | webhook یا SSE برای اعلام warm شدن فایل به اپ/پنل، بدون polling. | M |
| 30 | ایندکس و پارتیشن | ایندکس ترکیبی روی `relay_files(cache_state, created_at)` و `relay_events(relay_file_id, created_at)`؛ پارتیشن ماهانه رویدادها وقتی حجم بالا رفت. | S |
| 31 | Redis جدا | DB index مجزا برای cache/session/queue + `appendonly yes` تا سشن و شمارنده‌ها با restart از دست نروند. | S |
| 32 | تنظیم nginx/PHP | `proxy_buffering off` برای مسیر استریم، `sendfile on`، keepalive upstream، `pm.max_children` و opcache متناسب با RAM. | S |
| 33 | تست بار | k6 برای ۱۰۰ استریم هم‌زمان Range؛ تعیین سقف واقعی و مستندسازی. | M |
| 34 | استقرار بی‌وقفه | release dir + symlink به‌جای rsync در محل، health gate، rollback یک‌دستوری، و deploy بر اساس image digest. | M |
| 35 | staging و CI کامل | محیط staging با دیتابیس/Redis جدا، سرویس‌های mysql/redis در GitHub Actions، تست migration روی dump واقعی. | M |
| 36 | تست و تحلیل استاتیک | feature test برای relay (آپلود، promote، سرو، حذف، لینک)، Larastan level 5، Pint در CI. | M |
| 37 | مانیتورینگ و runbook | Uptime Kuma/Prometheus + لاگ متمرکز، هشدار صف/دیسک/سهمیه، runbook برای «9drive پر شد» و «promote گیر کرد». | M |
| 38 | پاکسازی بدهی فنی | رشته‌های خراب/تکراری در `RelayController`، کامنت `storage_type` در migration قدیمی، و یکدست‌سازی URLها در JS (`relayApiUrl`). | S |

---

## معیار پذیرش (Definition of Done)

- آپلود ۲GB بدون مصرف حافظه‌ی سرور و با امکان ادامه پس از قطعی.
- TTFB فایل warm زیر ۴۰۰ms و cold زیر ۱.۵ ثانیه در ایران.
- نرخ موفقیت promotion بالای ۹۹٪ با retry خودکار؛ هیچ فایل گم‌شده‌ای پس از deploy.
- قطعی هر یک از سرویس‌ها باعث ۵۰۳ کنترل‌شده با `Retry-After` شود، نه استثنا.
- بازگردانی بکاپ روی محیط تمیز، مستند و تست‌شده.
- پوشش تست خودکار برای همه‌ی مسیرهای عمومی `/r` و `/d`.
- صفر راز داخل گیت؛ کلیدها فقط در env/1Panel و قابل چرخش.

## ریسک‌های شناخته‌شده

- **سهمیه 9drive**: بدون eviction، پر شدن quota باعث fail شدن promotion می‌شود؛ اولویت P2 را بالا ببرد.
- **تک‌سروری**: nginx/php/redis همگی روی یک ماشین‌اند؛ هر بار سنگین استریم روی آپلود پنل اثر می‌گذارد.
- **وابستگی به Cloudflare**: قواعد cache اشتباه می‌تواند لینک‌های خصوصی را کش کند؛ قبل از فعال‌سازی CDN تست نشت انجام شود.
- **ترنسکدینگ روی همین سرور**: ffmpeg می‌تواند CPU را بخورد؛ بهتر است job ترنسکد روی سرور جدا یا به‌صورت on-demand باشد.
