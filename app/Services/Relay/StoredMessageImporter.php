<?php

namespace App\Services\Relay;

use App\Exceptions\PermanentImportFailure;
use RuntimeException;
use Throwable;

/**
 * One entry point for "this document is already in the storage channel".
 *
 * Path B (adopt) is tried first: Telegram Drive reads the stored message back
 * and writes a ready row, so no byte is copied. When the service cannot do
 * that, the importer falls back to B″: the bot uploads a tiny channel-backup
 * manifest into the channel and Telegram Drive merges it through the already
 * registered channel row.
 *
 * The fallback is deliberately limited to refusals that mean "adopt is not
 * available here": 404 (no such endpoint / message not visible to the service)
 * and 503/509 (no readable account for the channel / storage not ready). A 400
 * (size mismatch), 409 (chat pinned to another key) or 422 (malformed body) is
 * a caller bug — or a file record for the wrong destination — so it propagates.
 */
class StoredMessageImporter
{
    /** Telegram Drive answers that mean "cannot adopt here → use the manifest". */
    public const FALLBACK_STATUSES = [404, 503, 509];

    protected TelegramDriveClient $drive;

    public function __construct(TelegramDriveClient $drive)
    {
        $this->drive = $drive;
    }

    /**
     * Import one already-stored message.
     *
     * @param  array  $message  message_id (required) plus name, size, mime, storage_chat,
     *                          folder_path and uploader
     * @param  int|null  $channelId  Telegram Drive channel-registry id (B″ needs it)
     * @param  callable|null  $manifestSender  fn(string $jsonManifest): int — the bot sends
     *                                         that JSON to the channel and returns its message id
     * @return array  adopt answer with path=adopt, or the restored file row with path=manifest
     */
    public function import(array $message, ?int $channelId = null, ?callable $manifestSender = null): array
    {
        $messageId = (int) ($message['message_id'] ?? 0);

        if ($messageId <= 0) {
            throw new RuntimeException('import needs the message_id of the stored copy.');
        }

        try {
            $result = $this->drive->adopt($messageId, $message);
            $result['path'] = 'adopt';

            return $result;
        } catch (TelegramDriveRefusal $e) {
            if (!in_array($e->status(), self::FALLBACK_STATUSES, true)) {
                throw $e;
            }

            return $this->restoreViaManifest($message, $channelId, $manifestSender, $e);
        }
    }

    /**
     * Path B″: manifest into the channel, then ask the service to merge it.
     *
     * @return array  file_id/status plus the restore counters and the refusal
     *                that made this path necessary
     */
    protected function restoreViaManifest(
        array $message,
        ?int $channelId,
        ?callable $manifestSender,
        TelegramDriveRefusal $refusal
    ): array {
        $chat = (string) ($message['storage_chat'] ?? '');
        $size = (int) ($message['size'] ?? 0);

        if ($channelId === null || $channelId <= 0 || $manifestSender === null || $chat === '') {
            throw new RuntimeException(
                'adopt refused with ' . $refusal->status()
                . ' and the manifest fallback needs a registered channel, a channel chat and a manifest sender',
                0,
                $refusal
            );
        }

        if ($size <= 0) {
            // A manifest without a size would register a zero-byte file.
            throw new RuntimeException(
                'the manifest fallback needs the stored document size (copyMessage reports it).',
                0,
                $refusal
            );
        }

        $fileId = $this->drive->newFileId();
        $name = (string) ($message['name'] ?? '');
        $mime = (string) ($message['mime'] ?? '');

        $manifest = $this->drive->channelManifest([[
            'id' => $fileId,
            'message_id' => (int) $message['message_id'],
            'name' => $name,
            'size' => $size,
            'mime' => $mime,
            'storage_chat' => $chat,
            'uploader' => (string) ($message['uploader'] ?? 'bot:flixy'),
        ]], $chat);

        $json = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // restoreFromManifest rejects a missing id itself; no second guard here.
        $manifestMessageId = (int) $manifestSender($json);

        try {
            $restored = $this->drive->restoreFromManifest((int) $channelId, $manifestMessageId);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'channel restore failed (adopt was refused with ' . $refusal->status() . '): ' . $e->getMessage(),
                0,
                $e
            );
        }

        // No poll: the service stores the manifest's status verbatim and adds
        // the parts in the same call (channels.py restore), so the row is
        // usable the moment restore returns. Waiting would only pin a queue
        // worker for ready_timeout on a file that is already there.
        //
        // That is exactly why the counters matter: restore inserts the row with
        // the manifest's status even when a part fails, so a non-zero
        // missing_parts is the only evidence that the stored message is
        // truncated. Registering it would publish a link to a broken file.
        $missingParts = (int) ($restored['missing_parts'] ?? 0);

        // A retry cannot fix bytes that were never stored, and every attempt
        // mints a new file id, so this failure is final: the job must not spend
        // its attempts on it (see PermanentImportFailure).
        if ($missingParts > 0) {
            throw new PermanentImportFailure(sprintf(
                'channel restore stored %s but lost %d file part(s); an incomplete file must not be registered as ready',
                (string) ($restored['file_ids'][0] ?? $fileId),
                $missingParts
            ), 0, $refusal);
        }

        return [
            'path' => 'manifest',
            'file_id' => $fileId,
            'manifest_message_id' => $manifestMessageId,
            'restore' => $restored,
            'adopt_refusal' => ['status' => $refusal->status()],
        ];
    }
}
