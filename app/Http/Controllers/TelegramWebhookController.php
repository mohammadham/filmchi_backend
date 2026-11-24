<?php

namespace App\Http\Controllers;

use App\Jobs\ImportBotFileJob;
use App\RelayFile;
use App\RelayLink;
use App\Services\Bot\BotImportQueue;
use App\Services\Bot\TelegramBotClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /** `/help`: the commands the bot answers, then the two questions it asks. */
    protected const HELP_TEXT = "راهنما:\n"
        . "/start — سلام و شروع\n"
        . "/ping — تست اینکه ربات پاسخ می‌دهد\n"
        . "/cancel — لغو گفتگوی نیمه‌کاره\n"
        . 'ثبت فایل: فایل را با گزینهٔ Document (Send as file) بفرست، بعد کیفیت (1080/720/480) و سریال بودن را جواب بده؛ در پایان لینک پخش می‌آید.';

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
            // A bot added to a group sees every message there, so the line is
            // cooled down per chat: the first ignored update of the hour says it.
            $this->infoOnceAnHour('ignored:' . (string) ($message['chat']['id'] ?? ''), 'bot update ignored', [
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

        // The way out of a half-answered conversation. Checked before the
        // document so `/cancel` never has to compete with an attachment, and
        // before the session step so any text cannot swallow it.
        if ($this->isCancel($message)) {
            $this->cancelSession($message);

            return;
        }

        if (is_array($message['document'] ?? null)) {
            $this->beginSubmission($message);

            return;
        }

        // Telegram delivers a photo, a video, a GIF, a round video, audio, a
        // voice note or a sticker without a file name (and often recompressed),
        // so none of them can be imported. Say that instead of leaving the chat
        // silent.
        if ($this->isMediaWithoutFileName($message)) {
            $this->bot->trySendMessage(
                (int) ($message['chat']['id'] ?? 0),
                '📄 فایل را به‌صورت Document (Send as file) بفرست تا ذخیره شود؛ عکس، ویدیو، صدا، ویس، استیکر و گیف نام فایل ندارند.'
            );

            return;
        }

        $this->continueSession($message, $updateId);
    }

    /**
     * Media kinds the Bot API delivers without a file name.
     *
     * @param  array  $message  message
     */
    protected function isMediaWithoutFileName(array $message): bool
    {
        foreach (['photo', 'video', 'animation', 'video_note', 'audio', 'voice', 'sticker'] as $kind) {
            if (!empty($message[$kind])) {
                return true;
            }
        }

        return false;
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

        // The same bytes are already in the relay: asking the two questions
        // and queueing an import that would only answer "already stored" wastes
        // the user's time, so the deduped answer comes before the first question
        // — and before the size cap, which guards what would be imported.
        $stored = $this->storedAnswer($uniqueId);

        if ($stored !== null) {
            $this->bot->trySendMessage($chatId, $stored);

            return;
        }

        // The daily limit counts documents, not bytes: one huge file can still
        // eat the whole disk budget, so the size cap is checked before anything
        // is written or queued.
        $sizeBytes = (int) ($document['file_size'] ?? 0);
        $capMb = (int) config('media_relay.bot.max_size_mb', 0);

        if ($capMb > 0 && $sizeBytes > $capMb * 1024 * 1024) {
            $this->infoOnceAnHour('size-cap:' . $userId, 'bot document over the size cap', [
                'user' => $userId,
                'size' => $sizeBytes,
                'cap_mb' => $capMb,
            ]);

            $this->bot->trySendMessage($chatId, sprintf(
                '⚠️ این فایل %s است و از سقف %s مگابایتی ربات بزرگ‌تر است؛ نسخهٔ کم‌حجم‌تر را بفرست.',
                $this->humanSize($sizeBytes),
                $capMb
            ));

            return;
        }

        // A second document mid-conversation replaces the parked one: the user
        // changed their mind, and only the newest file should ever be imported.
        // The replaced file was never stored, so say it was dropped instead of
        // letting them believe both will arrive.
        $replacedName = $this->replacedDocumentName($userId, $chatId, $uniqueId);

        $this->saveSession($userId, $chatId, 'quality', [
            'message_id' => $messageId,
            'file_unique_id' => $uniqueId,
            'name' => (string) ($document['file_name'] ?? ''),
            'size' => (int) ($document['file_size'] ?? 0),
            'mime' => (string) ($document['mime_type'] ?? ''),
        ]);

        $question = 'کیفیت را انتخاب کن: 1080، 720 یا 480 (/cancel برای لغو)';

        if ($replacedName !== null) {
            $question = '⚠️ فایل ' . ($replacedName !== '' ? '«' . $replacedName . '» ' : 'قبلی ')
                . 'لغو شد و ذخیره نمی‌شود؛ فایل جدید جایش را گرفت.'
                . "\n" . $question;
        }

        $this->bot->trySendMessage($chatId, $question);
    }

    /**
     * The name of the parked document this one replaces, or null.
     *
     * Only a *different* file counts: re-sending the same document (a retried
     * delivery, a second copy) is the same file, and calling that a cancel
     * would be noise. An empty string is a replaced file with no name.
     */
    protected function replacedDocumentName(int $userId, int $chatId, string $uniqueId): ?string
    {
        $session = DB::table('bot_sessions')
            ->where('telegram_user_id', $userId)
            ->where('chat_id', $chatId)
            ->where('expires_at', '>', now())
            ->first();

        if ($session === null) {
            return null;
        }

        $payload = json_decode((string) $session->payload, true);
        $payload = is_array($payload) ? $payload : [];
        $parked = (string) ($payload['file_unique_id'] ?? '');

        if ($parked === '' || $parked === $uniqueId) {
            return null;
        }

        return trim((string) ($payload['name'] ?? ''));
    }

    /** GB/MB/KB for the size-cap reply, so the number reads like the panel's. */
    protected function humanSize(int $bytes): string
    {
        foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $unit => $scale) {
            if ($bytes >= $scale) {
                return round($bytes / $scale, $bytes % $scale === 0 ? 0 : 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }

    /**
     * The stored copy's answer, or null when this file is new.
     *
     * `relay_files.tg_unique_id` is the key BotFileImporter dedupes on, so the
     * webhook and the job can never disagree about what "already stored" means.
     */
    protected function storedAnswer(string $uniqueId): ?string
    {
        $file = RelayFile::where('tg_unique_id', $uniqueId)->first();

        if ($file === null) {
            return null;
        }

        $link = RelayLink::where('relay_file_id', $file->id)->first();

        return '♻️ این فایل قبلاً ذخیره شده بود.' . ($link !== null ? "
" . $link->streamUrl() : '');
    }

    /**
     * Advance the conversation parked for this user/chat. Unrelated text (
     * or an expired session) simply changes nothing.
     *
     * @param  array  $message  message
     */
    protected function continueSession(array $message, int $updateId): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($message['from']['id'] ?? 0);
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId <= 0 || $userId <= 0 || $text === '') {
            return;
        }

        $session = DB::table('bot_sessions')
            ->where('telegram_user_id', $userId)
            ->where('chat_id', $chatId)
            ->where('expires_at', '>', now())
            ->first();

        if ($session === null) {
            return;
        }

        $payload = json_decode((string) $session->payload, true);
        $payload = is_array($payload) ? $payload : [];

        if ($session->state === 'quality') {
            $quality = $this->qualityAnswer($text);

            if ($quality === null) {
                $this->bot->trySendMessage($chatId, 'یکی از کیفیتهای 1080، 720 یا 480 را بفرست. (/cancel برای لغو)');

                return;
            }

            $payload['quality'] = $quality;
            $this->saveSession($userId, $chatId, 'series', $payload);
            $this->bot->trySendMessage($chatId, 'این فایل بخشی از یک سریال است؟ (بله یا خیر)');

            return;
        }

        if ($session->state === 'series') {
            $isSeries = $this->seriesAnswer($text);

            if ($isSeries === null) {
                $this->bot->trySendMessage($chatId, 'لطفاً «بله» یا «خیر» بنویس. (/cancel برای لغو)');

                return;
            }

            DB::table('bot_sessions')->where('id', $session->id)->delete();

            $this->finishSubmission($payload, $userId, $chatId, $updateId, $isSeries);
        }
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
            $this->infoOnceAnHour('daily-limit:' . $userId, 'bot daily limit reached', ['update_id' => $updateId, 'user' => $userId]);
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
            // No worker behind the queue (sync runs the import here, null drops
            // it): said once an hour, by this request or the supervisor sweep.
            BotImportQueue::warnOnceAnHour();

            ImportBotFileJob::dispatch($submissionId);
        }
    }

    /**
     * One line per key per hour. A statement that does not change between
     * updates (a refused file, an ignored group) would otherwise write itself
     * once per request and bury the rest of the log. Cache::add is atomic, so of
     * the requests in the window exactly one writes the line.
     */
    protected function infoOnceAnHour(string $key, string $message, array $context = []): void
    {
        if (Cache::add('relay:bot:info:' . $key, true, now()->addHour())) {
            Log::info($message, $context);
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
     * `/cancel`: the parked document is dropped with the session, so nothing is
     * imported and the next text starts fresh instead of re-asking the question.
     */
    protected function cancelSession(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $userId = (int) ($message['from']['id'] ?? 0);

        if ($chatId <= 0 || $userId <= 0) {
            return;
        }

        // Scoped to this user and chat, and deliberately not filtered by
        // expires_at: an expired leftover row must not survive the cancel either.
        $removed = DB::table('bot_sessions')
            ->where('telegram_user_id', $userId)
            ->where('chat_id', $chatId)
            ->delete();

        // No submission row is touched: it is only written by the last answer,
        // so dropping the session is the whole cancel.
        $this->bot->trySendMessage($chatId, $removed > 0
            ? '❌ لغو شد؛ سند ذخیره نشد. هر وقت خواستی دوباره بفرست.'
            : 'گفت‌وگویی در جریان نیست. فایل را به‌صورت سند (Document) بفرست.');
    }

    /** `/cancel`, with the same optional `@BotName` suffix as the greetings. */
    protected function isCancel(array $message): bool
    {
        $text = trim((string) ($message['text'] ?? ''));

        return $text !== '' && preg_match('#^/cancel(@\w+)?(\s|$)#i', $text) === 1;
    }

    /**
     * `/ping`, `/start` and `/help`, with Telegram's optional `@BotName` suffix (and an
     * argument) in groups. `/start` matters as much as `/ping`: it is the
     * button every user presses first, and a silent bot looks broken.
     *
     * @param  array  $message  message
     * @return string|null  the command name, or null when this is not one
     */
    protected function greeting(array $message): ?string
    {
        $text = trim((string) ($message['text'] ?? ''));

        if ($text !== '' && preg_match('#^/(ping|start|help)(@\w+)?(\s|$)#i', $text, $match) === 1) {
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

        $this->bot->trySendMessage($chatId, match ($command) {
            'start' => 'سلام! فایل را به‌صورت سند (Document) بفرست تا ذخیره‌اش کنم.',
            'help' => self::HELP_TEXT,
            default => 'pong',
        });
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
