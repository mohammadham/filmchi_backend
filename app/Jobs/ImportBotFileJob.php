<?php

namespace App\Jobs;

use App\Services\Bot\BotFileImporter;
use App\Services\Bot\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * P2 of the bot plan: one queued import per document update.
 *
 * The webhook stores the update and queues this job; everything heavy happens
 * here — copyMessage into the storage channel, adopt (or the manifest
 * fallback) on Telegram Drive, the submission row, and the answer to the user.
 * Its own "bot" lane keeps a 2 GB import from sitting in front of a panel
 * download.
 */
class ImportBotFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800;

    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [120];

    /** @var int */
    public $updateId;

    public function __construct(int $updateId)
    {
        $this->updateId = $updateId;

        $this->onQueue((string) config('media_relay.bot.queue', 'bot'));
    }

    public function handle(BotFileImporter $importer, TelegramBotClient $bot): void
    {
        $update = $this->update();

        if ($update === null) {
            // Not a document update (text, callback, a document without ids):
            // nothing for this lane to do.
            return;
        }

        [$message, $document] = $update;

        $submission = $this->submission($message, $document);

        if ($submission === null || (string) $submission->status === 'done') {
            return;
        }

        try {
            $result = $importer->import([
                'message_id' => (int) $submission->message_id,
                'file_unique_id' => (string) $submission->file_unique_id,
                'name' => (string) ($submission->name ?? ''),
                'size' => (int) ($submission->size ?? 0),
                'mime' => (string) ($submission->mime ?? ''),
            ], (int) $submission->chat_id, (int) $submission->telegram_user_id);
        } catch (Throwable $e) {
            $willRetry = $this->attempts() < max(1, (int) $this->tries);

            $this->setStatus(
                (int) $submission->id,
                $willRetry ? 'retrying' : 'failed',
                $e->getMessage()
            );

            Log::warning('bot file import failed', [
                'update_id' => $this->updateId,
                'submission_id' => (int) $submission->id,
                'retrying' => $willRetry,
                'message' => $e->getMessage(),
            ]);

            if (!$willRetry) {
                $bot->trySendMessage((int) $submission->chat_id, '❌ ذخیره نشد: ' . Str::limit($e->getMessage(), 300));
            }

            // Rethrow so the queue applies the backoff; failed() is the
            // terminal state if every attempt runs out.
            throw $e;
        }

        $this->setStatus((int) $submission->id, 'done', null, [
            'relay_file_id' => $result['file']->id,
            'copy_message_id' => $result['copy_message_id'],
        ]);

        $this->reply($bot, (int) $submission->chat_id, $result);
    }

    /**
     * A document update's message and its document, or null when this update
     * carries no file (or has no usable ids).
     *
     * @return array{0: array, 1: array}|null
     */
    protected function update(): ?array
    {
        $raw = DB::table('bot_updates')->where('update_id', $this->updateId)->value('payload');
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        $message = is_array($payload) && is_array($payload['message'] ?? null) ? $payload['message'] : null;

        if ($message === null || !is_array($message['document'] ?? null)) {
            return null;
        }

        $document = $message['document'];

        $usable = (int) ($message['chat']['id'] ?? 0) > 0
            && (int) ($message['from']['id'] ?? 0) > 0
            && (int) ($message['message_id'] ?? 0) > 0
            && (string) ($document['file_unique_id'] ?? '') !== '';

        return $usable ? [$message, $document] : null;
    }

    /**
     * One row per (chat, message): the webhook dedup key is the update, this is
     * the file's own record. The job creates it so retries and the panel read
     * the same metadata the user sent.
     */
    protected function submission(array $message, array $document): ?object
    {
        $chatId = (int) $message['chat']['id'];
        $messageId = (int) $message['message_id'];

        DB::table('bot_submissions')->insertOrIgnore([
            'telegram_user_id' => (int) $message['from']['id'],
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'file_unique_id' => (string) $document['file_unique_id'],
            'name' => (string) ($document['file_name'] ?? ''),
            'size' => (int) ($document['file_size'] ?? 0),
            'mime' => (string) ($document['mime_type'] ?? ''),
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('bot_submissions')
            ->where('chat_id', $chatId)
            ->where('message_id', $messageId)
            ->first();
    }

    /**
     * The user always learns the outcome: the public link on success, the
     * reason on a terminal failure.
     */
    protected function reply(TelegramBotClient $bot, int $chatId, array $result): void
    {
        $file = $result['file'];
        $link = $result['link']->streamUrl();

        $text = $result['deduplicated']
            ? "♻️ این فایل قبلاً ذخیره شده بود.\n" . $link
            : '✅ «' . $file->name . '» ذخیره شد (' . $file->human_size . ").\n" . $link;

        $bot->trySendMessage($chatId, $text);
    }

    protected function setStatus(int $submissionId, string $status, ?string $error, array $extra = []): void
    {
        DB::table('bot_submissions')->where('id', $submissionId)->update($extra + [
            'status' => $status,
            'error' => $error === null ? null : Str::limit($error, 500),
            'updated_at' => now(),
        ]);
    }

    protected function storedSubmission(): ?object
    {
        $update = $this->update();

        if ($update === null) {
            return null;
        }

        [$message] = $update;

        return DB::table('bot_submissions')
            ->where('chat_id', (int) $message['chat']['id'])
            ->where('message_id', (int) $message['message_id'])
            ->first();
    }

    /**
     * Timeouts and worker crashes never reach the catch inside handle().
     */
    public function failed(Throwable $e): void
    {
        $submission = $this->storedSubmission();

        if ($submission === null) {
            return;
        }

        $this->setStatus((int) $submission->id, 'failed', $e->getMessage());

        app(TelegramBotClient::class)->trySendMessage(
            (int) $submission->chat_id,
            '❌ ذخیره نشد: ' . Str::limit($e->getMessage(), 300)
        );
    }
}
