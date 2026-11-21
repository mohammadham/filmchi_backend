<?php

namespace Tests\Feature;

use App\RelayEvent;
use App\RelayFile;
use App\RelayLink;
use App\Services\Bot\BotFileImporter;
use GuzzleHttp\Psr7\Response;
use App\MediaGallery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\DriveMock;
use Tests\TestCase;

/**
 * P2 of the bot plan: a document the user sent to the bot is copied into the
 * storage channel by Telegram, adopted by Telegram Drive (or restored from a
 * manifest) and registered as a cold relay file — once per file_unique_id.
 */
class BotFileImporterTest extends TestCase
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
            'media_relay.bot.token' => '123:abc',
            'media_relay.bot.storage_chat' => '@store',
            'media_relay.bot.channel_id' => 0,
            'media_relay.bot.copy_timeout' => 5,
        ]);

        $this->clean('bot-test-%');
    }

    protected function tearDown(): void
    {
        $this->clean('bot-test-%');

        parent::tearDown();
    }

    /**
     * The suite runs against a real sqlite file, so rows must be removed by hand.
     */
    protected function clean(string $pattern): void
    {
        $ids = RelayFile::where('tg_unique_id', 'like', $pattern)->pluck('id');

        if ($ids->isNotEmpty()) {
            RelayLink::whereIn('relay_file_id', $ids)->delete();

            if (Schema::hasTable('media_gallery')) {
                MediaGallery::whereIn('relay_file_id', $ids)->delete();
            }

            RelayEvent::whereIn('relay_file_id', $ids)->delete();
            RelayFile::whereIn('id', $ids)->delete();
        }
    }

    protected function adoptAnswer(array $overrides = []): Response
    {
        return new Response(200, [], (string) json_encode($overrides + [
            'ok' => true,
            'file_id' => 'f_bot1',
            'name' => 'movie.mkv',
            'size' => 2048,
            'status' => 'ready',
            'deduplicated' => false,
            'storage_chat' => '@store',
        ]));
    }

    protected function telegramOk(int $messageId): array
    {
        return ['ok' => true, 'result' => ['message_id' => $messageId]];
    }

    protected function document(array $overrides = []): array
    {
        return $overrides + [
            'message_id' => 77,
            'file_unique_id' => 'bot-test-u1',
            'name' => 'movie.mkv',
            'size' => 2048,
            'mime' => 'video/x-matroska',
        ];
    }

    public function test_a_bot_file_is_copied_adopted_and_registered(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response($this->telegramOk(5001))]);

        $drive = new DriveMock([$this->adoptAnswer()]);
        $result = (new BotFileImporter($drive))->import($this->document(), 111, 111);

        $file = $result['file'];

        $this->assertFalse($result['deduplicated']);
        $this->assertSame(5001, $result['copy_message_id']);

        $this->assertSame('bot', $file->origin);
        $this->assertSame('bot-test-u1', $file->tg_unique_id);
        $this->assertSame(111, (int) $file->bot_user_id);
        $this->assertSame('f_bot1', $file->tg_file_id);
        $this->assertSame(RelayFile::COLD, $file->cache_state);

        // adopt must be given the copy's id in the storage channel, never the
        // message id from the user's private chat.
        $this->assertCount(1, $drive->sent);
        $this->assertSame(5001, $drive->sent[0]['message_id']);
        $this->assertSame('@store', $drive->sent[0]['storage_chat']);
        $this->assertSame('bot:flixy:111', $drive->sent[0]['uploader']);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/copyMessage')
                && $request['chat_id'] === '@store'
                && (int) $request['from_chat_id'] === 111
                && (int) $request['message_id'] === 77;
        });
        Http::assertSentCount(1);

        $link = RelayLink::where('relay_file_id', $file->id)->first();
        $this->assertNotNull($link);
        $this->assertSame(RelayLink::MODE_STREAM, $link->mode);

        // the library entry is optional (the legacy media_gallery table is not
        // in every install), the event and the link are not
        $this->assertSame(1, RelayEvent::where('relay_file_id', $file->id)->count());
        $this->assertSame(1, RelayFile::where('tg_unique_id', 'bot-test-u1')->count());
    }

    public function test_the_same_file_is_never_copied_or_registered_twice(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response($this->telegramOk(5001))]);

        $drive = new DriveMock([$this->adoptAnswer()]);
        $importer = new BotFileImporter($drive);

        $first = $importer->import($this->document(), 111, 111);
        $second = $importer->import($this->document(['message_id' => 88]), 111, 111);

        $this->assertTrue($second['deduplicated']);
        $this->assertSame($first['file']->id, $second['file']->id);
        $this->assertSame($first['link']->id, $second['link']->id, 'the dedup path reuses the live link');

        // one copyMessage, one adopt, one row
        Http::assertSentCount(1);
        $this->assertCount(1, $drive->history);
        $this->assertSame(1, RelayFile::where('tg_unique_id', 'bot-test-u1')->count());
    }

    public function test_a_protected_message_is_refused_before_anything_is_stored(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => false,
            'description' => 'Bad Request: message is protected',
        ], 400)]);

        $drive = new DriveMock([]);

        try {
            (new BotFileImporter($drive))->import($this->document(), 111, 111);
            $this->fail('a protected message must not be imported');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('محافظت‌شده', $e->getMessage());
            $this->assertStringNotContainsString('123:abc', $e->getMessage(), 'the token never reaches the message');
        }

        $this->assertCount(0, $drive->history, 'nothing reaches Telegram Drive');
        $this->assertSame(0, RelayFile::where('tg_unique_id', 'bot-test-u1')->count());
    }

    public function test_an_adopt_refusal_falls_back_to_the_manifest_path(): void
    {
        config(['media_relay.bot.channel_id' => 3]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'copyMessage')) {
                return Http::response($this->telegramOk(5001));
            }

            return Http::response($this->telegramOk(99001));
        });

        $drive = new DriveMock([
            new Response(404, [], (string) json_encode(['detail' => 'not found'])),
            new Response(200, [], (string) json_encode(['access_token' => 'jwt-1', 'expires_in' => 60])),
            new Response(200, [], (string) json_encode(['ok' => true, 'inserted' => 1, 'skipped' => 0, 'file_ids' => ['f_x']])),
        ]);

        $result = (new BotFileImporter($drive))->import($this->document(), 111, 111);

        // the bytes are already in the channel: nothing is left to ingest
        $this->assertSame('completed', $result['file']->ingest_status);
        $this->assertStringStartsWith('f_', $result['file']->tg_file_id);

        Http::assertSent(function ($request) {
            // the manifest is a pointer document, not the media itself
            return str_contains($request->url(), 'sendDocument')
                && str_contains((string) $request->body(), '"channel-backup"')
                && str_contains((string) $request->body(), '"message_ids"');
        });

        $paths = array_column($drive->history, 'path');
        $this->assertStringContainsString('/api/v1/files/adopt', $paths[0]);
        $this->assertStringContainsString('/api/v1/channels/3/restore', $paths[2]);
    }

    public function test_a_missing_storage_channel_is_reported_in_persian(): void
    {
        config(['media_relay.bot.storage_chat' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('relay_bot_storage_chat');

        (new BotFileImporter(new DriveMock([])))->import($this->document(), 111, 111);
    }
}

