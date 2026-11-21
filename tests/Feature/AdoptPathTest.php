<?php

namespace Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\DriveMock;
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
        $drive = new DriveMock([
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'file_id' => 'f_abc123',
                'name' => 'movie.mkv',
                'size' => 2147483648,
                'status' => 'ready',
                'parts' => 1,
                'storage_chat' => '@store',
                'deduplicated' => true,
            ])),
        ]);

        $result = $drive->adopt(42117, ['name' => 'movie.mkv', 'size' => 2147483648]);

        $this->assertSame('f_abc123', $result['file_id']);
        $this->assertTrue($result['deduplicated']);

        $this->assertCount(1, $drive->history);
        $this->assertSame('POST', $drive->history[0]['method']);
        $this->assertStringContainsString('/api/v1/files/adopt', $drive->history[0]['path']);
        $this->assertSame(42117, $drive->sent[0]['message_id']);
        $this->assertSame('movie.mkv', $drive->sent[0]['name']);
        $this->assertSame('td_test_key', $drive->history[0]['api_key']);
    }

    public function test_adopt_surfaces_a_pinned_chat_refusal(): void
    {
        $drive = new DriveMock([
            new Response(409, [], (string) json_encode(['detail' => 'storage_chat is pinned to the API key'])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pinned to the API key');

        $drive->adopt(42117, ['storage_chat' => '@somewhere-else']);
    }

    public function test_adopt_surfaces_a_size_mismatch(): void
    {
        $drive = new DriveMock([
            new Response(400, [], (string) json_encode(['detail' => 'size mismatch: the stored message has 8 bytes, the request said 5'])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('size mismatch');

        $drive->adopt(42117, ['size' => 5]);
    }

    public function test_a_deduplicated_answer_returns_without_waiting_for_ready(): void
    {
        // The only mocked answer is the adopt itself: a wait would ask for
        // /files/{id} and run out of responses (then burn ready_timeout live).
        $drive = new DriveMock([
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'file_id' => 'f_existing',
                'name' => 'movie.mkv',
                'size' => 2048,
                'status' => 'queued',
                'deduplicated' => true,
            ])),
        ]);

        $result = $drive->adopt(42117);

        $this->assertSame('f_existing', $result['file_id']);
        $this->assertSame('queued', $result['status'], 'a dedup answer is handed back as-is');
        $this->assertCount(1, $drive->history, 'no poll after a dedup answer');
    }

    public function test_a_fresh_queued_adopt_still_waits_for_ready(): void
    {
        $drive = new DriveMock([
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'file_id' => 'f_new',
                'status' => 'queued',
                'deduplicated' => false,
            ])),
            new Response(200, [], (string) json_encode(['id' => 'f_new', 'status' => 'ready'])),
        ]);

        $result = $drive->adopt(42117);

        $this->assertSame('ready', $result['status']);
        $this->assertCount(2, $drive->history);
        $this->assertStringContainsString('/api/v1/files/f_new', $drive->history[1]['path']);
    }

    public function test_restore_reuses_the_cached_panel_token(): void
    {
        $restored = static fn () => new Response(200, [], (string) json_encode([
            'ok' => true, 'inserted' => 1, 'skipped' => 0, 'file_ids' => ['f_x'],
        ]));

        $drive = new DriveMock([
            new Response(200, [], (string) json_encode(['access_token' => 'jwt-1', 'expires_in' => 60])),
            $restored(),
            $restored(),
        ]);

        $drive->restoreFromManifest(3, 99001);
        $drive->restoreFromManifest(3, 99002);

        $paths = array_column($drive->history, 'path');
        $logins = array_values(array_filter($paths, static fn (string $p): bool => str_contains($p, '/auth/login')));

        $this->assertCount(1, $logins, 'the second restore must use the cached token');
        $this->assertCount(3, $paths);
        $this->assertSame('Bearer jwt-1', $drive->history[2]['authorization']);
        $this->assertStringContainsString('message_id=99002', $paths[2]);
    }

    public function test_restore_without_panel_credentials_explains_itself(): void
    {
        $drive = new DriveMock([], ['password' => '']);

        try {
            $drive->restoreFromManifest(3, 99001);
            $this->fail('a restore without panel credentials must not reach the service');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('اعتبارنامهٔ پنل', $e->getMessage());
            $this->assertStringContainsString('TGDRIVE_PASSWORD', $e->getMessage());
        }

        $this->assertCount(0, $drive->history, 'no request is made without credentials');
    }

    public function test_a_manifest_carries_pointers_only(): void
    {
        $drive = new DriveMock([]);

        $manifest = $drive->channelManifest([
            ['message_id' => 42117, 'name' => 'movie.mkv', 'size' => 2048, 'mime' => 'video/x-matroska'],
        ], '@store');

        $this->assertSame('channel-backup', $manifest['kind']);
        $this->assertSame('@store', $manifest['channel']['chat']);

        $file = $manifest['files'][0];
        $this->assertStringStartsWith('f_', $file['id']);
        $this->assertSame([42117], $file['message_ids']);
        $this->assertSame(42117, $file['file_parts'][0]['message_id']);
        $this->assertSame(2048, $file['file_parts'][0]['size']);
        $this->assertSame('ready', $file['status']);
        $this->assertSame('@store', $file['storage_chat']);
        $this->assertSame('bot', $file['source']);
        // restore writes these straight into the row; without them a restored
        // file is ready but has no created_at/ready_at
        $this->assertIsInt($file['created_at']);
        $this->assertIsInt($file['ready_at']);
        $this->assertGreaterThan(0, $file['ready_at']);
    }

    public function test_a_manifest_normalizes_the_channel_like_adopt(): void
    {
        $drive = new DriveMock([]);

        $manifest = $drive->channelManifest([
            ['message_id' => 42117, 'name' => 'movie.mkv', 'size' => 2048, 'storage_chat' => 'store'],
        ], 'store');

        // a restored row must carry the spelling adopt stores, or the panel
        // lists the same channel twice
        $this->assertSame('@store', $manifest['channel']['chat']);
        $this->assertSame('@store', $manifest['files'][0]['storage_chat']);

        $numeric = $drive->channelManifest([['message_id' => 42118, 'size' => 10]], '-1001234567890');

        $this->assertSame('-1001234567890', $numeric['channel']['chat']);
        $this->assertSame('-1001234567890', $numeric['files'][0]['storage_chat']);
    }

    public function test_a_manifest_entry_without_a_message_is_rejected(): void
    {
        $drive = new DriveMock([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('positive message_id');

        $drive->channelManifest([['name' => 'no-pointer.mkv']], '@store');
    }

    public function test_restore_posts_with_the_panel_jwt_and_manifest_id(): void
    {
        $drive = new DriveMock([
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
