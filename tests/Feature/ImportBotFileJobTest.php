<?php

namespace Tests\Feature;

use App\Exceptions\PermanentImportFailure;
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
 * reads the submission row, copies the document into the storage channel,
 * imports it, writes the result and answers the user.
 */
class ImportBotFileJobTest extends TestCase
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
        DB::table('bot_submissions')->where('file_unique_id', 'like', 'bot-test-%')->delete();

        $ids = RelayFile::where('tg_unique_id', 'like', 'bot-test-%')->pluck('id');

        if ($ids->isNotEmpty()) {
            RelayLink::whereIn('relay_file_id', $ids)->delete();
            RelayFile::whereIn('id', $ids)->delete();
        }
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

    /**
     * The row the webhook would have written; its id is what the job is given.
     */
    protected function queueDocument(string $uniqueId = 'bot-test-u1', int $messageId = 77, array $overrides = []): int
    {
        $document = $overrides + [
            'file_id' => 'AAA' . $uniqueId,
            'file_unique_id' => $uniqueId,
            'file_name' => 'movie.mkv',
            'file_size' => 2048,
            'mime_type' => 'video/x-matroska',
        ];

        return (int) DB::table('bot_submissions')->insertGetId([
            'telegram_user_id' => 111,
            'chat_id' => 111,
            'message_id' => $messageId,
            'file_unique_id' => (string) $document['file_unique_id'],
            'name' => (string) $document['file_name'],
            'size' => (int) $document['file_size'],
            'mime' => (string) $document['mime_type'],
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function runJob(int $submissionId, BotFileImporter $importer): ImportBotFileJob
    {
        $job = new ImportBotFileJob($submissionId);
        $job->handle($importer, new TelegramBotClient('123:abc'));

        return $job;
    }

    /** How many terminal failure texts reached the chat. */
    protected function failureMessages(): int
    {
        $sent = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], '❌ ذخیره نشد')) {
                $sent++;
            }
        }

        return $sent;
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

    /** How many manifests the bot pushed into the storage channel. */
    protected function manifestSends(): int
    {
        $sent = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/sendDocument')) {
                $sent++;
            }
        }

        return $sent;
    }

    public function test_a_document_update_is_imported_and_the_user_gets_the_link(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([$this->adoptAnswer()]);
        $this->runJob($this->queueDocument(), new BotFileImporter($drive));

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

        $this->runJob($this->queueDocument('bot-test-u1', 77), $importer);
        $this->runJob($this->queueDocument('bot-test-u1', 88), $importer);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'قبلاً ذخیره');
        });
    }

    public function test_a_submission_id_without_a_row_is_a_quiet_no_op(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([]);
        $this->runJob(999999, new BotFileImporter($drive));

        Http::assertNothingSent();
        $this->assertCount(0, $drive->history);
    }

    public function test_a_completed_submission_is_never_imported_again(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([$this->adoptAnswer()]);
        $importer = new BotFileImporter($drive);
        $submissionId = $this->queueDocument();

        $this->runJob($submissionId, $importer);
        $this->runJob($submissionId, $importer);

        $this->assertCount(1, $drive->history, 'the second run must not reach Telegram Drive');
        $this->assertSame(1, $this->submittedCopy());
    }

    public function test_a_terminal_failure_is_reported_to_the_user_once(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([
            new Response(400, [], (string) json_encode(['detail' => 'size mismatch'])),
            new Response(400, [], (string) json_encode(['detail' => 'size mismatch'])),
        ]);

        $submissionId = $this->queueDocument();
        $job = new ImportBotFileJob($submissionId);

        try {
            $job->handle(new BotFileImporter($drive), new TelegramBotClient('123:abc'));
            $this->fail('the failure must be rethrown so the queue can retry');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('size mismatch', $e->getMessage());
        }

        $row = DB::table('bot_submissions')->where('file_unique_id', 'bot-test-u1')->first();
        $this->assertSame('retrying', $row->status, 'attempt 1 of 3 is a retry, not a terminal failure');
        $this->assertStringContainsString('size mismatch', (string) $row->error);
        $this->assertSame(0, $this->failureMessages(), 'a retry says nothing to the user');

        // The last attempt: the worker calls failed() right after this throw,
        // so the text must not be sent from the catch as well.
        $job->job = new class
        {
            public function attempts(): int
            {
                return 3;
            }
        };

        try {
            $job->handle(new BotFileImporter($drive), new TelegramBotClient('123:abc'));
            $this->fail('the terminal attempt must still be rethrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('size mismatch', $e->getMessage());
        }

        $this->assertSame('failed', DB::table('bot_submissions')->where('id', $row->id)->value('status'));
        $this->assertSame(0, $this->failureMessages(), 'the throwing attempt leaves the message to failed()');

        $job->failed(new RuntimeException('timed out'));

        $this->assertSame('failed', DB::table('bot_submissions')->where('id', $row->id)->value('status'));
        $this->assertSame(1, $this->failureMessages(), 'the user gets exactly one ❌ ذخیره نشد');
    }

    public function test_an_incomplete_restore_fails_once_without_a_retry(): void
    {
        $this->fakeTelegram();
        config(['media_relay.bot.channel_id' => 7]);

        // adopt is refused, so the job takes the manifest path, and the restore
        // comes back with one lost part.
        $drive = new DriveMock([
            new Response(503, [], (string) json_encode(['detail' => 'adopt unavailable'])),
            new Response(200, [], (string) json_encode(['access_token' => 'jwt-9', 'expires_in' => 60])),
            new Response(200, [], (string) json_encode([
                'ok' => true,
                'inserted' => 1,
                'skipped' => 0,
                'file_ids' => ['f_partial'],
                'missing_parts' => 1,
            ])),
        ]);

        $submissionId = $this->queueDocument();
        $job = new ImportBotFileJob($submissionId);

        // The queue handle is what chooses "retry" over "stop", so the test
        // watches it instead of guessing from the status column.
        $handle = new class
        {
            /** @var array<int, Throwable> */
            public array $failures = [];

            public function fail($e = null): void
            {
                $this->failures[] = $e;
            }
        };

        $job->job = $handle;

        $job->handle(new BotFileImporter($drive), new TelegramBotClient('123:abc'));

        $this->assertCount(1, $handle->failures, 'the first refusal must fail the job, not retry it');
        $this->assertInstanceOf(PermanentImportFailure::class, $handle->failures[0]);
        $this->assertStringContainsString('lost 1 file part', $handle->failures[0]->getMessage());

        $row = DB::table('bot_submissions')->where('id', $submissionId)->first();
        $this->assertSame('failed', $row->status, 'a permanent failure is terminal at once');
        $this->assertStringContainsString('f_partial', (string) $row->error);

        // One attempt: one channel copy and one manifest. Each retry would mint
        // another file id, which is the reason this failure has to be final.
        $this->assertSame(1, $this->submittedCopy());
        $this->assertSame(1, $this->manifestSends());
        $this->assertNull(
            RelayFile::where('tg_unique_id', 'bot-test-u1')->first(),
            'a file with missing parts must never be published'
        );
        $this->assertSame(0, $this->failureMessages(), 'the text belongs to failed(), not the catch');

        $job->failed($handle->failures[0]);

        $this->assertSame(1, $this->failureMessages(), 'the user hears about it exactly once');
    }

    public function test_a_retry_reuses_the_copy_the_first_attempt_made(): void
    {
        $this->fakeTelegram();

        $submissionId = $this->queueDocument('bot-test-u1', 77);

        // Attempt 1 got as far as copyMessage and then died before the adopt.
        DB::table('bot_submissions')->where('message_id', 77)->update(['copy_message_id' => 5001]);

        $drive = new DriveMock([$this->adoptAnswer()]);
        $this->runJob($submissionId, new BotFileImporter($drive));

        $this->assertSame(0, $this->submittedCopy(), 'a retry must never copy the document twice');
        $this->assertSame(5001, $drive->sent[0]['message_id'], 'the adopt uses the copy from attempt 1');
        $this->assertSame('done', DB::table('bot_submissions')->where('message_id', 77)->value('status'));
    }

    public function test_the_copy_is_remembered_even_when_the_import_fails(): void
    {
        $this->fakeTelegram();

        $drive = new DriveMock([
            new Response(400, [], (string) json_encode(['detail' => 'size mismatch'])),
        ]);

        try {
            $this->runJob($this->queueDocument(), new BotFileImporter($drive));
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('size mismatch', $e->getMessage());
        }

        $this->assertSame(
            5001,
            (int) DB::table('bot_submissions')->where('file_unique_id', 'bot-test-u1')->value('copy_message_id'),
            'the copy is the retry checkpoint, so it must survive a failed adopt'
        );
    }

    public function test_bot_imports_use_their_own_queue(): void
    {
        config(['media_relay.bot.queue' => 'bot']);

        $this->assertSame('bot', (new ImportBotFileJob(1))->queue);
    }
}
