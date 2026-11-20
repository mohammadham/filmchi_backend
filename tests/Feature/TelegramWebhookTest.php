<?php

namespace Tests\Feature;

use App\Services\Relay\RelaySettings;
use Illuminate\Support\Facades\DB;
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
    }

    protected function postUpdate(array $update, string $secret = 's3cret', ?string $header = 's3cret')
    {
        $headers = $header === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $header];

        return $this->postJson('telegram/webhook/' . $secret, $update, $headers);
    }

    protected function update(int $updateId, int $userId = 111, string $chatType = 'private'): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'علی'],
                'chat' => ['id' => $userId, 'type' => $chatType],
                'date' => time(),
                'text' => '/start',
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
