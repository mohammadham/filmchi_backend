<?php

namespace Tests\Feature;

use App\Services\Relay\TelegramDriveClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * Path B (adopt) and path B″ (manifest + channel restore).
 *
 * The bot copies a document into the storage channel with Telegram's
 * copyMessage, so no byte reaches this server. These tests prove what the
 * client sends for each path and how a refusal is surfaced.
 */
class AdoptPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'media_relay.telegram.base_url' => 'http://tgdrive.test',
            'media_relay.telegram.api_key' => 'td_test_key',
            'media_relay.telegram.username' => 'admin',
            'media_relay.telegram.password' => 'secret',
        ]);
    }

    public function test_adopt_registers_the_stored_message(): void
    {
        $drive = new AdoptMockClient([
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'file_id' => 'f_abc123',
                'name' => 'movie.mkv',
                'size' => 2147483648,
                'status' => 'ready',
                'parts' => 1,
                'storage_chat' => '@store',
                'deduplicated' => true,
                'verified' => true,
            ])),
        ]);

        $result = $drive->adopt(42117, ['name' => 'movie.mkv', 'size' => 2147483648]);

        $this->assertSame('f_abc123', $result['file_id']);
        $this->assertTrue($result['deduplicated']);
        $this->assertTrue($result['verified']);

        $this->assertCount(1, $drive->history);
        $this->assertSame('POST', $drive->history[0]['method']);
        $this->assertStringContainsString('/api/v1/files/adopt', $drive->history[0]['path']);
        $this->assertSame(42117, $drive->sent[0]['message_id']);
        $this->assertSame('movie.mkv', $drive->sent[0]['name']);
        $this->assertSame('td_test_key', $drive->history[0]['api_key']);
    }

    public function test_adopt_surfaces_a_pinned_chat_refusal(): void
    {
        $drive = new AdoptMockClient([
            new Response(409, [], (string) json_encode(['detail' => 'storage_chat is pinned to the API key'])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pinned to the API key');

        $drive->adopt(42117, ['storage_chat' => '@somewhere-else']);
    }

    public function test_adopt_surfaces_a_size_mismatch(): void
    {
        $drive = new AdoptMockClient([
            new Response(400, [], (string) json_encode(['detail' => 'size mismatch: the stored message has 8 bytes, the request said 5'])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('size mismatch');

        $drive->adopt(42117, ['size' => 5]);
    }

    public function test_a_manifest_carries_pointers_only(): void
    {
        $drive = new AdoptMockClient([]);

        $manifest = $drive->channelManifest([
            ['message_id' => 42117, 'name' => 'movie.mkv', 'size' => 2048, 'mime' => 'video/x-matroska'],
        ], '@store');

        $this->assertSame('channel-backup', $manifest['kind']);
        $this->assertSame(1, $manifest['counts']['files']);
        $this->assertSame(2048, $manifest['counts']['bytes']);

        $file = $manifest['files'][0];
        $this->assertStringStartsWith('f_', $file['id']);
        $this->assertSame([42117], $file['message_ids']);
        $this->assertSame(42117, $file['file_parts'][0]['message_id']);
        $this->assertSame(2048, $file['file_parts'][0]['size']);
        $this->assertSame('ready', $file['status']);
        $this->assertSame('@store', $file['storage_chat']);
        $this->assertSame('bot', $file['source']);
    }

    public function test_a_manifest_entry_without_a_message_is_rejected(): void
    {
        $drive = new AdoptMockClient([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('positive message_id');

        $drive->channelManifest([['name' => 'no-pointer.mkv']], '@store');
    }

    public function test_restore_posts_with_the_panel_jwt_and_manifest_id(): void
    {
        $drive = new AdoptMockClient([
            new Response(200, [], (string) json_encode(['access_token' => 'jwt-777', 'expires_in' => 60])),
            new Response(200, [], (string) json_encode(['ok' => true, 'inserted' => 1, 'skipped' => 0, 'file_ids' => ['f_abc']])),
        ]);

        $result = $drive->restoreFromManifest(3, 99001);

        $this->assertSame(1, $result['inserted']);

        $this->assertCount(2, $drive->history);
        $this->assertStringContainsString('/api/v1/auth/login', $drive->history[0]['path']);
        $this->assertStringContainsString('/api/v1/channels/3/restore', $drive->history[1]['path']);
        $this->assertStringContainsString('message_id=99001', $drive->history[1]['path']);
        $this->assertSame('Bearer jwt-777', $drive->history[1]['authorization']);
    }
}

/**
 * The real client with its HTTP layer mocked: records method/path/headers and
 * the JSON body of every request.
 */
class AdoptMockClient extends TelegramDriveClient
{
    /** @var array<int, array{method:string,path:string,authorization:string,api_key:string}> */
    public array $history = [];

    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public function __construct(array $responses)
    {
        parent::__construct([
            'base_url' => 'http://tgdrive.test',
            'api_key' => 'td_test_key',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $stack = HandlerStack::create(new MockHandler($responses));

        $stack->push(Middleware::mapRequest(function ($request) {
            $body = (string) $request->getBody();
            $decoded = json_decode($body, true);

            $this->history[] = [
                'method' => $request->getMethod(),
                'path' => (string) $request->getUri(),
                'authorization' => $request->getHeaderLine('Authorization'),
                'api_key' => $request->getHeaderLine('X-API-Key'),
            ];

            if (is_array($decoded)) {
                $this->sent[] = $decoded;
            }

            return $request;
        }));

        $this->http = new HttpClient([
            'handler' => $stack,
            'base_uri' => 'http://tgdrive.test/',
            'http_errors' => false,
        ]);
    }
}
