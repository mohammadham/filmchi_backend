<?php

namespace App\Services\Relay;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Client for the 9drive gateway (Express + Google Drive) — the cache tier.
 *
 * Uploads use an API key (POST /api/v1/uploads, scope files:upload). Creating
 * the public share used for serving cached bytes needs a panel session, so
 * email/password credentials are optional but recommended.
 */
class NineDriveClient
{
    protected $config;

    /** @var HttpClient|null */
    protected $http;

    public function __construct(array $config = null)
    {
        $this->config = $config ?: (array) config('media_relay.ninedrive', []);
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['base_url']) && !empty($this->config['api_key']);
    }

    /**
     * Reading cached bytes only needs the gateway to be reachable; the API key
     * is required for uploads, not for serving an existing share link.
     */
    public function hasBaseUrl(): bool
    {
        return !empty($this->config['base_url']);
    }

    public function hasSession(): bool
    {
        return !empty($this->config['email']) && !empty($this->config['password']);
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    public function frontendUrl(): string
    {
        return rtrim((string) ($this->config['frontend_url'] ?? ''), '/') ?: $this->baseUrl();
    }

    protected function http(): HttpClient
    {
        if ($this->http === null) {
            $this->http = new HttpClient([
                'base_uri' => $this->baseUrl() . '/',
                'timeout' => (int) ($this->config['timeout'] ?? 600),
                'connect_timeout' => (int) ($this->config['connect_timeout'] ?? 15),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        }

        return $this->http;
    }

    /* ------------------------------------------------------------------ auth */

    protected function sessionCacheKey(): string
    {
        return 'relay:ninedrive:token:' . md5($this->baseUrl() . '|' . ($this->config['email'] ?? ''));
    }

    public function sessionToken(bool $force = false): ?string
    {
        if (!$this->hasSession()) {
            return null;
        }

        $key = $this->sessionCacheKey();

        if (!$force) {
            $cached = Cache::get($key);
            if (!empty($cached)) {
                return (string) $cached;
            }
        }

        $response = $this->http()->post('auth/login', [
            'json' => [
                'email' => (string) $this->config['email'],
                'password' => (string) $this->config['password'],
            ],
        ]);

        $data = $this->decode($response, 'login');
        $token = $data['accessToken'] ?? null;

        if (empty($token)) {
            throw new RuntimeException('9drive login did not return an access token.');
        }

        // Access tokens live 15 minutes by default.
        Cache::put($key, $token, 600);

        if (!empty($data['refreshToken'])) {
            Cache::put($key . ':refresh', $data['refreshToken'], 60 * 60 * 24 * 14);
        }

        return (string) $token;
    }

    protected function apiKeyHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . (string) $this->config['api_key'],
        ];
    }

    protected function sessionHeaders(): array
    {
        $token = $this->sessionToken();

        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    protected function decode($response, string $context): array
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if ($response->getStatusCode() >= 400) {
            $message = is_array($data)
                ? ($data['message'] ?? $data['code'] ?? $body)
                : $body;

            throw new RuntimeException(sprintf(
                '9drive %s failed (%d): %s',
                $context,
                $response->getStatusCode(),
                is_array($message) ? json_encode($message) : (string) $message
            ));
        }

        return is_array($data) ? $data : [];
    }

    /* ---------------------------------------------------------------- upload */

    /**
     * Build the multipart payload for an upload.
     *
     * 9drive's busboy handler reads the metadata fields while it parses, so
     * sizeBytes/fileName/mimeType must be serialised *before* the file part.
     *
     * @param  resource|\Psr\Http\Message\StreamInterface  $stream
     */
    public function buildUploadMultipart($stream, int $size, string $name, string $mime, string $folderId = null): array
    {
        $mime = $mime ?: 'application/octet-stream';

        $multipart = [
            ['name' => 'sizeBytes', 'contents' => (string) $size],
            ['name' => 'fileName', 'contents' => $name],
            ['name' => 'mimeType', 'contents' => $mime],
        ];

        $target = $folderId ?: (string) ($this->config['folder_id'] ?? '');
        if ($target !== '') {
            $multipart[] = ['name' => 'folderId', 'contents' => $target];
        }

        $multipart[] = [
            'name' => 'file',
            'contents' => is_resource($stream) ? new SizedStream($stream, $size) : $stream,
            'filename' => $name,
            'headers' => ['Content-Type' => $mime],
        ];

        return $multipart;
    }

    /**
     * Stream an already-open stream straight into 9drive (no local copy).
     *
     * @param  resource|\Psr\Http\Message\StreamInterface  $stream  readable stream of the file bytes
     */
    public function uploadStream($stream, int $size, string $name, string $mime, string $folderId = null): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('9drive is not configured (NINEDRIVE_BASE_URL / NINEDRIVE_API_KEY).');
        }

        $response = $this->http()->post('api/v1/uploads', [
            'headers' => $this->apiKeyHeaders(),
            'multipart' => $this->buildUploadMultipart($stream, $size, $name, $mime, $folderId),
        ]);

        $data = $this->decode($response, 'upload');
        $file = $data['file'] ?? null;

        if (!$file || empty($file['id'])) {
            $failure = $data['failed'][0]['message'] ?? 'unknown error';

            throw new RuntimeException('9drive did not accept the upload: ' . $failure);
        }

        return $file;
    }

    /**
     * Ask 9drive for a long-lived public share token for a stored file.
     */
    public function createShare(string $fileId): array
    {
        if (!$this->hasSession()) {
            throw new RuntimeException('9drive panel credentials are required to create share links.');
        }

        $response = $this->http()->post('files/' . rawurlencode($fileId) . '/share', [
            'headers' => $this->sessionHeaders(),
        ]);

        $data = $this->decode($response, 'share');
        $token = $this->extractShareToken((string) ($data['url'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('9drive did not return a usable share token.');
        }

        return ['token' => $token, 'url' => (string) ($data['url'] ?? ''), 'share_id' => $data['shareId'] ?? null];
    }

    protected function extractShareToken(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = explode('/', rtrim($url, '/'));

        return (string) end($parts);
    }

    /* ---------------------------------------------------------------- serving */

    public function previewUrl(string $shareToken): string
    {
        return $this->baseUrl() . '/public/files/' . rawurlencode($shareToken) . '/preview';
    }

    public function downloadUrl(string $shareToken): string
    {
        return $this->baseUrl() . '/public/files/' . rawurlencode($shareToken) . '/download';
    }

    public function sharePageUrl(string $shareToken): string
    {
        return $this->frontendUrl() . '/public/files/' . rawurlencode($shareToken);
    }

    /**
     * Last-resort fallback: stream from the public Google Drive link.
     */
    public function driveUrl(string $providerFileId): string
    {
        return 'https://drive.google.com/uc?export=download&id=' . rawurlencode($providerFileId);
    }

    public function deleteFile(string $fileId): array
    {
        $response = $this->http()->delete('files/' . rawurlencode($fileId), [
            'headers' => $this->hasSession() ? $this->sessionHeaders() : $this->apiKeyHeaders(),
        ]);

        return $this->decode($response, 'delete');
    }

    public function health(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'NINEDRIVE_BASE_URL / NINEDRIVE_API_KEY is not configured.'];
        }

        try {
            $response = $this->http()->get('health');

            return [
                'ok' => $response->getStatusCode() < 400,
                'message' => 'HTTP ' . $response->getStatusCode(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
