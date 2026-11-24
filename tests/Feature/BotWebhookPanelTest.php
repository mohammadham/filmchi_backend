<?php

namespace Tests\Feature;

use App\GlobalSettings;
use App\Http\Controllers\SettingController;
use App\Services\Bot\TelegramBotClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\RemembersGlobalSettings;
use Tests\TestCase;

/**
 * The card has two kinds of button: Set/Remove webhook write the card and only
 * then talk to the Bot API, while Check health only reads what is stored
 * (getMe/getWebhookInfo decide the badge). setWebhook must register the same
 * secret Telegram then sends back as a header. The tests call the controller
 * methods directly, exactly like the card's fetch calls, because the login
 * middleware is not what is under test.
 */
class BotWebhookPanelTest extends TestCase
{
    use RemembersGlobalSettings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberGlobalSettings();

        config([
            'media_relay.bot.enabled' => true,
            'media_relay.bot.token' => '123:abc',
            'media_relay.bot.webhook_secret' => 'hook-secret',
            'media_relay.bot.reply_timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreGlobalSettings();

        parent::tearDown();
    }

    /** One card button, called the way the panel calls it. */
    protected function card(string $method, array $form = []): array
    {
        return (new SettingController())
            ->{$method}(Request::create('/' . $method, 'POST', $form))
            ->getData(true);
    }

    /** The URL the webhook route builds for a secret. */
    protected function expectedUrl(string $secret = 'hook-secret'): string
    {
        return route('telegramWebhook', ['secret' => $secret]);
    }

    protected function fakeBotApi(?string $webhookUrl = null): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 1, 'is_bot' => true, 'username' => 'flixy_bot'],
            ]),
            'api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => $webhookUrl ?? $this->expectedUrl(),
                    'pending_update_count' => 4,
                ],
            ]),
        ]);
    }

    /** getMe answers, getWebhookInfo returns whatever the test hands over. */
    protected function fakeBotApiAnswering(PromiseInterface $webhookInfo): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 1, 'is_bot' => true, 'username' => 'flixy_bot'],
            ]),
            'api.telegram.org/*/getWebhookInfo' => $webhookInfo,
        ]);
    }

    public function test_health_compares_the_registered_webhook_with_the_expected_url(): void
    {
        $this->fakeBotApi();

        $payload = $this->card('botHealth');

        $this->assertTrue($payload['ok']);
        $this->assertSame('@flixy_bot', $payload['message']);
        $this->assertTrue($payload['webhook_ok'], 'Telegram already has the URL this panel would use');
        $this->assertSame(4, $payload['pending_updates']);
        $this->assertTrue($payload['token_set']);
        $this->assertTrue($payload['secret_set']);

        $this->assertStringContainsString('***', $payload['webhook_url']);
        $this->assertStringNotContainsString('hook-secret', $payload['webhook_url']);
        $this->assertStringNotContainsString('hook-secret', (string) $payload['expected_webhook_url']);
        $this->assertStringNotContainsString('123:abc', json_encode($payload));
    }

    public function test_health_reads_the_stored_card_and_ignores_unsaved_edits(): void
    {
        // Read-only, unlike Set/Remove webhook: the boxes do not count until
        // the card is saved, so a badge only ever describes what is served.
        $this->fakeBotApi();

        $payload = $this->card('botHealth', [
            'relay_bot_token' => '555:typed',
            'relay_bot_webhook_secret' => 'typed-secret',
        ]);

        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['webhook_ok'], 'the stored secret is still the one the route serves');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:abc/getMe'), 'the stored token is the one checked');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'typed'));

        $setting = GlobalSettings::first();

        $this->assertNotSame('555:typed', $setting?->relay_bot_token, 'health must not write the card');
        $this->assertNotSame('typed-secret', $setting?->relay_bot_webhook_secret);
    }

    public function test_health_flags_a_webhook_pointing_somewhere_else(): void
    {
        $this->fakeBotApi('https://elsewhere.test/telegram/hook');

        $payload = $this->card('botHealth');

        $this->assertTrue($payload['ok'], 'the bot itself is reachable');
        $this->assertFalse($payload['webhook_ok'], 'but Telegram has another URL registered');
        $this->assertSame('https://elsewhere.test/telegram/hook', $payload['webhook_url']);
    }

    public function test_health_reports_offline_when_telegram_refuses_the_token(): void
    {
        Http::fake(['api.telegram.org/*/getMe' => Http::response([
            'ok' => false,
            'description' => 'Unauthorized',
        ], 401)]);

        $payload = $this->card('botHealth');

        $this->assertFalse($payload['ok'], 'a refused token is an offline badge, not an exception');
        $this->assertStringContainsString('HTTP 401', $payload['message']);
        $this->assertStringContainsString('Unauthorized', $payload['message']);
        $this->assertFalse($payload['webhook_ok']);
        $this->assertStringNotContainsString('123:abc', $payload['message'], 'the token is part of the URL, never the message');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/getWebhookInfo'));
    }

    public function test_health_says_when_no_webhook_is_registered(): void
    {
        $this->fakeBotApi('');

        $payload = $this->card('botHealth');

        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['webhook_ok']);
        $this->assertSame('', $payload['webhook_url'], 'nothing registered reads as an empty URL, not as a match');
        $this->assertStringContainsString('***', (string) $payload['expected_webhook_url']);
    }

    public function test_health_puts_a_failed_webhook_lookup_in_last_error(): void
    {
        // getMe is fine, so the bot is online; only getWebhookInfo failed and
        // that is the one thing the card can report in last_error.
        $this->fakeBotApiAnswering(Http::response([
            'ok' => false,
            'description' => 'Internal Server Error',
        ], 500));

        $payload = $this->card('botHealth');

        $this->assertTrue($payload['ok'], 'a failed lookup is not an offline bot');
        $this->assertFalse($payload['webhook_ok']);
        $this->assertSame('', $payload['webhook_url'], 'nothing was read, so nothing is claimed');
        $this->assertSame(0, $payload['pending_updates']);
        $this->assertStringContainsString('getWebhookInfo', $payload['last_error']);
        $this->assertStringContainsString('HTTP 500', $payload['last_error']);
        $this->assertStringContainsString('Internal Server Error', $payload['last_error']);
        $this->assertStringNotContainsString('123:abc', $payload['last_error'], 'the token lives in the URL, never in a message');
    }

    public function test_health_surfaces_telegrams_own_webhook_delivery_error(): void
    {
        // last_error_message is Telegram reporting a failed delivery to a
        // correctly registered URL: the URL still matches, the error is shown.
        $this->fakeBotApiAnswering(Http::response([
            'ok' => true,
            'result' => [
                'url' => $this->expectedUrl(),
                'pending_update_count' => 2,
                'last_error_message' => 'Wrong response from the webhook: 500 Internal Server Error',
            ],
        ]));

        $payload = $this->card('botHealth');

        $this->assertTrue($payload['webhook_ok']);
        $this->assertSame(2, $payload['pending_updates']);
        $this->assertSame('Wrong response from the webhook: 500 Internal Server Error', $payload['last_error']);
    }

    public function test_health_without_a_token_never_calls_telegram(): void
    {
        Http::fake();

        config([
            'media_relay.bot.token' => '',
            'media_relay.bot.webhook_secret' => '',
        ]);

        $payload = $this->card('botHealth');

        $this->assertFalse($payload['ok']);
        $this->assertSame('token is not set', $payload['message']);
        $this->assertFalse($payload['token_set']);
        $this->assertFalse($payload['secret_set']);
        $this->assertFalse($payload['webhook_ok']);
        $this->assertSame('', $payload['expected_webhook_url']);
        Http::assertNothingSent();
    }

    public function test_set_webhook_registers_the_secret_as_path_and_header(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $payload = $this->card('setBotWebhook');

        $this->assertTrue($payload['status']);
        $this->assertStringNotContainsString('hook-secret', $payload['message'], 'the panel never echoes the secret');
        $this->assertStringContainsString('***', $payload['webhook_url']);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/setWebhook')
                && $request['url'] === $this->expectedUrl()
                && $request['secret_token'] === 'hook-secret'
                && $request['drop_pending_updates'] === false;
        });
    }

    public function test_set_webhook_saves_the_card_before_registering(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        config([
            'media_relay.bot.token' => '',
            'media_relay.bot.webhook_secret' => '',
        ]);

        $payload = $this->card('setBotWebhook', [
            'relay_bot_token' => '999:form',
            'relay_bot_webhook_secret' => 'form-secret',
        ]);

        $this->assertTrue($payload['status']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bot999:form/setWebhook')
                && $request['url'] === $this->expectedUrl('form-secret')
                && $request['secret_token'] === 'form-secret';
        });

        $setting = GlobalSettings::first();

        $this->assertNotNull($setting, 'the card is written, not only read for this call');
        $this->assertSame('999:form', $setting->relay_bot_token, 'Telegram must never hold a secret this install has not stored');
        $this->assertSame('form-secret', $setting->relay_bot_webhook_secret);
    }

    public function test_set_webhook_keeps_the_stored_secret_when_the_box_is_empty(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $payload = $this->card('setBotWebhook', ['relay_bot_webhook_secret' => '']);

        $this->assertTrue($payload['status'], 'an empty box keeps the saved value, exactly like the Save button');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === 'hook-secret'
            && $request['url'] === $this->expectedUrl());
    }

    public function test_set_webhook_registers_the_command_menu(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $payload = $this->card('setBotWebhook');

        $this->assertTrue($payload['status']);

        // The "/" menu comes from Telegram, not from this install: it has to be
        // pushed with the webhook, in the JSON field the Bot API reads.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setMyCommands')
            && json_decode((string) $request['commands'], true) === TelegramBotClient::COMMANDS);
    }

    public function test_a_refused_command_menu_is_reported_instead_of_passing_silently(): void
    {
        Http::fake([
            'api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/setMyCommands' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: commands too many',
            ], 400),
        ]);

        $payload = $this->card('setBotWebhook');

        $this->assertFalse($payload['status']);
        $this->assertStringContainsString('setMyCommands', $payload['message']);
    }

    public function test_delete_webhook_saves_the_card_first(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $payload = $this->card('deleteBotWebhook', ['relay_bot_token' => '321:typed']);

        $this->assertTrue($payload['status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot321:typed/deleteWebhook'));
        $this->assertSame('321:typed', GlobalSettings::first()->relay_bot_token);
    }

    public function test_delete_webhook_also_clears_the_command_menu(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $payload = $this->card('deleteBotWebhook');

        $this->assertTrue($payload['status']);

        // The menu is Telegram-side state that survives deleteWebhook: left
        // alone, "/" keeps listing commands the removed webhook cannot answer.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setMyCommands')
            && json_decode((string) $request['commands'], true) === []);
    }

    public function test_set_webhook_refuses_an_empty_secret(): void
    {
        Http::fake();

        config(['media_relay.bot.webhook_secret' => '']);

        $payload = $this->card('setBotWebhook');

        $this->assertFalse($payload['status']);
        $this->assertStringContainsString('secret', $payload['message']);
        Http::assertNothingSent();
    }

    public function test_set_webhook_refuses_without_a_token(): void
    {
        Http::fake();

        config(['media_relay.bot.token' => '']);

        $payload = $this->card('setBotWebhook');

        $this->assertFalse($payload['status']);
        $this->assertStringContainsString('relay_bot_token', $payload['message']);
        Http::assertNothingSent();
    }

    public function test_set_webhook_reports_telegram_refusals(): void
    {
        Http::fake(['api.telegram.org/*/setWebhook' => Http::response([
            'ok' => false,
            'description' => 'Bad Request: bad webhook',
        ], 400)]);

        $payload = $this->card('setBotWebhook');

        $this->assertFalse($payload['status']);
        $this->assertStringContainsString('HTTP 400', $payload['message']);
        $this->assertStringContainsString('bad webhook', $payload['message']);
        $this->assertStringNotContainsString('123:abc', $payload['message'], 'the token lives in the URL, never in an error');
    }

    public function test_delete_webhook_reports_telegram_refusals(): void
    {
        Http::fake(['api.telegram.org/*/deleteWebhook' => Http::response([
            'ok' => false,
            'description' => 'Unauthorized',
        ], 401)]);

        $payload = $this->card('deleteBotWebhook');

        $this->assertFalse($payload['status']);
        $this->assertStringContainsString('HTTP 401', $payload['message']);
        $this->assertStringContainsString('Unauthorized', $payload['message']);
        $this->assertStringNotContainsString('123:abc', $payload['message'], 'the token lives in the URL, never in an error');
    }

    public function test_neither_button_returns_the_webhook_secret_or_the_token(): void
    {
        // The secret is the webhook's path segment and the token is the Bot API
        // URL: the card may say both are set, never what they are. This pins the
        // whole response, so a new field cannot start echoing one of them.
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 1, 'is_bot' => true, 'username' => 'flixy_bot'],
            ]),
            'api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => ['url' => $this->expectedUrl(), 'pending_update_count' => 0],
            ]),
            'api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/setMyCommands' => Http::response(['ok' => true, 'result' => true]),
        ]);

        foreach (['botHealth', 'setBotWebhook'] as $button) {
            $payload = $this->card($button);
            $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('hook-secret', $json, "$button must not echo the webhook secret");
            $this->assertStringNotContainsString('123:abc', $json, "$button must not echo the bot token");
            $this->assertStringContainsString('***', $json, "$button still shows the webhook URL, masked");
        }
    }

    public function test_webhook_ok_compares_the_whole_url_not_only_the_secret(): void
    {
        // One fake for both rounds: Http::fake() merges stubs and the first match
        // wins, so the reported URL is switched through the closure instead.
        $url = '';
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 1, 'is_bot' => true, 'username' => 'flixy_bot'],
            ]),
            'api.telegram.org/*/getWebhookInfo' => function () use (&$url) {
                return Http::response([
                    'ok' => true,
                    'result' => ['url' => $url, 'pending_update_count' => 0],
                ]);
            },
        ]);

        $expected = $this->expectedUrl();
        // Same secret, same path, different scheme: the secret is a substring of
        // the expected URL, so anything looser than equality calls this a match.
        $url = str_starts_with($expected, 'https://')
            ? 'http://' . substr($expected, strlen('https://'))
            : 'https://' . substr($expected, strlen('http://'));

        $payload = $this->card('botHealth');

        $this->assertFalse($payload['webhook_ok'], 'a URL that only shares the secret is not the expected one');
        $this->assertSame(
            str_replace('hook-secret', '***', $url),
            $payload['webhook_url'],
            'the panel shows what Telegram has, masked'
        );
        $this->assertStringContainsString('***', (string) $payload['expected_webhook_url']);

        $url = $expected;

        $this->assertTrue($this->card('botHealth')['webhook_ok'], 'the exact URL is a match');
    }

    public function test_delete_webhook_without_a_token_does_not_call_telegram(): void
    {
        Http::fake();

        config(['media_relay.bot.token' => '']);

        $payload = $this->card('deleteBotWebhook');

        $this->assertFalse($payload['status']);
        $this->assertStringContainsString('relay_bot_token', $payload['message']);
        Http::assertNothingSent();
    }
}
