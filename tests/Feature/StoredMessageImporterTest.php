<?php

namespace Tests\Feature;

use App\Exceptions\PermanentImportFailure;
use App\Services\Bot\BotManifestSender;
use App\Services\Relay\StoredMessageImporter;
use App\Services\Relay\TelegramDriveRefusal;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\DriveMock;
use Tests\TestCase;

/**
 * One entry point for a message that already sits in the storage channel:
 * adopt (B) first, and the manifest + restore backup (B″) when adopt says it
 * cannot work here. The mocked HTTP layer pins exactly which calls each path
 * makes, so a silent fallback or a swallowed refusal shows up as a failure.
 */
class StoredMessageImporterTest extends TestCase
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

    protected function message(): array
    {
        return [
            'message_id' => 42117,
            'name' => 'movie.mkv',
            'size' => 2048,
            'mime' => 'video/x-matroska',
            'storage_chat' => '@store',
            'uploader' => 'bot:flixy:42',
        ];
    }

    protected function adoptOk(): Response
    {
        return new Response(200, [], (string) json_encode([
            'ok' => true,
            'file_id' => 'f_adopted',
            'name' => 'movie.mkv',
            'size' => 2048,
            'status' => 'ready',
            'parts' => 1,
            'storage_chat' => '@store',
            'deduplicated' => false,
        ]));
    }

    public function test_the_adopt_path_is_used_when_it_works(): void
    {
        $drive = new DriveMock([$this->adoptOk()]);
        $senderCalls = 0;

        $result = (new StoredMessageImporter($drive))->import(
            $this->message(),
            7,
            function (string $json) use (&$senderCalls): int {
                $senderCalls++;

                return 555;
            }
        );

        $this->assertSame('adopt', $result['path']);
        $this->assertSame('f_adopted', $result['file_id']);
        $this->assertCount(1, $drive->history);
        $this->assertSame(0, $senderCalls, 'a working adopt must not send a manifest');
        $this->assertStringContainsString('/api/v1/files/adopt', $drive->history[0]['path']);
    }

    public function test_the_manifest_backup_runs_for_the_documented_refusals(): void
    {
        foreach ([404, 503, 509] as $status) {
            Cache::flush();

            $drive = new DriveMock([
                new Response($status, [], (string) json_encode(['detail' => 'adopt unavailable'])),
                new Response(200, [], (string) json_encode(['access_token' => 'jwt-9', 'expires_in' => 60])),
                new Response(200, [], (string) json_encode(['ok' => true, 'inserted' => 1, 'skipped' => 0, 'file_ids' => ['f_restored'], 'missing_parts' => 0])),
            ]);

            $manifestJson = null;

            $result = (new StoredMessageImporter($drive))->import(
                $this->message(),
                7,
                function (string $json) use (&$manifestJson): int {
                    $manifestJson = $json;

                    return 555;
                }
            );

            $this->assertSame('manifest', $result['path'], "HTTP {$status} must fall back");
            $this->assertSame($status, $result['adopt_refusal']['status']);
            $this->assertSame(555, $result['manifest_message_id']);
            $this->assertCount(3, $drive->history, 'adopt → login → restore, with nothing to poll');
            $this->assertStringContainsString('/api/v1/auth/login', $drive->history[1]['path']);
            $this->assertStringContainsString('/api/v1/channels/7/restore', $drive->history[2]['path']);
            $this->assertStringContainsString('message_id=555', $drive->history[2]['path']);

            $manifest = json_decode((string) $manifestJson, true);

            $this->assertIsArray($manifest);
            $this->assertSame('channel-backup', $manifest['kind']);
            $this->assertSame('@store', $manifest['channel']['chat']);
            $this->assertSame([42117], $manifest['files'][0]['message_ids']);
            $this->assertSame(42117, $manifest['files'][0]['file_parts'][0]['message_id']);
            $this->assertSame(2048, $manifest['files'][0]['file_parts'][0]['size']);
            $this->assertNotNull($manifest['files'][0]['ready_at'], 'a restored row must carry ready_at');
            $this->assertStringStartsWith('f_', (string) $manifest['files'][0]['id']);
        }
    }

    public function test_a_pinned_chat_refusal_is_not_swallowed(): void
    {
        $drive = new DriveMock([
            new Response(409, [], (string) json_encode(['detail' => 'storage_chat is pinned to the API key'])),
        ]);
        $senderCalls = 0;

        try {
            (new StoredMessageImporter($drive))->import(
                $this->message(),
                7,
                function (string $json) use (&$senderCalls): int {
                    $senderCalls++;

                    return 555;
                }
            );

            $this->fail('a 409 must not fall back to the manifest');
        } catch (TelegramDriveRefusal $e) {
            $this->assertSame(409, $e->status());
            $this->assertStringContainsString('pinned to the API key', $e->getMessage());
        }

        $this->assertSame(0, $senderCalls);
        $this->assertCount(1, $drive->history);
    }

    public function test_a_fallback_without_a_registered_channel_keeps_the_adopt_refusal(): void
    {
        $drive = new DriveMock([
            new Response(404, [], (string) json_encode(['detail' => 'message not found in the storage chat'])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest fallback needs a registered channel');

        (new StoredMessageImporter($drive))->import($this->message(), null, null);
    }

    public function test_a_fallback_without_a_size_is_refused(): void
    {
        $drive = new DriveMock([
            new Response(404, [], (string) json_encode(['detail' => 'message not found in the storage chat'])),
        ]);

        $message = $this->message();
        $message['size'] = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the stored document size');

        (new StoredMessageImporter($drive))->import($message, 7, function (string $json): int {
            return 555;
        });
    }

    public function test_a_restore_that_lost_a_part_is_never_a_ready_file(): void
    {
        $drive = new DriveMock([
            new Response(404, [], (string) json_encode(['detail' => 'message not found in the storage chat'])),
            new Response(200, [], (string) json_encode(['access_token' => 'jwt-9', 'expires_in' => 60])),
            // The row is inserted, but one part could not be added: exactly what
            // the removed poll used to catch.
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'inserted' => 1,
                'skipped' => 0,
                'file_ids' => ['f_partial'],
                'missing_parts' => 2,
            ])),
        ]);

        try {
            (new StoredMessageImporter($drive))->import(
                $this->message(),
                7,
                function (string $json): int {
                    return 555;
                }
            );

            $this->fail('an incomplete restore must fail the import');
        } catch (PermanentImportFailure $e) {
            // The type is the contract: the job must not retry this one.
            $this->assertInstanceOf(PermanentImportFailure::class, $e);
            $this->assertStringContainsString('f_partial', $e->getMessage());
            $this->assertStringContainsString('lost 2 file part(s)', $e->getMessage());
            $this->assertStringContainsString('must not be registered as ready', $e->getMessage());
        }

        $this->assertCount(3, $drive->history, 'adopt → login → restore, then the guard stops here');
    }

    public function test_the_bot_sends_the_manifest_and_restore_completes_the_import(): void
    {
        // The real sender (not a closure) talks to api.telegram.org while the
        // drive client keeps its mocked transport: this pins the whole hand-off.
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 555]], 200),
        ]);

        $drive = new DriveMock([
            new Response(404, [], (string) json_encode(['detail' => 'message not found in the storage chat'])),
            new Response(200, [], (string) json_encode(['access_token' => 'jwt-9', 'expires_in' => 60])),
            new Response(200, [], (string) json_encode(['ok' => true, 'inserted' => 1, 'skipped' => 0, 'file_ids' => ['f_x'], 'missing_parts' => 0])),
        ]);

        $result = (new StoredMessageImporter($drive))->import(
            $this->message(),
            7,
            new BotManifestSender('123:abc', '@store')
        );

        $this->assertSame('manifest', $result['path']);
        $this->assertSame(555, $result['manifest_message_id']);
        $this->assertCount(3, $drive->history, 'adopt → login → restore, with nothing to poll');
        $this->assertStringContainsString('/api/v1/channels/7/restore', $drive->history[2]['path']);
        $this->assertStringContainsString('message_id=555', $drive->history[2]['path']);
        Http::assertSentCount(1);
    }
}
