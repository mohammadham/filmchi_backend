<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Path B″ step 1: send the channel-backup JSON into the storage channel with
 * sendDocument and return the message id that /channels/{id}/restore merges.
 *
 * Only pointers travel: the manifest is a few KB and the documents it lists
 * already sit in that channel, so no media is uploaded or downloaded.
 *
 * The bot token lives in the URL, so it is never allowed into an exception
 * message or a log line; failures carry Telegram's description only.
 */
class BotManifestSender
{
    /** Telegram's own backup marker, so the panel finds the manifest too. */
    public const CAPTION = '#tgdrive-backup';

    protected string $token;

    protected string $chat;

    protected int $timeout;

    public function __construct(string $token, string $chat, int $timeout = 30)
    {
        $this->token = trim($token);
        $this->chat = trim($chat);
        $this->timeout = max(5, $timeout);
    }

    /**
     * The bot as configured in the panel (relay_bot_token + relay_bot_storage_chat).
     */
    public static function fromConfig(): self
    {
        return new self(
            (string) config('media_relay.bot.token'),
            (string) config('media_relay.bot.storage_chat')
        );
    }

    /**
     * @param  string  $json  the manifest produced by
     *                        TelegramDriveClient::channelManifest()
     * @return int  message id of the manifest document in the storage channel
     */
    public function __invoke(string $json): int
    {
        if ($this->token === '' || $this->chat === '') {
            throw new RuntimeException(
                'the manifest fallback needs relay_bot_token and relay_bot_storage_chat'
            );
        }

        $name = 'tgdrive-manifest-' . date('Ymd-His') . '.json';

        $response = Http::timeout($this->timeout)
            ->attach('document', $json, $name, ['Content-Type' => 'application/json'])
            ->post($this->endpoint('sendDocument'), [
                'chat_id' => $this->chat,
                'caption' => self::CAPTION . ' ' . $this->chat,
            ]);

        $payload = is_array($response->json()) ? $response->json() : [];
        $messageId = (int) ($payload['result']['message_id'] ?? 0);

        if (!$response->successful() || ($payload['ok'] ?? false) !== true || $messageId <= 0) {
            throw new RuntimeException(sprintf(
                'sendDocument failed: HTTP %d %s',
                $response->status(),
                substr((string) ($payload['description'] ?? 'no description'), 0, 200)
            ));
        }

        return $messageId;
    }

    protected function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token . '/' . $method;
    }
}
