<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The smallest useful Bot API client: send one text message.
 *
 * The webhook needs to answer an allowed user so "the bot is alive" is
 * verifiable end to end, and the import job reports its result the same way.
 * The token lives in the URL, so it never reaches an exception message.
 */
class TelegramBotClient
{
    protected string $token;

    public function __construct(?string $token = null)
    {
        $this->token = trim((string) ($token ?? config('media_relay.bot.token')));
    }

    /**
     * @param  int|string  $chatId  the private chat or channel id
     * @return int  message id of the sent text
     */
    public function sendMessage($chatId, string $text): int
    {
        if ($this->token === '') {
            throw new RuntimeException('تنظیم relay_bot_token خالی است.');
        }

        $response = Http::timeout(10)
            ->post($this->endpoint('sendMessage'), [
                'chat_id' => $chatId,
                'text' => $text,
            ]);

        $payload = is_array($response->json()) ? $response->json() : [];
        $messageId = (int) ($payload['result']['message_id'] ?? 0);

        if (!$response->successful() || ($payload['ok'] ?? false) !== true || $messageId <= 0) {
            throw new RuntimeException(sprintf(
                'sendMessage failed: HTTP %d %s',
                $response->status(),
                substr((string) ($payload['description'] ?? 'no description'), 0, 200)
            ));
        }

        return $messageId;
    }

    /**
     * A reply is a courtesy: a failure is logged and never turns a recorded
     * webhook update (or a stored file) into an error.
     */
    public function trySendMessage($chatId, string $text): bool
    {
        try {
            $this->sendMessage($chatId, $text);

            return true;
        } catch (Throwable $e) {
            Log::warning('bot message failed', [
                'chat' => $chatId,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token . '/' . $method;
    }
}
