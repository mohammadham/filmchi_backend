<?php

namespace App\Http\Controllers;

use App\Jobs\ImportBotFileJob;
use App\Services\Bot\TelegramBotClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Telegram bot webhook (Flixy side of path B).
 *
 * Security is layered and cheap: the feature flag, the secret path segment and
 * Telegram's secret header must all match before a single row is written, and
 * only numeric ids on the allowlist in a private chat are accepted. Anyone
 * else gets a 200 with no side effect, so a stranger learns nothing and a
 * disallowed user cannot even make the bot retry.
 *
 * Nothing heavy happens here: the update id is recorded for dedup, an allowed
 * /ping is answered so the bot is verifiable without the queue, and the caller
 * returns immediately; the conversation/jobs pick the rest up later.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(protected TelegramBotClient $bot)
    {
    }

    public function handle(Request $request, string $secret)
    {
        $expected = (string) config('media_relay.bot.webhook_secret');
        $token = (string) config('media_relay.bot.token');

        // Not configured or switched off: the endpoint simply does not exist.
        if (!config('media_relay.bot.enabled') || $token === '' || $expected === '') {
            abort(404);
        }

        if (!hash_equals($expected, $secret)) {
            abort(404);
        }

        if (!hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            abort(403);
        }

        $update = $request->json()->all();
        $updateId = (int) ($update['update_id'] ?? 0);

        if ($updateId <= 0) {
            return response()->json(['ok' => false], 400);
        }

        $message = $update['message'] ?? $update['callback_query']['message'] ?? null;
        $from = $update['message']['from'] ?? $update['callback_query']['from'] ?? null;

        if (!$this->allowed($from, $message)) {
            Log::info('bot update ignored', [
                'update_id' => $updateId,
                'user' => (string) ($from['id'] ?? ''),
                'chat' => (string) ($message['chat']['type'] ?? ''),
            ]);

            return response()->json(['ok' => true]);
        }

        // update_id is the primary key: a retried delivery is a no-op, and
        // only the delivery that actually recorded the row queues the import.
        $recorded = DB::table('bot_updates')->insertOrIgnore([
            'update_id' => $updateId,
            'kind' => array_key_exists('callback_query', $update) ? 'callback_query' : 'message',
            'payload' => json_encode($update, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'received_at' => now(),
        ]);

        if ($this->isPing($message)) {
            $this->answerPing((int) ($message['chat']['id'] ?? 0));
        }

        if ($recorded > 0 && is_array($message['document'] ?? null)) {
            ImportBotFileJob::dispatch($updateId);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * `/ping` and `/ping@BotName` (Telegram appends the username in groups).
     * A callback query has no text, so it never matches.
     *
     * @param  array  $message  message
     */
    protected function isPing(array $message): bool
    {
        $text = trim((string) ($message['text'] ?? ''));

        return $text !== '' && preg_match('#^/ping(@\w+)?(\s|$)#i', $text) === 1;
    }

    /** A failed reply must not turn a recorded update into a Telegram retry. */
    protected function answerPing(int $chatId): void
    {
        if ($chatId > 0) {
            $this->bot->trySendMessage($chatId, 'pong');
        }
    }

    /**
     * @param  array|null  $from  message.from / callback_query.from
     * @param  array|null  $message  message / callback_query.message
     */
    protected function allowed($from, $message): bool
    {
        if (!is_array($from) || !is_array($message)) {
            return false;
        }

        if ((string) ($message['chat']['type'] ?? '') !== 'private') {
            return false;
        }

        $id = (string) ($from['id'] ?? '');

        return $id !== '' && ctype_digit($id) && in_array($id, $this->allowlist(), true);
    }

    /**
     * Numeric ids only: usernames can be changed and re-registered by anyone.
     *
     * @return array<int, string>
     */
    protected function allowlist(): array
    {
        $raw = (string) config('media_relay.bot.allowed_ids');

        return array_values(array_filter(
            preg_split('/[\s,]+/', $raw) ?: [],
            fn ($id) => $id !== '' && ctype_digit($id)
        ));
    }
}
