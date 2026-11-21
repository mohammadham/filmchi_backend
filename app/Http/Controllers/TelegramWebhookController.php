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
 * returns immediately.
 *
 * P3: a document opens a short bot_sessions conversation (quality, then series)
 * and only the last answer writes the bot_submissions row and queues
 * ImportBotFileJob. Asking first keeps the row a finished decision instead of a
 * half-filled one, and the candidate document waits in the session payload, not
 * in the queue. The webhook owns dedup and the daily limit because those are
 * decisions about the request, not about the import.
 */
class TelegramWebhookController extends Controller
{
    /** How long a half-answered conversation waits for the next message. */
    protected const SESSION_MINUTES = 30;

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
        // only the delivery that actually recorded the row moves the flow on.
        $recorded = DB::table('bot_updates')->insertOrIgnore([
            'update_id' => $updateId,
            'kind' => array_key_exists('callback_query', $update) ? 'callback_query' : 'message',
            'received_at' => now(),
        ]);

        if ($recorded > 0) {
            $this->route($message, $updateId);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A ping, a document, or an answer to the question the bot just asked.
     *
     * @param  array  $message  message
     */
    protected function route(array $message, int $updateId): void
    {
        $greeting = $this->greeting($message);

        if ($greeting !== null) {
            $this->answerGreeting($greeting, (int) ($message['chat']['id'] ?? 0));

            return;
        }

        if (is_array($message['document'] ?? null)) {
            $this->beginSubmission($message);

            return;
        }

        $this->continueSession($message, $updateId);
    }

    /**
     * A document opens the conversation: its metadata is parked in bot_sessions
     * until the last answer arrives, so an abandoned chat leaves no submission.
     *
     * @param  array  $message  message
     */
    protected function beginSubmission(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($message['from']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $document = $message['document'];
        $uniqueId = (string) ($document['file_unique_id'] ?? '');

        if ($chatId <= 0 || $userId <= 0 || $messageId <= 0 || $uniqueId === '') {
            return;
        }

        // A second document mid-conversation replaces the parked one: the user
        // changed their mind, and only the newest file should ever be imported.
        $this->saveSession($userId, $chatId, 'quality', [
            'message_id' => $messageId,
            'file_unique_id' => $uniqueId,
            'name' => (string) ($document['file_name'] ?? ''),
            'size' => (int) ($document['file_size'] ?? 0),
            'mime' => (string) ($document['mime_type'] ?? ''),
        ]);

        $this->bot->trySendMessage($chatId, 'کیفیت را انتخاب کن: 1080، 720 یا 480');
    }

    /**
     * Advance the conversation parked for this user/chat. Returns false when the
     * message is not an answer, so unrelated text stays a no-op.
     *
     * @param  array  $message  message
     */
    protected function continueSession(array $message, int $updateId): bool
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($message['from']['id'] ?? 0);
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId <= 0 || $userId <= 0 || $text === '') {
            return false;
        }

        $session = DB::table('bot_sessions')
            ->where('telegram_user_id', $userId)
            ->where('chat_id', $chatId)
            ->where('expires_at', '>', now())
            ->first();

        if ($session === null) {
            return false;
        }

        $payload = json_decode((string) $session->payload, true);
        $payload = is_array($payload) ? $payload : [];

        if ($session->state === 'quality') {
            $quality = $this->qualityAnswer($text);

            if ($quality === null) {
                $this->bot->trySendMessage($chatId, 'یکی از کیفیتهای 1080، 720 یا 480 را بفرست.');

                return true;
            }

            $payload['quality'] = $quality;
            $this->saveSession($userId, $chatId, 'series', $payload);
            $this->bot->trySendMessage($chatId, 'این فایل بخشی از یک سریال است؟ (بله یا خیر)');

            return true;
        }

        if ($session->state === 'series') {
            $isSeries = $this->seriesAnswer($text);

            if ($isSeries === null) {
                $this->bot->trySendMessage($chatId, 'لطفاً «بله» یا «خیر» بنویس.');

                return true;
            }

            DB::table('bot_sessions')->where('id', $session->id)->delete();

            $this->finishSubmission($payload, $userId, $chatId, $updateId, $isSeries);

            return true;
        }

        return false;
    }

    /**
     * The last answer: the parked document plus both answers become the
     * submission row, and only then is the import queued.
     *
     * @param  array  $payload  the parked document (and the chosen quality)
     */
    protected function finishSubmission(array $payload, int $userId, int $chatId, int $updateId, bool $isSeries): void
    {
        if ($this->overDailyLimit($userId)) {
            Log::info('bot daily limit reached', ['update_id' => $updateId, 'user' => $userId]);
            $this->bot->trySendMessage($chatId, '⏳ سقف ورود روزانهٔ شما پر شده است؛ فردا دوباره تلاش کنید.');

            return;
        }

        $quality = (string) ($payload['quality'] ?? '');

        $recorded = DB::table('bot_submissions')->insertOrIgnore([
            'telegram_user_id' => $userId,
            'chat_id' => $chatId,
            'message_id' => (int) ($payload['message_id'] ?? 0),
            'file_unique_id' => (string) ($payload['file_unique_id'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'size' => (int) ($payload['size'] ?? 0),
            'mime' => (string) ($payload['mime'] ?? ''),
            'quality' => $quality !== '' ? $quality : null,
            'is_series' => $isSeries,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($recorded === 0) {
            // (chat_id, message_id) is unique: this file already has a row, so
            // a second delivery must not queue a second import.
            $this->bot->trySendMessage($chatId, 'این فایل قبلاً ثبت شده است.');

            return;
        }

        $this->queueImport($chatId, (int) ($payload['message_id'] ?? 0));

        $this->bot->trySendMessage($chatId, $isSeries
            ? '✅ ثبت شد؛ کیفیت ' . $quality . '، به‌عنوان قسمت سریال.'
            : '✅ ثبت شد؛ کیفیت ' . $quality . '. فایل در صف ذخیره است.');
    }

    /** The row is the job's only input, so the id lookup is the whole hand-off. */
    protected function queueImport(int $chatId, int $messageId): void
    {
        $submissionId = (int) DB::table('bot_submissions')
            ->where('chat_id', $chatId)
            ->where('message_id', $messageId)
            ->value('id');

        if ($submissionId > 0) {
            ImportBotFileJob::dispatch($submissionId);
        }
    }

    protected function saveSession(int $userId, int $chatId, string $state, array $payload): void
    {
        DB::table('bot_sessions')->updateOrInsert(
            ['telegram_user_id' => $userId, 'chat_id' => $chatId],
            [
                'state' => $state,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'expires_at' => now()->addMinutes(self::SESSION_MINUTES),
                'updated_at' => now(),
            ]
        );
    }

    /** The three offered qualities, normalized; null means "ask again". */
    protected function qualityAnswer(string $text): ?string
    {
        return preg_match('/\b(1080|720|480)\b/', $text, $match) === 1 ? $match[1] : null;
    }

    /** Null means "ask again"; a plain yes/no is all this step needs. */
    protected function seriesAnswer(string $text): ?bool
    {
        $answer = mb_strtolower(trim($text));

        if (in_array($answer, ['بله', 'آری', 'اره', 'سریال', 'yes', 'y', '1', 'true'], true)) {
            return true;
        }

        if (in_array($answer, ['خیر', 'نه', 'فیلم', 'no', 'n', '0', 'false'], true)) {
            return false;
        }

        return null;
    }

    /** 0 (or less) means unlimited; only today's accepted rows count. */
    protected function overDailyLimit(int $userId): bool
    {
        $limit = (int) config('media_relay.bot.daily_limit', 0);

        if ($limit <= 0) {
            return false;
        }

        return DB::table('bot_submissions')
            ->where('telegram_user_id', $userId)
            ->where('created_at', '>=', today())
            ->count() >= $limit;
    }

    /**
     * `/ping` and `/start`, with Telegram's optional `@BotName` suffix (and an
     * argument) in groups. `/start` matters as much as `/ping`: it is the
     * button every user presses first, and a silent bot looks broken.
     *
     * @param  array  $message  message
     * @return string|null  the command name, or null when this is not one
     */
    protected function greeting(array $message): ?string
    {
        $text = trim((string) ($message['text'] ?? ''));

        if ($text !== '' && preg_match('#^/(ping|start)(@\w+)?(\s|$)#i', $text, $match) === 1) {
            return strtolower($match[1]);
        }

        return null;
    }

    /** A failed reply must not turn a recorded update into a Telegram retry. */
    protected function answerGreeting(string $command, int $chatId): void
    {
        if ($chatId <= 0) {
            return;
        }

        $this->bot->trySendMessage($chatId, $command === 'start'
            ? 'سلام! سند یا ویدیو را بفرست تا ذخیره‌اش کنم.'
            : 'pong');
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
