<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The panel's bot card is where a misconfigured queue becomes visible: with
 * QUEUE_CONNECTION=sync the import runs inside the webhook request, so the
 * health answer has to say so instead of reporting a clean "online".
 */
class BotHealthQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([
            'ok' => true,
            'result' => ['id' => 1, 'is_bot' => true, 'username' => 'flixy_bot', 'url' => ''],
        ])]);

        config([
            'media_relay.bot.enabled' => true,
            'media_relay.bot.token' => '123:abc',
            'media_relay.bot.webhook_secret' => 's3cret',
        ]);
    }

    /** The card's health call, without the login middleware in front of it. */
    protected function health(): array
    {
        return (new SettingController())
            ->botHealth(Request::create('/botHealth', 'POST'))
            ->getData(true);
    }

    public function test_the_card_flags_a_sync_queue(): void
    {
        config(['queue.default' => 'sync']);

        $payload = $this->health();

        $this->assertTrue($payload['queue_sync'], 'sync has no worker, so the card must warn');
        $this->assertSame('sync', $payload['queue_connection']);
    }

    public function test_a_real_queue_is_not_flagged_and_the_secret_stays_hidden(): void
    {
        config(['queue.default' => 'redis']);

        $payload = $this->health();

        $this->assertFalse($payload['queue_sync']);
        $this->assertSame('redis', $payload['queue_connection']);
        $this->assertStringNotContainsString('s3cret', (string) $payload['expected_webhook_url']);
        $this->assertStringNotContainsString('s3cret', (string) $payload['webhook_url']);
    }
}
