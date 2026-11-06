<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps title artwork on our own disk instead of hotlinking the scraper
 * source. Those hosts move their upload paths around and rate-limit hotlinks,
 * which shows up in the panel as broken posters on otherwise healthy titles.
 */
class PosterStore
{
    /** Folder on the public disk, served as <app url>/storage/<folder>. */
    public const FOLDER = 'uploads/posters';

    /** No piece of artwork is worth more than this. */
    protected const MAX_BYTES = 8388608;

    /** True when the value already points at artwork we serve ourselves. */
    public function isLocal(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        return str_starts_with($url, '/')
            || str_starts_with($url, $this->baseUrl() . '/')
            || $url === $this->baseUrl();
    }

    /** Public base url of the `public` disk, without a trailing slash. */
    public function baseUrl(): string
    {
        $configured = config('filesystems.disks.public.url');

        return rtrim((string) ($configured ?: config('app.url') . '/storage'), '/');
    }

    /**
     * Download remote artwork once and serve it from our own disk.
     *
     * Returns the URL to store in the row: the local copy when the download
     * worked, the untouched value when it was already local, and null when the
     * source gave us nothing usable (a caller can then keep what it had).
     */
    public function localize(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $url) || $this->isLocal($url)) {
            return $url;
        }

        try {
            // Artwork is a few hundred KB: a slow source is not worth waiting on
            // and the caller keeps the remote URL as a fallback.
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => (string) config('media_relay.relay.user_agent', 'flixy-media-relay/1.0')])
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('Poster download failed', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('Poster download failed', ['url' => $url, 'status' => $response->status()]);

            return null;
        }

        $bytes = $response->body();
        $type = strtolower((string) $response->header('Content-Type'));

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || !str_starts_with($type, 'image/')) {
            Log::warning('Poster download rejected', ['url' => $url, 'type' => $type, 'bytes' => strlen($bytes)]);

            return null;
        }

        // A 200 with an error page behind an image content type would otherwise
        // be stored as a corrupt poster.
        if (@getimagesizefromstring($bytes) === false) {
            Log::warning('Poster download is not a readable image', ['url' => $url]);

            return null;
        }

        // The hash makes the copy idempotent: the same source URL always lands
        // on the same file, so a repeated backfill does not pile up copies.
        $path = self::FOLDER . '/' . sha1($url) . '.' . $this->extension($type, $url);

        Storage::disk('public')->put($path, $bytes);

        return $this->baseUrl() . '/' . $path;
    }

    protected function extension(string $contentType, string $url): string
    {
        return match (true) {
            str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            str_contains($contentType, 'avif') => 'avif',
            default => strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg',
        };
    }
}
