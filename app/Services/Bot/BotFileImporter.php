<?php

namespace App\Services\Bot;

use App\RelayFile;
use App\RelayLink;
use App\Services\Relay\StoredMessageImporter;
use App\Services\Relay\TelegramDriveClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * P2 of the bot plan: turn "a user sent this document to the bot" into a relay
 * file without moving a byte through this server.
 *
 * 1. The file is deduplicated on Telegram's file_unique_id. A file the relay
 *    already stores is returned as-is: no copy, no row, no upload.
 * 2. copyMessage puts the stored document into the storage channel (Telegram
 *    does the copy server-side; the source stays in the user's chat).
 * 3. StoredMessageImporter adopts that copy on Telegram Drive (path B) and
 *    falls back to the manifest + restore path (B″) when adopt is refused.
 * 4. The relay row, its public link and its library entry are written here —
 *    origin=bot, tg_unique_id and bot_user_id say where it came from.
 *
 * The bot token lives in the API URL, so it never reaches an exception message.
 */
class BotFileImporter
{
    protected TelegramDriveClient $drive;

    protected StoredMessageImporter $importer;

    /** @var callable|null */
    protected $manifestSender;

    /** @var int|null the storage-channel copy a previous attempt already made */
    protected ?int $resumeCopyId = null;

    /** @var callable|null */
    protected $copiedHook;

    public function __construct(
        TelegramDriveClient $drive,
        ?StoredMessageImporter $importer = null,
        ?callable $manifestSender = null
    ) {
        $this->drive = $drive;
        $this->importer = $importer ?: new StoredMessageImporter($drive);
        $this->manifestSender = $manifestSender;
    }

    /**
     * Reuse the copy a previous attempt already made. The copy is the expensive
     * side effect of this flow, so a retry must never create a second one.
     */
    public function resumeFrom(int $copyMessageId): static
    {
        $this->resumeCopyId = $copyMessageId > 0 ? $copyMessageId : null;

        return $this;
    }

    /**
     * Called as soon as copyMessage returns. The copy exists in the channel at
     * that moment, so whoever owns the submission row can persist it before the
     * adopt (which may fail) runs.
     */
    public function onCopied(callable $hook): static
    {
        $this->copiedHook = $hook;

        return $this;
    }

    /**
     * @param  array  $file  message_id (the document message in the user's chat),
     *                       file_unique_id, name, size and mime
     * @param  int  $chatId  the private chat the document was sent in
     * @param  int  $userId  the allowlisted Telegram user id
     * @return array{file: RelayFile, link: RelayLink, deduplicated: bool,
     *               copy_message_id: int|null}
     */
    public function import(array $file, int $chatId, int $userId): array
    {
        $uniqueId = trim((string) ($file['file_unique_id'] ?? ''));
        $messageId = (int) ($file['message_id'] ?? 0);
        $chat = trim((string) config('media_relay.bot.storage_chat'));

        if ($uniqueId === '') {
            throw new RuntimeException('the bot import needs the document file_unique_id.');
        }

        if ($messageId <= 0 || $chatId <= 0 || $userId <= 0) {
            throw new RuntimeException('the bot import needs the message id, the chat id and the user id.');
        }

        if ($chat === '') {
            throw new RuntimeException('تنظیم relay_bot_storage_chat (کانال ذخیره) خالی است.');
        }

        // Cheapest possible answer: the same file was already stored, so the
        // second send only needs the existing link.
        $existing = $this->existing($uniqueId);

        if ($existing !== null) {
            return $this->deduped($existing);
        }

        $copiedMessageId = $this->resumeCopyId ?? $this->copyMessage($chat, $chatId, $messageId);

        if ($this->resumeCopyId === null && $this->copiedHook !== null) {
            ($this->copiedHook)($copiedMessageId);
        }

        $message = [
            'message_id' => $copiedMessageId,
            'name' => (string) ($file['name'] ?? ''),
            'size' => (int) ($file['size'] ?? 0),
            'mime' => (string) ($file['mime'] ?? ''),
            'storage_chat' => $chat,
            'uploader' => 'bot:flixy:' . $userId,
        ];

        $result = $this->importer->import(
            $message,
            (int) config('media_relay.bot.channel_id'),
            $this->manifestSender ?: BotManifestSender::fromConfig()
        );

        $relay = $this->register($message, $result, $uniqueId, $userId);

        if ($relay === null) {
            // The unique index on tg_unique_id caught a concurrent import of
            // the same file. The twin row is the answer; the second channel
            // copy it made is harmless (same bytes, no relay row).
            $twin = $this->existing($uniqueId);

            if ($twin === null) {
                throw new RuntimeException('the bot import lost a dedup race but its row is missing.');
            }

            return $this->deduped($twin, $copiedMessageId);
        }

        return [
            'file' => $relay,
            'link' => $this->linkFor($relay),
            'deduplicated' => false,
            'copy_message_id' => $copiedMessageId,
        ];
    }

    /** The existing row is the whole answer; only its link has to exist. */
    protected function deduped(RelayFile $file, ?int $copyMessageId = null): array
    {
        return [
            'file' => $file,
            'link' => $this->linkFor($file),
            'deduplicated' => true,
            'copy_message_id' => $copyMessageId,
        ];
    }

    protected function existing(string $uniqueId): ?RelayFile
    {
        return RelayFile::where('tg_unique_id', $uniqueId)->first();
    }

    /**
     * @param  array  $message  the copy's message_id plus name/size/mime/storage_chat/uploader
     * @param  array  $result  the adopt or manifest answer from StoredMessageImporter
     * @return RelayFile|null  null when a concurrent import already owns the unique id
     */
    protected function register(array $message, array $result, string $uniqueId, int $userId): ?RelayFile
    {
        $fileId = (string) ($result['file_id'] ?? '');

        if ($fileId === '') {
            throw new RuntimeException('Telegram Drive returned no file id for the copied message.');
        }

        $name = trim((string) ($result['name'] ?? $message['name']));

        $relay = new RelayFile();
        $relay->name = $name !== '' ? $name : 'bot-file-' . $uniqueId;
        $relay->size = (int) ($result['size'] ?? $message['size']);
        $relay->mime = ($message['mime'] ?? '') !== '' ? (string) $message['mime'] : null;
        $relay->extension = pathinfo($relay->name, PATHINFO_EXTENSION) ?: null;
        $relay->tg_file_id = $fileId;
        $relay->tg_backend = (string) config('media_relay.telegram.default_backend', 'telegram');
        $relay->tg_storage_chat = (string) ($result['storage_chat'] ?? $message['storage_chat']);
        $relay->cache_state = RelayFile::COLD;
        $relay->ingest_status = 'completed';
        $relay->origin = 'bot';
        $relay->tg_unique_id = $uniqueId;
        $relay->bot_user_id = $userId;

        try {
            $relay->save();
        } catch (QueryException $e) {
            // Only the insert may race another worker (the unique index on
            // tg_unique_id). Anything after it must surface as a real failure,
            // not be mistaken for a duplicate.
            if ($this->existing($uniqueId) === null) {
                throw $e;
            }

            return null;
        }

        $relay->publish('Stored on Telegram Drive (from the bot).');

        return $relay;
    }

    /**
     * A deduplicated file still needs a public link; reuse the live one when
     * there is one so the library does not collect tokens for the same bytes.
     */
    protected function linkFor(RelayFile $file): RelayLink
    {
        return RelayLink::where('relay_file_id', $file->id)->first() ?? RelayLink::issueFor($file);
    }

    /**
     * Telegram copies the document itself: the source message stays in the
     * user's chat and no byte reaches this server.
     */
    public function copyMessage(string $chat, int $fromChatId, int $messageId): int
    {
        $token = trim((string) config('media_relay.bot.token'));

        if ($token === '') {
            throw new RuntimeException('تنظیم relay_bot_token خالی است.');
        }

        $response = Http::timeout(max(5, (int) config('media_relay.bot.copy_timeout', 60)))
            ->post('https://api.telegram.org/bot' . $token . '/copyMessage', [
                'chat_id' => $chat,
                'from_chat_id' => $fromChatId,
                'message_id' => $messageId,
            ]);

        $payload = is_array($response->json()) ? $response->json() : [];
        $description = (string) ($payload['description'] ?? 'no description');
        $copied = (int) ($payload['result']['message_id'] ?? 0);

        if (!$response->successful() || ($payload['ok'] ?? false) !== true || $copied <= 0) {
            throw new RuntimeException($this->explainCopyFailure($response->status(), $description));
        }

        return $copied;
    }

    /**
     * Telegram's own refusals mean different things to the person in the chat;
     * a raw "Bad Request: ..." would not tell them what to change.
     */
    protected function explainCopyFailure(int $status, string $description): string
    {
        $lower = strtolower($description);

        if (str_contains($lower, 'message is protected')) {
            return 'این پیام محافظت‌شده است (کانال مبدأ «حفظ محتوا» دارد) و تلگرام کپی را رد می‌کند.';
        }

        if (str_contains($lower, 'chat not found') || str_contains($lower, 'not enough rights')) {
            return 'ربات به کانال ذخیره دسترسی ندارد؛ ربات را ادمین کانال کن و relay_bot_storage_chat را بررسی کن.'
                . ' (' . substr($description, 0, 160) . ')';
        }

        return sprintf('copyMessage failed: HTTP %d %s', $status, substr($description, 0, 200));
    }
}
