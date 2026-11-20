<?php

namespace Tests\Feature;

use App\Services\Bot\BotManifestSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The B″ hand-off: the manifest JSON goes into the storage channel with
 * sendDocument and its message id is what restore needs.
 */
class BotManifestSenderTest extends TestCase
{
    protected function manifest(): string
    {
        return (string) json_encode([
            'kind' => 'channel-backup',
            'channel' => ['chat' => '@store'],
            'files' => [['id' => 'f_1', 'message_ids' => [42117]]],
        ], JSON_UNESCAPED_SLASHES);
    }

    public function test_it_uploads_the_manifest_and_returns_the_message_id(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]], 200),
        ]);

        $id = (new BotManifestSender('123:abc', '@store'))($this->manifest());

        $this->assertSame(777, $id);
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $body = (string) json_encode($request->data());

            return str_ends_with($request->url(), '/bot123:abc/sendDocument')
                && str_contains($body, '@store')
                && str_contains($body, '#tgdrive-backup')
                && str_contains($body, 'channel-backup')
                && str_contains($body, 'tgdrive-manifest-');
        });
    }

    public function test_a_telegram_error_is_reported_without_leaking_the_token(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        try {
            (new BotManifestSender('123:abc', '@store'))($this->manifest());
            $this->fail('a failed sendDocument must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('chat not found', $e->getMessage());
            $this->assertStringNotContainsString('123:abc', $e->getMessage());
        }
    }

    public function test_it_refuses_to_send_without_a_token_or_channel(): void
    {
        Http::fake();

        foreach ([['', '@store'], ['123:abc', '']] as [$token, $chat]) {
            try {
                (new BotManifestSender($token, $chat))($this->manifest());
                $this->fail('an unconfigured sender must throw');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('relay_bot_token', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }
}
