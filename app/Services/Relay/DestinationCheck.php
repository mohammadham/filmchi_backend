<?php

namespace App\Services\Relay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Is the upload destination ready to take a file?
 *
 * Every ingest source ends up in the relay's storage chat, so a download whose
 * destination cannot store it is pure waste: hundreds of megabytes are fetched
 * and then the upload fails. This check runs before the source is touched and
 * answers with a panel-ready Persian message.
 *
 * "Ready" means the relay is enabled, Telegram Drive is configured, and the
 * storage service accepts an upload session for the configured chat — the same
 * preflight the service itself runs before accepting bytes. The probe creates a
 * zero-byte session and cancels it again, so no file and no bytes are left
 * behind. Results are cached briefly: a positive answer for
 * `media_relay.destination.ttl` seconds and a failure for the shorter negative
 * TTL, so a channel that was just fixed recovers quickly.
 */
class DestinationCheck
{
    public const CACHE_PREFIX = 'relay:destination:';

    /** @var TelegramDriveClient */
    protected $client;

    public function __construct(?TelegramDriveClient $client = null)
    {
        $this->client = $client ?: app(TelegramDriveClient::class);
    }

    /**
     * @return array{ok:bool,message:string,cached:bool}
     */
    public function inspect(): array
    {
        if (!config('media_relay.enabled')) {
            return $this->failure(
                'مقصد آپلود آماده نیست: رله رسانه غیرفعال است — آن را در تنظیمات رسانه فعال کنید.'
            );
        }

        if (!app(RelayStorage::class)->isReady()) {
            return $this->failure(
                'مقصد آپلود آماده نیست: سرویس Telegram Drive پیکربندی نشده است (TGDRIVE_BASE_URL).'
            );
        }

        $chat = trim((string) config('media_relay.telegram.storage_chat', ''));
        $key = self::CACHE_PREFIX . md5($this->client->baseUrl() . '|' . $chat);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return [
                'ok' => (bool) ($cached['ok'] ?? false),
                'message' => (string) ($cached['message'] ?? ''),
                'cached' => true,
            ];
        }

        try {
            $message = $this->client->destinationUnavailable($chat);
        } catch (Throwable $e) {
            $message = 'مقصد آپلود آماده نیست: سرویس Telegram Drive در دسترس نیست — '
                . Str::limit($e->getMessage(), 120);
        }

        $ok = $message === null;

        Cache::put($key, ['ok' => $ok, 'message' => (string) $message], $ok ? $this->ttl() : $this->negativeTtl());

        return ['ok' => $ok, 'message' => (string) $message, 'cached' => false];
    }

    /**
     * @throws DestinationUnavailable
     */
    public function assertReady(): void
    {
        $result = $this->inspect();

        if (!$result['ok']) {
            throw new DestinationUnavailable($result['message']);
        }
    }

    /** @return array{ok:bool,message:string,cached:bool} */
    protected function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'cached' => false];
    }

    protected function ttl(): int
    {
        return max(1, (int) config('media_relay.destination.ttl', 60));
    }

    protected function negativeTtl(): int
    {
        return max(1, min($this->ttl(), (int) config('media_relay.destination.negative_ttl', 30)));
    }
}
