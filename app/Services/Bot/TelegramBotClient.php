<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Cache;
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
        $result = $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
        $messageId = (int) ($result['message_id'] ?? 0);

        if ($messageId <= 0) {
            throw new RuntimeException('sendMessage failed: no message id');
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
            // A blocked bot fails every reply, so the line is cooled down per
            // chat: one an hour still names the chat, without one per message.
            if (Cache::add('relay:bot:warn:message-failed:' . $chatId, true, now()->addHour())) {
                Log::warning('bot message failed', [
                    'chat' => $chatId,
                    'message' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * One Bot API call. The token only ever lives in the URL, so a failure
     * message carries the method name and Telegram's own description.
     */
    protected function call(string $method, array $params = []): array
    {
        if ($this->token === '') {
            throw new RuntimeException('تنظیم relay_bot_token خالی است.');
        }

        $response = Http::timeout($this->timeout())->post($this->endpoint($method), $params);
        $payload = is_array($response->json()) ? $response->json() : [];

        if (!$response->successful() || ($payload['ok'] ?? false) !== true) {
            throw new RuntimeException(sprintf(
                '%s failed: HTTP %d %s',
                $method,
                $response->status(),
                substr((string) ($payload['description'] ?? 'no description'), 0, 200)
            ));
        }

        $result = $payload['result'] ?? [];

        return is_array($result) ? $result : ['value' => $result];
    }

    /** @return array{url: string, pending: int, last_error: string} */
    public function webhookInfo(): array
    {
        $info = $this->call('getWebhookInfo');

        return [
            'url' => (string) ($info['url'] ?? ''),
            'pending' => (int) ($info['pending_update_count'] ?? 0),
            'last_error' => (string) ($info['last_error_message'] ?? ''),
        ];
    }

    /**
     * Telegram only accepts the secret header when setWebhook registers it,
     * so the panel sets both together and they can never drift apart.
     */
    public function setWebhook(string $url, string $secret = ''): void
    {
        $params = ['url' => $url, 'drop_pending_updates' => false];

        if ($secret !== '') {
            $params['secret_token'] = $secret;
        }

        $this->call('setWebhook', $params);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook');
    }

    /**
     * The menu behind "/", registered with the webhook so the user never has to
     * type a command to discover it. The list travels as one JSON field because
     * the Bot API does not read nested form arrays.
     */
    public const COMMANDS = [
        ['command' => 'start', 'description' => 'شروع و خوش‌آمد'],
        ['command' => 'ping', 'description' => 'تست پاسخ‌دهی ربات'],
        ['command' => 'help', 'description' => 'راهنمای دستورها'],
        ['command' => 'cancel', 'description' => 'لغو گفتگوی نیمه‌کاره'],
    ];

    /** An empty list is how the Bot API clears the "/" menu again. */
    public function setMyCommands(array $commands = self::COMMANDS): void
    {
        $this->call('setMyCommands', ['commands' => json_encode($commands, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Panel badge answer: never throws, so an unreachable Telegram is a red
     * badge instead of a 500 on the settings page.
     *
     * @return array{ok: bool, message: string}
     */
    public function health(): array
    {
        if ($this->token === '') {
            return ['ok' => false, 'message' => 'token is not set'];
        }

        try {
            $me = $this->call('getMe');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $username = trim((string) ($me['username'] ?? ''));

        return ['ok' => true, 'message' => $username !== '' ? '@' . $username : 'reachable'];
    }

    /**
     * A webhook reply is a courtesy: Telegram expects the delivery to be
     * acknowledged in a few seconds, and the conversation waits on this call.
     * The default is well inside that budget while still riding out a slow hop.
     */
    protected function timeout(): int
    {
        return max(2, (int) config('media_relay.bot.reply_timeout', 5));
    }

    protected function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token . '/' . $method;
    }
}
