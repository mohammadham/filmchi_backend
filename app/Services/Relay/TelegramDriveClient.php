<?php

namespace App\Services\Relay;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Client for the TelegramDrive service (FastAPI) — the primary / cold tier.
 *
 * Supports either a static API key (X-API-Key) or panel credentials, in which
 * case the JWT is cached until shortly before it expires.
 */
class TelegramDriveClient
{
    protected $config;

    /** @var HttpClient|null */
    protected $http;

    public function __construct(array $config = null)
    {
        $this->config = $config ?: (array) config('media_relay.telegram', []);
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['base_url']);
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    protected function http(): HttpClient
    {
        if ($this->http === null) {
            $this->http = new HttpClient([
                'base_uri' => $this->baseUrl() . '/',
                'timeout' => (int) ($this->config['timeout'] ?? 300),
                'connect_timeout' => (int) ($this->config['connect_timeout'] ?? 15),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        }

        return $this->http;
    }

    /* ------------------------------------------------------------------ auth */

    protected function hasApiKey(): bool
    {
        return trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    protected function tokenCacheKey(): string
    {
        return 'relay:tgdrive:token:' . md5($this->baseUrl() . '|' . ($this->config['username'] ?? ''));
    }

    /**
     * Return the current bearer token, logging in when the cache is cold.
     */
    public function token(bool $force = false): ?string
    {
        if ($this->hasApiKey()) {
            return null;
        }

        $key = $this->tokenCacheKey();

        if (!$force) {
            $cached = Cache::get($key);
            if (!empty($cached)) {
                return (string) $cached;
            }
        }

        return $this->login();
    }

    public function login(): string
    {
        $response = $this->http()->post('api/v1/auth/login', [
            'json' => [
                'username' => (string) ($this->config['username'] ?? ''),
                'password' => (string) ($this->config['password'] ?? ''),
            ],
        ]);

        $data = $this->decode($response, 'login');
        $token = $data['access_token'] ?? null;

        if (empty($token)) {
            throw new RuntimeException('Telegram Drive login did not return an access token.');
        }

        // ``expires_in`` is reported in minutes; refresh two minutes early.
        $ttl = max(60, ((int) ($data['expires_in'] ?? 60)) * 60 - 120);
        Cache::put($this->tokenCacheKey(), $token, $ttl);

        if (!empty($data['refresh_token'])) {
            Cache::put($this->tokenCacheKey() . ':refresh', $data['refresh_token'], $ttl * 2);
        }

        return (string) $token;
    }

    protected function authHeaders(): array
    {
        if ($this->hasApiKey()) {
            $key = (string) $this->config['api_key'];

            $headers = [
                'Accept' => 'application/json',
                'X-API-Key' => $key,
            ];

            // Panel-issued keys (td_…) are also valid as bearer tokens, and
            // the read endpoints only accept that form, so send both.
            if (str_starts_with($key, 'td_')) {
                $headers['Authorization'] = 'Bearer ' . $key;
            }

            return $headers;
        }

        $token = $this->token();

        return $token
            ? ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token]
            : ['Accept' => 'application/json'];
    }

    /**
     * Perform a request, re-authenticating once when the token has expired.
     */
    public function request(string $method, string $path, array $options = [], bool $retry = true)
    {
        $options['headers'] = array_merge($this->authHeaders(), $options['headers'] ?? []);
        $response = $this->http()->request($method, ltrim($path, '/'), $options);

        if ($retry && $response->getStatusCode() === 401 && !$this->hasApiKey()) {
            $this->token(true);

            // Drop the stale credentials BEFORE merging the fresh ones, or
            // the attempt-1 Authorization header (last in array_merge) would
            // override the renewed token and the retry would 401 again.
            unset($options['headers']['Authorization'], $options['headers']['X-API-Key']);
            $options['headers'] = array_merge($options['headers'], $this->authHeaders());

            $response = $this->http()->request($method, ltrim($path, '/'), $options);
        }

        return $response;
    }

    protected function decode($response, string $context): array
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if ($response->getStatusCode() >= 400) {
            $message = is_array($data)
                ? ($data['detail'] ?? $data['message'] ?? $body)
                : $body;

            throw new RuntimeException(sprintf(
                'Telegram Drive %s failed (%d): %s',
                $context,
                $response->getStatusCode(),
                is_array($message) ? json_encode($message) : (string) $message
            ));
        }

        return is_array($data) ? $data : [];
    }

    /* ----------------------------------------------------------------- files */

    public function listFiles(int $limit = 50, int $offset = 0): array
    {
        $response = $this->request('GET', 'api/v1/files', [
            'query' => ['limit' => $limit, 'offset' => $offset],
        ]);

        return $this->decode($response, 'list files');
    }

    public function fileInfo(string $fileId): array
    {
        $response = $this->request('GET', 'api/v1/files/' . rawurlencode($fileId));

        return $this->decode($response, 'file info');
    }

    /**
     * Upload a local file (an upload buffer, not a persistent copy) and return
     * the created record. Nothing is written to this server's storage.
     *
     * $folder is a virtual folder path inside the storage chat; the service
     * creates parents as needed, so "Anime/Season 1" works on the first call.
     */
    public function uploadFromPath(string $path, string $name, string $mime = null, string $storageChat = null, string $folder = null): array
    {
        $startedAt = now();
        $startedClock = microtime(true);
        $uploadBytes = is_file($path) ? (int) filesize($path) : 0;
        $attempt = 0;

        while (true) {
            // A 401 retry needs a fresh handle: the previous stream was already
            // consumed by the failed attempt.
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Unable to read the uploaded file.');
            }

            $headers = [];

            if ($storageChat !== null && $storageChat !== '') {
                $headers['X-Storage-Chat'] = $storageChat;
            }

            // Telegram Drive resolves (and creates) a nested virtual folder
            // from this path, e.g. "Under-the-Gate/S01".
            if ($folder !== null && $folder !== '') {
                $headers['X-Folder'] = $folder;
            }

            try {
                $response = $this->request('POST', 'api/v1/files/upload', [
                    'headers' => $headers,
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => $handle,
                            'filename' => $name,
                            'headers' => ['Content-Type' => $mime ?: 'application/octet-stream'],
                        ],
                    ],
                ], false);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            if ($response->getStatusCode() === 401 && $attempt === 0 && !$this->hasApiKey()) {
                // The cached token went stale mid-flight; mint a fresh one and retry once.
                $this->token(true);
                $attempt = 1;

                continue;
            }

            break;
        }

        $result = $this->decode($response, 'upload');

        // The queued job uploads (and splits) asynchronously; multi-GB files
        // take minutes. Wait for "ready" so callers never presign too early.
        $fileId = $result['file_id'] ?? ($result['id'] ?? null);

        if (!empty($fileId)) {
            $ready = $this->waitForReady((string) $fileId);
            $result['sha256'] = $ready['sha256'] ?? null;
            $result['parts'] = $ready['parts'] ?? null;
        }

        $result['_metrics'] = [
            'bytes' => $uploadBytes,
            'duration_ms' => max(1, (int) round((microtime(true) - $startedClock) * 1000)),
            'started_at' => $startedAt,
            'finished_at' => now(),
        ];

        return $result;
    }

    /**
     * Wait until a freshly uploaded file is fully stored on Telegram.
     *
     * The upload request returns with the job queued; for multi-gigabyte
     * files the queue then uploads (and splits) for minutes. A presign or
     * stream before "ready" answers 404, so callers of uploadFromPath get
     * the file record polled here instead of guessing.
     */
    public function waitForReady(string $fileId): array
    {
        $timeout = (int) ($this->config['ready_timeout'] ?? 900);
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            try {
                $response = $this->request('GET', 'api/v1/files/' . rawurlencode($fileId));
                $data = $this->decode($response, 'file');
                $status = (string) ($data['status'] ?? '');

                if ($status === 'ready') {
                    return $data;
                }

                if (str_contains($status, 'fail') || str_contains($status, 'error')) {
                    throw new RuntimeException('Telegram Drive failed to store the file: ' . (string) ($data['error'] ?? $status));
                }
            } catch (RuntimeException $e) {
                // 404 right after the queued response is normal; keep polling.
                if (stripos($e->getMessage(), 'not found') === false
                    && stripos($e->getMessage(), 'failed to store') !== false) {
                    throw $e;
                }
            }

            sleep(3);
        }

        throw new RuntimeException('Telegram Drive did not finish storing the file within ' . $timeout . 's.');
    }

    /**
     * Mint a short-lived signed download URL (public, no auth header needed).
     */
    public function presignedLink(string $fileId, int $ttl = null, bool $oneTime = false): string
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->request('POST', 'api/v1/files/' . rawurlencode($fileId) . '/link', [
                    'json' => [
                        'ttl' => $ttl ?: (int) ($this->config['link_ttl'] ?? 3600),
                        'one_time' => $oneTime,
                    ],
                ]);

                $data = $this->decode($response, 'presign');

                if (empty($data['url'])) {
                    throw new RuntimeException('Telegram Drive did not return a download link.');
                }

                return $this->absoluteUrl((string) $data['url']);
            } catch (RuntimeException $e) {
                // Telegram Drive finalises uploads asynchronously; a freshly
                // uploaded file briefly answers 404 "not found or not ready".
                // Retry briefly so a visitor (or the promote job) that hits
                // the file seconds after upload is not turned away.
                $notReady = stripos($e->getMessage(), 'not ready') !== false
                    || stripos($e->getMessage(), 'not found') !== false;

                if (!$notReady || $attempt >= 3) {
                    throw $e;
                }

                sleep(2 * $attempt);
            }
        }
    }

    public function shareLink(string $fileId, string $slug = ''): array
    {
        $response = $this->request('POST', 'api/v1/files/' . rawurlencode($fileId) . '/share', [
            'json' => ['slug' => $slug],
        ]);

        return $this->decode($response, 'share');
    }

    public function deleteFile(string $fileId, bool $purge = false): array
    {
        $response = $this->request('DELETE', 'api/v1/files/' . rawurlencode($fileId), [
            'query' => ['purge' => $purge ? 'true' : 'false'],
        ]);

        return $this->decode($response, 'delete');
    }

    /**
     * The stored copy can also be fetched with the API credentials directly.
     */
    public function contentUrl(string $fileId): string
    {
        return $this->baseUrl() . '/api/v1/files/' . rawurlencode($fileId) . '/content';
    }

    public function authHeadersForStream(): array
    {
        $headers = $this->authHeaders();
        unset($headers['Accept']);

        return $headers;
    }

    public function health(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'TGDRIVE_BASE_URL is not configured.'];
        }

        try {
            // A panel-issued key (td_…) authenticates the file API as a bearer
            // token but is not a panel session, so /auth/me answers 401 for it.
            // Probing /auth/me with a key therefore reported a perfectly working
            // service as offline; probe an endpoint that accepts the credential
            // that is actually configured.
            if ($this->hasApiKey()) {
                $response = $this->request('GET', 'api/v1/files', ['query' => ['limit' => 1]]);
                $status = $response->getStatusCode();

                return [
                    'ok' => $status < 400,
                    'message' => $status < 400 ? 'reachable (api key)' : 'HTTP ' . $status,
                ];
            }

            $response = $this->request('GET', 'api/v1/auth/me');
            $status = $response->getStatusCode();

            return [
                'ok' => $status < 400,
                'message' => $status < 400 ? 'reachable' : 'HTTP ' . $status,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function absoluteUrl(string $url): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        return $this->baseUrl() . '/' . ltrim($url, '/');
    }
}
