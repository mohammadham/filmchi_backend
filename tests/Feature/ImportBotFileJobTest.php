<?php

namespace Tests\Feature;

use App\Jobs\ImportBotFileJob;
use App\RelayFile;
use App\RelayLink;
use App\Services\Bot\BotFileImporter;
use App\Services\Bot\TelegramBotClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\DriveMock;
use Tests\TestCase;

/**
 * P2 of the bot plan, end to end on a fake Telegram Drive backend: the job
 * reads the stored update, copies the document into the storage channel,
 * imports it, writes the submission and answers the user.
 */
class ImportBotFileJobTest extends TestCase
{
    /** @var array<int, int> */
    protected array $updateIds = [];

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
        ]);

        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    protected function clean(): void
    {
        if ($this->updateIds) {
            DB::table('bot_updates')->whereIn('update_id', $this->updateIds)->delete();
        }

        DB::table('bot_submissions')->where('file_unique_id', 'like', 'bot-test-%')->delete();

        $ids = RelayFile::where('tg_unique_id', 'like', 'bot-test-%')->pluck('id');

        if ($ids->isNotEmpty()) {
            RelayLink::whereIn('relay_file_id', $ids)->delete();
            RelayFile::whereIn('id', $ids)->delete();
        }

        $this->updateIds = [];
    }

    /**
     * copyMessage answers 5001; every sendMessage answers 7001.
     */
    protected function fakeTelegram(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'copyMessage')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 5001]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 7001]]);
        });
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

    protected function queueUpdate(array $message, int $updateId): int
    {
        DB::table('bot_updates')->insert([
            'update_id' => $updateId,
            'kind' => 'message',
            'payload' => json_encode([
                'update_id' => $updateId,
                'message' => $message + [
                    'from' => ['id' => 111, 'is_bot' => false, 'first_name' => 'علی'],
                    'chat' => ['id' => 111, 'type' => 'private'],
                    'date' => time(),
                ],
            ]),
            'received_at' => now(),
        ]);

        $this->updateIds[] = $updateId;

        return $updateId;
    }

    protected function queueDocument(int $updateId, string $uniqueId = 'bot-test-u1', int $messageId = 77, array $overrides = []): int
    {
        return $this->queueUpdate([
            'message_id' => $messageId,
            'document' => $overrides + [
                'file_id' => 'AAA' . $uniqueId,
                'file_unique_id' => $uniqueId,
                'file_name' => 'movie.mkv',
                'file_size' => 2048,
                'mime_type' => 'video/x-matroska',
            ],
        ], $updateId);
    }

    protected function runJob(int $updateId, BotFileImporter $importer): ImportBotFileJob
    {
        $job = new ImportBotFileJob($updateId);
        $job->handle($importer, new TelegramBotClient('123:abc'));

        return $job;
    }

    protected function submittedCopy(): int
    {
        $sent = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'copyMessage')) {
                $sent++;
            }
        }

        return $sent;
    }

    public function test_a_document_update_is_imported_and_the_user_gets_the_link(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([$this->adoptAnswer()]);
        $this->runJob($this->queueDocument(910001), new BotFileImporter($drive));

        $submission = DB::table('bot_submissions')->where('file_unique_id', 'bot-test-u1')->first();
        $file = RelayFile::where('tg_unique_id', 'bot-test-u1')->first();

        $this->assertNotNull($file, 'the relay row must exist');
        $this->assertSame('bot', $file->origin);
        $this->assertSame('done', $submission->status);
        $this->assertSame((int) $file->id, (int) $submission->relay_file_id);
        $this->assertSame(5001, (int) $submission->copy_message_id);

        // adopt got the copy's id in the channel, not the user's message id
        $this->assertSame(5001, $drive->sent[0]['message_id']);
        $this->assertSame('@store', $drive->sent[0]['storage_chat']);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/copyMessage')
                && $request['chat_id'] === '@store'
                && (int) $request['from_chat_id'] === 111
                && (int) $request['message_id'] === 77;
        });

        $this->assertSame(1, $this->submittedCopy());

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sendMessage')
                && (int) $request['chat_id'] === 111
                && str_contains((string) $request['text'], 'movie.mkv')
                && str_contains((string) $request['text'], '/r/');
        });
    }

    public function test_the_same_file_sent_twice_is_reported_as_already_stored(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([$this->adoptAnswer()]);
        $importer = new BotFileImporter($drive);

        $this->runJob($this->queueDocument(910002, 'bot-test-u1', 77), $importer);
        $this->runJob($this->queueDocument(910003, 'bot-test-u1', 88), $importer);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'قبلاً ذخیره');
        });
    }

    public function test_a_text_update_is_ignored(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([]);
        $this->runJob($this->queueUpdate(['message_id' => 90, 'text' => 'سلام'], 910004), new BotFileImporter($drive));

        Http::assertNothingSent();
        $this->assertCount(0, $drive->history);
        $this->assertSame(0, DB::table('bot_submissions')->where('message_id', 90)->count());
    }

    public function test_a_completed_submission_is_never_imported_again(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([$this->adoptAnswer()]);
        $importer = new BotFileImporter($drive);
        $updateId = $this->queueDocument(910005);

        $this->runJob($updateId, $importer);
        $this->runJob($updateId, $importer);

        $this->assertCount(1, $drive->history, 'the second run must not reach Telegram Drive');
        $this->assertSame(1, $this->submittedCopy());
    }

    public function test_a_terminal_failure_is_reported_to_the_user(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([
            new Response(400, [], (string) json_encode(['detail' => 'size mismatch'])),
        ]);

        $updateId = $this->queueDocument(910006);
        $job = new ImportBotFileJob($updateId);

        try {
            $job->handle(new BotFileImporter($drive), new TelegramBotClient('123:abc'));
            $this->fail('the failure must be rethrown so the queue can retry');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('size mismatch', $e->getMessage());
        }

        $row = DB::table('bot_submissions')->where('file_unique_id', 'bot-test-u1')->first();
        $this->assertSame('retrying', $row->status, 'attempt 1 of 3 is a retry, not a terminal failure');
        $this->assertStringContainsString('size mismatch', (string) $row->error);

        $job->failed(new RuntimeException('timed out'));

        $row = DB::table('bot_submissions')->where('id', $row->id)->first();
        $this->assertSame('failed', $row->status);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], '❌');
        });
    }

    public function test_bot_imports_use_their_own_queue(): void
    {
        config(['media_relay.bot.queue' => 'bot']);

        $this->assertSame('bot', (new ImportBotFileJob(1))->queue);
    }
}
