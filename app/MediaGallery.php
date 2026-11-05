<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaGallery extends Model
{
    use HasFactory;

    protected $table = 'media_gallery';

    /**
     * The relay file that holds these bytes, when the entry lives on the
     * combined storage instead of local disk.
     */
    public function relayFile()
    {
        return $this->belongsTo(RelayFile::class, 'relay_file_id');
    }

    /**
     * Point a library entry at the relay file behind its /r/{token} URL.
     */
    public function linkRelayFileFromUrl(?string $url = null): void
    {
        $url = $url ?: $this->file;

        if (empty($url) || empty($this->id)) {
            return;
        }

        $token = self::relayToken($url);

        if ($token === null) {
            return;
        }

        $link = RelayLink::where('token', $token)->first();

        if (!$link) {
            return;
        }

        if ((int) $this->relay_file_id !== (int) $link->relay_file_id) {
            $this->relay_file_id = $link->relay_file_id;
            $this->save();
        }
    }

    /**
     * Create (or refresh) the library entry for a freshly uploaded relay file
     * so it can be picked as a source inside Content.
     */
    public static function registerRelayFile(RelayFile $file, string $url, ?string $title = null): self
    {
        $media = self::where('file', $url)->first() ?: new self();

        $media->title = $title ?: ($media->title ?: $file->name);
        $media->file = $url;
        $media->relay_file_id = $file->id;
        $media->save();

        return $media;
    }

    /**
     * Extract the token from a relay URL on this site's own domain.
     */
    public static function relayToken(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $streamPath = trim((string) config('media_relay.relay.stream_path', 'r/{token}'), '/');
        $downloadPath = trim((string) config('media_relay.relay.download_path', 'd/{token}'), '/');

        foreach ([$streamPath, $downloadPath] as $pattern) {
            $regex = '#^/' . str_replace('\{token\}', '([A-Za-z0-9]+)', preg_quote($pattern, '#')) . '$#';

            if (preg_match($regex, rtrim($path, '/'), $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
