<?php

namespace App\Services\Relay;

use App\RelayEvent;
use App\RelayFile;
use App\RelayLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores files uploaded from the panel on the relay's primary tier and hands
 * back a public link on this site's domain.
 *
 * This is what backs the "combined" storage type: bytes live on Telegram
 * Drive, in-demand files are promoted into the 9drive cache, and viewers only
 * ever see /r/{token} on the site domain.
 */
class RelayStorage
{
    /** @var RelayManager */
    protected $relay;

    public function __construct(RelayManager $relay)
    {
        $this->relay = $relay;
    }

    public function isReady(): bool
    {
        return (bool) config('media_relay.enabled') && $this->relay->telegram()->isConfigured();
    }

    public function storeUploadedFile(UploadedFile $file, string $name = null): string
    {
        $name = $name ?: $file->getClientOriginalName();

        return $this->storePath(
            $file->getRealPath(),
            $name,
            $file->getMimeType(),
            $file->getClientOriginalExtension(),
            (int) $file->getSize()
        );
    }

    public function storeUrl(string $url, string $name = null): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'flixy_relay_');

        if ($tmp === false) {
            return null;
        }

        try {
            $in = @fopen($url, 'rb');

            if ($in === false) {
                return null;
            }

            $out = fopen($tmp, 'wb');

            if ($out === false) {
                fclose($in);

                return null;
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $name = $name ?: (basename($path) ?: 'media');

            return $this->storePath(
                $tmp,
                $name,
                null,
                pathinfo($name, PATHINFO_EXTENSION),
                (int) @filesize($tmp)
            );
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public function storePath(string $path, string $name, string $mime = null, string $extension = null, int $size = 0): string
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Media relay is disabled or Telegram Drive is not configured.');
        }

        $chat = (string) config('media_relay.telegram.storage_chat', '');

        $result = $this->relay->telegram()->uploadFromPath(
            $path,
            $name,
            $mime ?: 'application/octet-stream',
            $chat
        );

        $fileId = $result['file_id'] ?? ($result['id'] ?? null);

        if (empty($fileId)) {
            throw new RuntimeException('Telegram Drive did not return a file id.');
        }

        $file = new RelayFile();
        $file->name = $name;
        $file->size = (int) ($result['size'] ?? $size);
        $file->mime = $result['mime'] ?? $mime;
        $file->extension = $extension;
        $file->tg_file_id = (string) $fileId;
        $file->tg_backend = (string) config('media_relay.telegram.default_backend', 'telegram');
        $file->tg_storage_chat = $chat;
        $file->cache_state = RelayFile::COLD;
        $file->owner = 'panel';
        $file->save();

        $link = new RelayLink();
        $link->relay_file_id = $file->id;
        $link->token = Str::random(40);
        $link->mode = RelayLink::MODE_STREAM;
        $link->label = $name;
        $link->save();

        RelayEvent::create([
            'relay_file_id' => $file->id,
            'event' => 'uploaded',
            'served_by' => 'telegram',
            'bytes' => (int) $file->size,
            'message' => 'Stored on Telegram Drive (combined storage).',
            'created_at' => now(),
        ]);

        return $link->streamUrl();
    }
}
