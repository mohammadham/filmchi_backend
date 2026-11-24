<?php

namespace Tests\Feature;

use App\GlobalSettings;
use App\Jobs\ImportBotFileJob;
use App\Providers\AppServiceProvider;
use App\RelayFile;
use App\RelayLink;
use App\Services\Bot\TelegramBotClient;
use App\Services\Relay\RelaySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1 of the bot plan: the webhook is inert until switched on, refuses a wrong
 * secret in the path or in Telegram's header, and only records updates from a
 * numeric id on the allowlist inside a private chat — once per update_id.
 */
class TelegramWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media_relay.bot.enabled' => true,
            'media_relay.bot.token' => '123:abc',
            'media_relay.bot.webhook_secret' => 's3cret',
            'media_relay.bot.allowed_ids' => '111, 222',
        ]);

        DB::table('bot_updates')->delete();
        DB::table('bot_submissions')->where('telegram_user_id', 111)->delete();
        DB::table('bot_sessions')->where('telegram_user_id', 111)->delete();
        $this->cleanStored();
    }

    protected function postUpdate(array $update, string $secret = 's3cret', ?string $header = 's3cret')
    {
        $headers = $header === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $header];

        return $this->postJson('telegram/webhook/' . $secret, $update, $headers);
    }

    protected function update(int $updateId, int $userId = 111, string $chatType = 'private', string $text = '/start'): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'علی'],
                'chat' => ['id' => $userId, 'type' => $chatType],
                'date' => time(),
                'text' => $text,
            ],
        ];
    }

    public function test_the_endpoint_does_not_exist_while_the_flag_is_off(): void
    {
        config(['media_relay.bot.enabled' => false]);

        $this->postUpdate($this->update(1))->assertNotFound();

        $this->assertSame(0, DB::table('bot_updates')->count());
    }

    public function test_the_endpoint_does_not_exist_without_a_token_or_secret(): void
    {
        config(['media_relay.bot.token' => '']);
        $this->postUpdate($this->update(1))->assertNotFound();

        config(['media_relay.bot.token' => '123:abc', 'media_relay.bot.webhook_secret' => '']);
        $this->postUpdate($this->update(2))->assertNotFound();

        $this->assertSame(0, DB::table('bot_updates')->count());
    }

    public function test_a_wrong_path_secret_is_a_404_and_a_bad_header_a_403(): void
    {
        $this->postUpdate($this->update(1), 'nope')->assertNotFound();
        $this->postUpdate($this->update(2), 's3cret', 'nope')->assertForbidden();

        $this->assertSame(0, DB::table('bot_updates')->count());
    }

    public function test_an_allowed_private_message_is_recorded_once(): void
    {
        $update = $this->update(10);

        $this->postUpdate($update)->assertOk();
        $this->postUpdate($update)->assertOk(); // Telegram retries the same id

        $this->assertSame(1, DB::table('bot_updates')->count());
        $this->assertNotNull(DB::table('bot_updates')->where('update_id', 10)->value('received_at'));

        $callback = [
            'update_id' => 11,
            'callback_query' => [
                'id' => 'cb',
                'from' => ['id' => 222],
                'message' => [
                    'message_id' => 9,
                    'chat' => ['id' => 222, 'type' => 'private'],
                ],
                'data' => 'pick:1',
            ],
        ];

        $this->postUpdate($callback)->assertOk();

        $this->assertSame(2, DB::table('bot_updates')->count());
    }

    public function test_outsiders_and_group_chats_are_ignored_silently(): void
    {
        $this->postUpdate($this->update(20, 999))->assertOk();        // not on the list
        $this->postUpdate($this->update(21, 111, 'supergroup'))->assertOk(); // not private

        $this->assertSame(0, DB::table('bot_updates')->count());
    }

    public function test_a_document_update_is_stored_and_opens_the_conversation(): void
    {
        Queue::fake();

        $this->postUpdate($this->documentUpdate(40))->assertOk();

        Queue::assertNothingPushed();

        $session = json_decode((string) DB::table('bot_sessions')->value('payload'), true);
        $this->assertSame('uq-40', $session['file_unique_id']);
        $this->assertSame(2048, $session['size']);
        $this->assertSame('quality', $this->sessionState());
    }

    public function test_a_retried_document_delivery_does_not_restart_the_conversation(): void
    {
        Queue::fake();

        $this->postUpdate($this->documentUpdate(41))->assertOk();
        $this->postUpdate($this->documentUpdate(41))->assertOk();

        $this->assertSame(1, DB::table('bot_sessions')->count());
        Queue::assertNothingPushed();
    }

    public function test_the_conversation_asks_quality_then_series_and_queues_the_import(): void
    {
        Queue::fake();
        Http::fake();

        $this->postDocument(50);

        Queue::assertNothingPushed();
        $this->assertSame('quality', $this->sessionState());
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], '1080'));

        $this->answer(51, '720');

        $this->assertSame('series', $this->sessionState());
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'سریال'));

        $this->answer(52, 'بله');

        $row = DB::table('bot_submissions')->where('message_id', 50)->first();

        $this->assertNotNull($row, 'only the last answer writes the submission');
        $this->assertSame('720', $row->quality);
        $this->assertSame(1, (int) $row->is_series);
        $this->assertSame('queued', $row->status);
        $this->assertSame('movie.mkv', $row->name);
        $this->assertSame(2048, (int) $row->size);

        Queue::assertPushedOn('bot', ImportBotFileJob::class);
        Queue::assertPushed(ImportBotFileJob::class, 1);
        $this->assertSame(0, DB::table('bot_sessions')->count(), 'the session ends with the last answer');
    }

    public function test_an_invalid_quality_answer_is_asked_again(): void
    {
        Queue::fake();
        Http::fake();

        $this->postDocument(53);
        $this->answer(54, 'خیلی خوب');

        $this->assertSame('quality', $this->sessionState());
        $this->assertSame(0, DB::table('bot_submissions')->count());
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], '1080، 720 یا 480'));

        $this->answer(55, '1080');
        $this->answer(56, 'خیر');

        $this->assertSame('1080', DB::table('bot_submissions')->where('message_id', 53)->value('quality'));
        Queue::assertPushed(ImportBotFileJob::class, 1);
    }

    public function test_the_inline_warning_is_once_an_hour_and_only_on_a_workerless_queue(): void
    {
        Queue::fake();
        Http::fake();
        Log::spy();

        config(['queue.default' => 'redis']);
        $this->importDocument(70);

        config(['queue.default' => 'sync']);
        $this->importDocument(73);
        $this->importDocument(76); // same hour: the statement is already written

        $this->travel(61)->minutes();
        $this->importDocument(79);

        // Exactly the two sync imports an hour apart, so neither the redis
        // upload nor the second sync one inside the hour logged it.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'runs inline'))
            ->twice();
    }

    public function test_a_null_queue_warns_that_the_import_is_dropped(): void
    {
        Queue::fake();
        Http::fake();
        Log::spy();
        config(['queue.default' => 'null']);

        $this->importDocument(140);

        // null drops the job without a trace, so it gets the same warning sync
        // does - and says the import is dropped, not merely inline.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'is dropped')
                && str_contains($message, 'queue.default is null'))
            ->once();
    }

    public function test_a_dead_reply_is_logged_once_an_hour_per_chat(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => false,
            'description' => 'Forbidden: bot was blocked by the user',
        ], 403)]);
        Log::spy();

        $dead = fn (string $message): bool => $message === 'bot message failed';

        // A blocked bot fails every answer: two pings, one line.
        $this->postUpdate($this->update(120, 111, 'private', '/ping'))->assertOk();
        $this->postUpdate($this->update(121, 111, 'private', '/ping'))->assertOk();
        Log::shouldHaveReceived('warning')->withArgs($dead)->once();

        $this->travel(61)->minutes();
        $this->postUpdate($this->update(122, 111, 'private', '/ping'))->assertOk();
        Log::shouldHaveReceived('warning')->withArgs($dead)->twice();
    }

    public function test_every_refused_file_and_ignored_update_is_logged(): void
    {
        Queue::fake();
        Http::fake();
        config(['media_relay.bot.max_size_mb' => 1]);
        Log::spy();

        $big = ['file_size' => 5 * 1024 * 1024];
        $refused = fn (string $message): bool => $message === 'bot document over the size cap';

        // No cooldown: two refusals in the same hour are two events. A key per
        // user would also have swallowed the next line about that same user.
        $this->postUpdate($this->documentUpdate(130, 111, $big))->assertOk();
        $this->postUpdate($this->documentUpdate(131, 111, $big))->assertOk();
        Log::shouldHaveReceived('info')->withArgs($refused)->twice();

        $this->postUpdate($this->update(132, 999, 'group'))->assertOk();
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message): bool => $message === 'bot update ignored')
            ->once();
    }

    public function test_an_outsider_gets_no_session_even_with_a_document(): void
    {
        Queue::fake();

        $this->postDocument(57, 999);

        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('bot_sessions')->count());
        $this->assertSame(0, DB::table('bot_submissions')->count());
    }

    public function test_the_same_message_delivered_again_is_one_submission(): void
    {
        Queue::fake();
        Http::fake();

        $this->postDocument(60);
        $this->answer(61, '1080');
        $this->answer(62, 'خیر');

        // Same chat/message under a new update id: the conversation re-asks,
        // but (chat_id, message_id) refuses the second row and the second job.
        $again = $this->documentUpdate(60);
        $again['update_id'] = 63;
        $this->postUpdate($again)->assertOk();
        $this->answer(64, '1080');
        $this->answer(65, 'خیر');

        $this->assertSame(1, DB::table('bot_submissions')->where('message_id', 60)->count());
        Queue::assertPushed(ImportBotFileJob::class, 1);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'قبلاً ثبت شده'));
    }

    public function test_the_daily_limit_is_checked_at_the_last_answer(): void
    {
        Queue::fake();
        Http::fake();

        config(['media_relay.bot.daily_limit' => 1]);

        $this->postDocument(70);
        $this->answer(71, '1080');
        $this->answer(72, 'خیر');

        $this->postDocument(73);
        $this->answer(74, '1080');
        $this->answer(75, 'خیر');

        $this->assertSame(1, DB::table('bot_submissions')->count(), 'a refused file never becomes a row');
        Queue::assertPushed(ImportBotFileJob::class, 1);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'سقف ورود روزانه'));
    }

    public function test_a_zero_daily_limit_means_unlimited(): void
    {
        Queue::fake();

        config(['media_relay.bot.daily_limit' => 0]);

        foreach ([80, 84] as $updateId) {
            $this->postDocument($updateId);
            $this->answer($updateId + 1, '1080');
            $this->answer($updateId + 2, 'بله');
        }

        $this->assertSame(2, DB::table('bot_submissions')->count());
        Queue::assertPushed(ImportBotFileJob::class, 2);
    }

    public function test_an_expired_session_is_ignored(): void
    {
        Queue::fake();
        Http::fake();

        $this->postDocument(90);
        $this->assertSame('quality', $this->sessionState());

        // The 30-minute window ran out while the chat sat idle.
        DB::table('bot_sessions')->where('chat_id', 111)->update(['expires_at' => now()->subMinute()]);

        $before = count(Http::recorded());

        $this->answer(91, '720');

        $this->assertSame($before, count(Http::recorded()), 'an expired session must not be answered');
        $this->assertSame('quality', $this->sessionState(), 'the stale row is left alone');
        $this->assertSame(0, DB::table('bot_submissions')->count());
        Queue::assertNothingPushed();
    }

    public function test_a_second_document_mid_conversation_replaces_the_first(): void
    {
        Queue::fake();
        Http::fake();

        $this->postDocument(92);
        $this->answer(93, '1080');
        $this->assertSame('series', $this->sessionState());

        // The user changed their mind before answering the last question.
        $this->postDocument(94);

        $this->assertSame('quality', $this->sessionState(), 'the new document restarts the questions');

        $payload = json_decode((string) DB::table('bot_sessions')->where('chat_id', 111)->value('payload'), true);

        $this->assertSame('uq-94', $payload['file_unique_id']);
        $this->assertArrayNotHasKey('quality', $payload, 'the old answers must not carry over');

        $this->answer(95, '720');
        $this->answer(96, 'خیر');

        $this->assertSame(1, DB::table('bot_submissions')->count(), 'only the newest document is registered');
        $this->assertSame('uq-94', DB::table('bot_submissions')->value('file_unique_id'));
        Queue::assertPushed(ImportBotFileJob::class, 1);
    }

    public function test_a_document_over_the_size_cap_is_refused_before_anything_is_written(): void
    {
        Queue::fake();
        Http::fake();
        config(['media_relay.bot.max_size_mb' => 1]);

        $this->postUpdate($this->documentUpdate(98, 111, ['file_size' => 5 * 1024 * 1024]))->assertOk();

        $this->assertSame(0, DB::table('bot_sessions')->where('chat_id', 111)->count(), 'no question for a file past the cap');
        $this->assertSame(0, DB::table('bot_submissions')->count(), 'no row is written');
        Queue::assertNothingPushed();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'بزرگ‌تر')
            && str_contains((string) $request['text'], '5 MB')
            && str_contains((string) $request['text'], '1 مگابایتی'));
    }

    public function test_a_document_at_the_size_cap_is_still_accepted(): void
    {
        Queue::fake();
        Http::fake();
        config(['media_relay.bot.max_size_mb' => 1]);

        $this->postUpdate($this->documentUpdate(99, 111, ['file_size' => 1024 * 1024]))->assertOk();

        $this->assertSame('quality', $this->sessionState(), 'the cap is exclusive, the edge still passes');
    }

    public function test_a_zero_size_cap_means_no_ceiling(): void
    {
        Queue::fake();
        Http::fake();
        config(['media_relay.bot.max_size_mb' => 0]);

        $this->postUpdate($this->documentUpdate(100, 111, ['file_size' => 5 * 1024 * 1024]))->assertOk();

        $this->assertSame('quality', $this->sessionState());
    }

    /**
     * The settings row the panel writes to, plus a copy to restore it after.
     *
     * @return array{0:\App\GlobalSettings,1:array<string,mixed>}
     */
    protected function rememberSettings(): array
    {
        $setting = GlobalSettings::first();

        if ($setting === null) {
            $this->fail('the install has no global_settings row for the panel to save');
        }

        return [$setting, $setting->getAttributes()];
    }

    /** @param array<string,mixed> $attributes */
    protected function restoreSettings(GlobalSettings $setting, array $attributes): void
    {
        // refresh() first: the instance still holds the pre-test values, so
        // forceFill() alone would leave nothing dirty and skip the UPDATE.
        $setting->refresh();
        $setting->forceFill($attributes)->save();

        RelaySettings::apply($setting);
    }

    public function test_the_cap_saved_in_the_panel_is_the_cap_the_webhook_applies(): void
    {
        Queue::fake();
        Http::fake();

        [$setting, $before] = $this->rememberSettings();

        try {
            // exactly what the bot card posts: the controller stores the value
            // and makes it effective for the requests that follow.
            // The bot card also carries its on/off switch; the saved card is
            // re-applied as a whole, so an off switch would 404 the endpoint.
            $this->withSession(['user_name' => 'admin'])
                ->post('saveRelaySettings', [
                    'relay_bot_enabled' => 1,
                    'relay_bot_max_size_mb' => 7,
                ])
                ->assertOk()
                ->assertJson(['status' => true]);

            $this->assertSame(7, (int) DB::table('global_settings')->value('relay_bot_max_size_mb'));
            $this->assertSame(7, (int) config('media_relay.bot.max_size_mb'), 'the saved cap is live, not just stored');

            $this->postUpdate($this->documentUpdate(110, 111, ['file_size' => 8 * 1024 * 1024]))->assertOk();

            $this->assertSame(0, DB::table('bot_sessions')->where('chat_id', 111)->count(), 'no question for a file past the saved cap');
            $this->assertSame(0, DB::table('bot_submissions')->count());
            Queue::assertNothingPushed();
            Http::assertSent(fn ($request) => str_contains((string) $request['text'], '8 MB')
                && str_contains((string) $request['text'], '7 مگابایتی'));
        } finally {
            $this->restoreSettings($setting, $before);
        }
    }

    public function test_a_cap_stored_in_the_database_is_applied_on_the_next_boot(): void
    {
        Queue::fake();
        Http::fake();

        [$setting, $before] = $this->rememberSettings();

        try {
            // Only the row changes: nobody calls config() for the panel path,
            // so what makes it effective is the boot-time bridge.
            DB::table('global_settings')->update([
                'relay_bot_enabled' => 1,
                'relay_bot_max_size_mb' => 4,
            ]);
            config(['media_relay.bot.max_size_mb' => 2048]);

            (new AppServiceProvider($this->app))->boot();

            $this->assertSame(4, (int) config('media_relay.bot.max_size_mb'));

            $this->postUpdate($this->documentUpdate(111, 111, ['file_size' => 5 * 1024 * 1024]))->assertOk();

            $this->assertSame(0, DB::table('bot_sessions')->where('chat_id', 111)->count());
            Http::assertSent(fn ($request) => str_contains((string) $request['text'], '4 مگابایتی'));
        } finally {
            $this->restoreSettings($setting, $before);
        }
    }

    public function test_a_stored_file_is_answered_as_stored_even_past_the_cap(): void
    {
        Queue::fake();
        Http::fake();
        config(['media_relay.bot.max_size_mb' => 1]);

        // Nothing would be imported for a duplicate, so the cheap answer wins
        // over the cap: the file is already in the relay, whatever its size.
        $link = $this->seedStoredFile();

        $this->postUpdate($this->documentUpdate(105, 111, [
            'file_unique_id' => 'uq-97', // the row seedStoredFile() just wrote
            'file_size' => 5 * 1024 * 1024,
        ]))->assertOk();

        $this->assertSame(0, DB::table('bot_sessions')->where('chat_id', 111)->count());
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'قبلاً ذخیره')
            && str_contains((string) $request['text'], $link->token));
    }

    public function test_a_file_already_stored_is_answered_before_the_questions(): void
    {
        Queue::fake();
        Http::fake();

        $link = $this->seedStoredFile();

        $this->postDocument(97);

        $this->assertSame(0, DB::table('bot_sessions')->where('chat_id', 111)->count(), 'a stored file must not open a conversation');
        $this->assertSame(0, DB::table('bot_submissions')->count());
        Queue::assertNothingPushed();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'قبلاً ذخیره')
            && str_contains((string) $request['text'], $link->token));
    }

    public function test_cancel_drops_the_half_answered_session(): void
    {
        Queue::fake();
        Http::fake();

        $this->postDocument(80);
        $this->answer(81, '720');
        $this->assertSame('series', $this->sessionState());

        $this->answer(82, '/cancel');

        $this->assertSame(0, DB::table('bot_sessions')->where('chat_id', 111)->count(), 'the session must be gone');
        $this->assertSame(0, DB::table('bot_submissions')->count(), 'a cancelled file is never registered');
        Queue::assertNothingPushed();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'لغو شد'));

        // The next text is a fresh start, not another re-ask of the question.
        $this->answer(83, 'بله');

        $this->assertSame(0, DB::table('bot_sessions')->where('chat_id', 111)->count());
        Queue::assertNothingPushed();
    }

    public function test_cancel_also_works_with_the_bot_name_suffix(): void
    {
        Queue::fake();
        Http::fake();

        $this->postDocument(84);
        $this->assertSame('quality', $this->sessionState());

        $this->answer(85, '/cancel@flixy_bot');

        $this->assertSame(0, DB::table('bot_sessions')->where('chat_id', 111)->count());
        $this->assertSame(0, DB::table('bot_submissions')->count());
    }

    public function test_cancel_without_a_conversation_is_a_quiet_noop(): void
    {
        Queue::fake();
        Http::fake();

        $this->answer(86, '/cancel');

        $this->assertSame(0, DB::table('bot_submissions')->count());
        Queue::assertNothingPushed();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'در جریان نیست'));
    }

    public function test_a_stuck_answer_points_at_cancel(): void
    {
        Queue::fake();
        Http::fake();

        $this->postDocument(87);
        $this->answer(88, 'خیلی خوب');

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], '/cancel'));
    }

    /** A completed relay row plus its link, as if the bot had imported it. */
    protected function seedStoredFile(): RelayLink
    {
        $file = new RelayFile();
        $file->name = 'movie.mkv';
        $file->size = 2048;
        $file->tg_file_id = 'f_old';
        $file->tg_backend = 'telegram';
        $file->cache_state = RelayFile::COLD;
        $file->ingest_status = 'completed';
        $file->origin = 'bot';
        $file->tg_unique_id = 'uq-97';
        $file->save();

        return RelayLink::issueFor($file);
    }

    /** The relay rows this file's dedup test creates. */
    protected function cleanStored(): void
    {
        $ids = RelayFile::where('tg_unique_id', 'uq-97')->pluck('id');

        if ($ids->isNotEmpty()) {
            RelayLink::whereIn('relay_file_id', $ids)->delete();
            RelayFile::whereIn('id', $ids)->delete();
        }
    }

    public function test_a_new_document_says_the_parked_one_was_dropped(): void
    {
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 80],
        ])]);

        $this->postDocument(90);
        $this->postDocument(91);

        // the replaced file is named, and the quality question still follows
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, 'لغو شد')
                && str_contains($text, 'movie.mkv')
                && str_contains($text, '1080');
        });

        // one live conversation, pointing at the second document only
        $this->assertSame(1, DB::table('bot_sessions')->count());
        $payload = json_decode((string) DB::table('bot_sessions')->value('payload'), true);
        $this->assertSame(91, (int) $payload['message_id']);
        $this->assertSame(0, DB::table('bot_submissions')->count(), 'the dropped file was never queued');
    }

    public function test_resending_the_same_document_is_not_called_a_cancel(): void
    {
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 81],
        ])]);

        $this->postUpdate($this->documentUpdate(92))->assertOk();
        $this->postUpdate($this->documentUpdate(93, 111, ['file_unique_id' => 'uq-92']))->assertOk();

        Http::assertNotSent(fn ($request) => str_contains((string) $request['text'], 'لغو شد'));
    }

    public function test_media_telegram_sends_without_a_file_name_is_told_to_arrive_as_a_document(): void
    {
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 9],
        ])]);

        $media = [
            'photo' => [['file_id' => 'p', 'file_unique_id' => 'pq', 'width' => 90, 'height' => 90]],
            'video' => ['file_id' => 'v', 'file_unique_id' => 'vq', 'duration' => 3],
            'audio' => ['file_id' => 'a', 'file_unique_id' => 'aq', 'duration' => 30],
            'voice' => ['file_id' => 'vo', 'file_unique_id' => 'voq', 'duration' => 4],
            'sticker' => ['file_id' => 's', 'file_unique_id' => 'sq', 'width' => 512, 'height' => 512],
        ];

        $updateId = 100;

        foreach ($media as $kind => $value) {
            $this->postUpdate($this->mediaUpdate($updateId++, [$kind => $value]))->assertOk();
        }

        // one answer per upload, all pointing at Document
        Http::assertSentCount(count($media));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (int) $request['chat_id'] === 111
            && str_contains((string) $request['text'], 'Document'));

        // nothing was parked and nothing was queued
        $this->assertSame(0, DB::table('bot_sessions')->count());
        $this->assertSame(0, DB::table('bot_submissions')->count());
        Queue::assertNothingPushed();
    }

    /** An update that carries media instead of text. */
    protected function mediaUpdate(int $updateId, array $media): array
    {
        $update = $this->update($updateId);

        unset($update['message']['text']);

        return ['update_id' => $update['update_id'], 'message' => $update['message'] + $media];
    }

    /** One document through the whole question flow, which queues one import. */
    protected function importDocument(int $updateId): void
    {
        $this->postDocument($updateId);
        $this->answer($updateId + 1, '1080');
        $this->answer($updateId + 2, 'خیر');
    }

    protected function postDocument(int $updateId, int $userId = 111): void
    {
        $this->postUpdate($this->documentUpdate($updateId, $userId))->assertOk();
    }

    protected function answer(int $updateId, string $text, int $userId = 111): void
    {
        $this->postUpdate($this->update($updateId, $userId, 'private', $text))->assertOk();
    }

    protected function sessionState(): ?string
    {
        return DB::table('bot_sessions')->where('chat_id', 111)->value('state');
    }

    protected function documentUpdate(int $updateId, int $userId = 111, array $document = []): array
    {
        $update = $this->update($updateId, $userId);

        unset($update['message']['text']);

        $update['message']['document'] = $document + [
            'file_id' => 'AAA' . $updateId,
            'file_unique_id' => 'uq-' . $updateId,
            'file_name' => 'movie.mkv',
            'file_size' => 2048,
            'mime_type' => 'video/x-matroska',
        ];

        return $update;
    }

    public function test_an_allowed_ping_is_answered(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 55],
        ])]);

        $this->postUpdate($this->update(30, 111, 'private', '/ping'))->assertOk();

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sendMessage')
                && (int) $request['chat_id'] === 111
                && $request['text'] === 'pong';
        });
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('bot_updates')->count());
    }

    public function test_the_start_button_gets_a_welcome_reply(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 70],
        ])]);

        $this->postUpdate($this->update(120, 111, 'private', '/start'))->assertOk();

        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            // Only documents are accepted, so the welcome must not promise video.
            return str_ends_with($request->url(), '/sendMessage')
                && (int) $request['chat_id'] === 111
                && str_contains($text, 'سند')
                && !str_contains($text, 'ویدیو');
        });
    }

    public function test_the_help_command_lists_the_commands_and_the_import_steps(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 71],
        ])]);

        $this->postUpdate($this->update(123, 111, 'private', '/help'))->assertOk();
        // Telegram appends @BotName in groups, so the suffix must not hide it
        $this->postUpdate($this->update(124, 111, 'private', '/help@flixy_bot'))->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            // /help is the "/" menu's own list: every registered command and
            // its description, so the two cannot drift apart.
            foreach (TelegramBotClient::COMMANDS as $command) {
                if (!str_contains($text, '/' . $command['command'] . ' — ' . $command['description'])) {
                    return false;
                }
            }

            return str_ends_with($request->url(), '/sendMessage')
                && (int) $request['chat_id'] === 111
                && str_contains($text, 'Document')
                && str_contains($text, '1080');
        });
    }

    public function test_a_longer_command_is_not_mistaken_for_a_greeting(): void
    {
        Http::fake();

        $this->postUpdate($this->update(121, 111, 'private', '/startle'))->assertOk();
        $this->postUpdate($this->update(122, 111, 'private', '/pingpong'))->assertOk();
        $this->postUpdate($this->update(125, 111, 'private', '/helper'))->assertOk();

        Http::assertNothingSent();
        $this->assertSame(3, DB::table('bot_updates')->count(), 'every update is recorded, none replied');
    }

    public function test_only_an_allowed_ping_is_answered(): void
    {
        Http::fake();

        $this->postUpdate($this->update(31, 999, 'private', '/ping'))->assertOk();  // outsider
        $this->postUpdate($this->update(33, 111, 'private', 'سلام'))->assertOk();   // plain text

        Http::assertNothingSent();
        $this->assertSame(1, DB::table('bot_updates')->count(), 'only the allowed text update was recorded');
    }

    public function test_a_failed_ping_reply_does_not_break_the_webhook(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => false,
            'description' => 'Bad Request: chat not found',
        ], 400)]);

        $this->postUpdate($this->update(32, 111, 'private', '/ping'))->assertOk();

        // recorded once, so Telegram's retry of the same update is still a no-op
        $this->assertSame(1, DB::table('bot_updates')->count());
    }

    public function test_an_update_without_an_id_is_rejected(): void
    {
        $this->postUpdate(['message' => ['text' => 'hi']])->assertStatus(400);

        $this->assertSame(0, DB::table('bot_updates')->count());
    }

    public function test_the_panel_never_returns_the_bot_secrets(): void
    {
        $view = RelaySettings::effective();

        $this->assertTrue($view['relay_bot_enabled']);
        $this->assertSame('', $view['relay_bot_token']);
        $this->assertTrue($view['relay_bot_token_set']);
        $this->assertSame('', $view['relay_bot_webhook_secret']);
        $this->assertTrue($view['relay_bot_webhook_secret_set']);
        $this->assertSame('111, 222', $view['relay_bot_allowed_ids']);
        $this->assertSame('1080', $view['relay_bot_quality']);
        $this->assertSame(20, $view['relay_bot_daily_limit']);
        $this->assertContains('relay_bot_token', RelaySettings::columns());
    }
}
