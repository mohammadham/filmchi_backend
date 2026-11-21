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
 * The webhook writes the bot_submissions row (dedup and the daily limit live
 * there) and queues this job with that row's id; everything heavy happens here —
 * copyMessage into the storage channel, adopt (or the manifest fallback) on
 * Telegram Drive, and the answer to the user. Its own "bot" lane keeps a 2 GB
 * import from sitting in front of a panel download.
 */
class ImportBotFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800;

    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [120];

    /** @var int */
    public $submissionId;

    public function __construct(int $submissionId)
    {
        $this->submissionId = $submissionId;

        $this->onQueue((string) config('media_relay.bot.queue', 'bot'));
    }

    public function handle(BotFileImporter $importer, TelegramBotClient $bot): void
    {
        $submission = $this->submission();

        // A queued id can outlive its row (a reset database, a pruned table),
        // and a done row is imported already.
        if ($submission === null || (string) $submission->status === 'done') {
            return;
        }

        try {
            $result = $importer
                ->resumeFrom((int) ($submission->copy_message_id ?? 0))
                ->onCopied(fn (int $copyMessageId) => $this->rememberCopy((int) $submission->id, $copyMessageId))
                ->import([
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
                'submission_id' => (int) $submission->id,
                'retrying' => $willRetry,
                'message' => $e->getMessage(),
            ]);

            // Rethrow so the queue applies the backoff. The worker calls
            // failed() once the attempts run out, and that is the only place
            // the terminal text is sent: sending it here too reached the user
            // twice on the last attempt.
            throw $e;
        }

        $this->setStatus((int) $submission->id, 'done', null, [
            'relay_file_id' => $result['file']->id,
            'copy_message_id' => $result['copy_message_id'],
        ]);

        $this->reply($bot, (int) $submission->chat_id, $result, $submission);
    }

    /** The row the webhook wrote; the job is keyed by it, not by the update. */
    protected function submission(): ?object
    {
        return DB::table('bot_submissions')->where('id', $this->submissionId)->first();
    }

    /**
     * The user always learns the outcome: the public link on success, the
     * reason on a terminal failure.
     */
    protected function reply(TelegramBotClient $bot, int $chatId, array $result, ?object $submission = null): void
    {
        $file = $result['file'];
        $link = $result['link']->streamUrl();

        $text = $result['deduplicated']
            ? "♻️ این فایل قبلاً ذخیره شده بود.\n" . $link
            : '✅ «' . $file->name . '» ذخیره شد (' . $file->human_size . ").\n" . $link;

        // The conversation's answers do not change the bytes, so the reply is
        // the one place the user sees them echoed back.
        $answers = array_filter([
            trim((string) ($submission->quality ?? '')) !== '' ? 'کیفیت ' . $submission->quality : null,
            !empty($submission->is_series) ? 'سریال' : null,
        ]);

        if ($answers !== []) {
            $text .= "\n" . implode(' · ', $answers);
        }

        $bot->trySendMessage($chatId, $text);
    }

    /**
     * The copy id is the retry checkpoint: a later attempt reads it back through
     * BotFileImporter::resumeFrom() instead of copying the document again.
     */
    protected function rememberCopy(int $submissionId, int $copyMessageId): void
    {
        DB::table('bot_submissions')->where('id', $submissionId)->update([
            'copy_message_id' => $copyMessageId,
            'updated_at' => now(),
        ]);
    }

    protected function setStatus(int $submissionId, string $status, ?string $error, array $extra = []): void
    {
        DB::table('bot_submissions')->where('id', $submissionId)->update($extra + [
            'status' => $status,
            'error' => $error === null ? null : Str::limit($error, 500),
            'updated_at' => now(),
        ]);
    }

    /**
     * Timeouts and worker crashes never reach the catch inside handle().
     */
    public function failed(Throwable $e): void
    {
        $submission = $this->submission();

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
