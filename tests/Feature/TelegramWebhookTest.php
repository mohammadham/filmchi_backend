<?php

namespace Tests\Feature;

use App\Jobs\ImportBotFileJob;
use App\Services\Relay\RelaySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1 of the bot plan: the webhook is inert until switched on, refuses a wrong
 * secret in the path or in Telegram's header, and only records updates from a
 * numeric id on the allowlist inside a private chat â€” once per update_id.
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
                'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Ø¹Ù„ÛŒ'],
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
        $this->assertSame('message', DB::table('bot_updates')->where('update_id', 10)->value('kind'));
        $this->assertNotNull(DB::table('bot_updates')->where('update_id', 10)->value('payload'), 'the update is kept for the job');

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
        $this->assertSame('callback_query', DB::table('bot_updates')->where('update_id', 11)->value('kind'));
    }

    public function test_outsiders_and_group_chats_are_ignored_silently(): void
    {
        $this->postUpdate($this->update(20, 999))->assertOk();        // not on the list
        $this->postUpdate($this->update(21, 111, 'supergroup'))->assertOk(); // not private

        $this->assertSame(0, DB::table('bot_updates')->count());
    }

    public function test_a_document_update_stores_its_payload_and_queues_the_import(): void
    {
        Queue::fake();

        $this->postUpdate($this->documentUpdate(40))->assertOk();

        Queue::assertPushed(ImportBotFileJob::class, fn ($job) => $job->updateId === 40);

        $payload = json_decode((string) DB::table('bot_updates')->where('update_id', 40)->value('payload'), true);
        $this->assertSame('uq-40', $payload['message']['document']['file_unique_id']);
        $this->assertSame(2048, $payload['message']['document']['file_size']);
    }

    public function test_a_retried_document_delivery_is_not_queued_twice(): void
    {
        Queue::fake();

        $this->postUpdate($this->documentUpdate(41))->assertOk();
        $this->postUpdate($this->documentUpdate(41))->assertOk();

        Queue::assertPushed(ImportBotFileJob::class, 1);
    }

    protected function documentUpdate(int $updateId, int $userId = 111): array
    {
        $update = $this->update($updateId, $userId);

        unset($update['message']['text']);

        $update['message']['document'] = [
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
            return str_ends_with($request->url(), '/sendMessage')
                && (int) $request['chat_id'] === 111
                && str_contains((string) $request['text'], 'سند');
        });
    }

    public function test_a_longer_command_is_not_mistaken_for_a_greeting(): void
    {
        Http::fake();

        $this->postUpdate($this->update(121, 111, 'private', '/startle'))->assertOk();
        $this->postUpdate($this->update(122, 111, 'private', '/pingpong'))->assertOk();

        Http::assertNothingSent();
        $this->assertSame(2, DB::table('bot_updates')->count(), 'both updates are recorded, neither replied');
    }

    public function test_only_an_allowed_ping_is_answered(): void
    {
        Http::fake();

        $this->postUpdate($this->update(31, 999, 'private', '/ping'))->assertOk();  // outsider
        $this->postUpdate($this->update(33, 111, 'private', 'Ø³Ù„Ø§Ù…'))->assertOk();   // plain text

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
