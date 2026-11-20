<?php

namespace Tests\Feature;

use App\EpisodeSource;
use App\Jobs\IngestUrlJob;
use App\Services\Relay\DestinationCheck;
use App\Services\Relay\DestinationUnavailable;
use App\Services\Relay\TelegramDriveClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Concerns\CreatesRelaySourceTables;
use Tests\TestCase;

/**
 * The upload destination is proven before a source is touched.
 *
 * Telegram Drive answers with the same preflight the real upload uses, and its
 * answer is cached so a queue full of sources does not probe once per job.
 */
class DestinationPreflightTest extends TestCase
{
    use CreatesRelaySourceTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSourceTables();
        DB::table('episode_sources')->delete();
        Cache::flush();

        config([
            'media_relay.enabled' => true,
            'media_relay.telegram.base_url' => 'http://tgdrive.test',
            'media_relay.telegram.storage_chat' => '@store',
            'media_relay.destination.ttl' => 60,
            'media_relay.destination.negative_ttl' => 30,
            'media_relay.ingest.retry_max_attempts' => 3,
            'media_relay.ingest.retry_delay_seconds' => 300,
            'media_relay.ingest.disk_percent' => 50,
            'media_relay.ingest.connections' => 4,
            'media_relay.ingest.total_connections' => 4,
        ]);

        Queue::fake();

        DestinationProbeJob::$probed = false;
    }

    protected function tearDown(): void
    {
        DB::table('episode_sources')->delete();
        $this->dropSourceTables();

        parent::tearDown();
    }

    protected function source(): EpisodeSource
    {
        $source = new EpisodeSource();
        $source->forceFill([
            'episode_id' => 1,
            'source' => 'http://127.0.0.1:9/film.mkv',
            'ingest_status' => 'queued',
            'ingest_progress' => 0,
            'ingest_attempts' => 0,
            'ingest_fail_count' => 0,
        ])->save();

        return $source;
    }

    protected function jobFor(EpisodeSource $source): DestinationProbeJob
    {
        $job = new DestinationProbeJob('episode', (int) $source->id, (string) $source->source, 'test.mkv');

        $job->job = new class {
            public array $released = [];

            public function release($delay = 0): void
            {
                $this->released[] = $delay;
            }

            public function delete(): void
            {
            }

            public function attempts(): int
            {
                return 1;
            }
        };

        return $job;
    }

    public function test_a_ready_destination_is_reported_and_cached(): void
    {
        $drive = new StubDriveClient();
        $check = new DestinationCheck($drive);

        $first = $check->inspect();
        $second = $check->inspect();

        $this->assertTrue($first['ok']);
        $this->assertFalse($first['cached']);
        $this->assertTrue($second['cached']);
        $this->assertSame(1, $drive->calls);
    }

    public function test_a_dead_storage_chat_surfaces_the_services_message_and_is_cached(): void
    {
        $drive = new StubDriveClient();
        $drive->message = 'مقصد آپلود آماده نیست: هیچ حساب تلگرامی اجازه نوشتن در این کانال را ندارد.';
        $check = new DestinationCheck($drive);

        $first = $check->inspect();
        $second = $check->inspect();

        $this->assertFalse($first['ok']);
        $this->assertSame($drive->message, $first['message']);
        $this->assertFalse($second['ok']);
        $this->assertSame(1, $drive->calls, 'a failure is cached for the negative TTL');
    }

    public function test_assert_ready_throws_the_persian_message(): void
    {
        $drive = new StubDriveClient();
        $drive->message = 'مقصد آپلود آماده نیست: کانال ذخیره سازی تنظیم نشده است.';

        $this->expectException(DestinationUnavailable::class);
        $this->expectExceptionMessage('کانال ذخیره سازی تنظیم نشده است');

        (new DestinationCheck($drive))->assertReady();
    }

    public function test_an_unreachable_service_is_a_message_not_an_exception(): void
    {
        $drive = new StubDriveClient();
        $drive->error = 'cURL error 7: connection refused';

        $result = (new DestinationCheck($drive))->inspect();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('در دسترس نیست', $result['message']);
        $this->assertStringContainsString('connection refused', $result['message']);
    }

    public function test_a_disabled_relay_is_reported_without_probing(): void
    {
        config(['media_relay.enabled' => false]);
        $drive = new StubDriveClient();

        $result = (new DestinationCheck($drive))->inspect();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('غیرفعال', $result['message']);
        $this->assertSame(0, $drive->calls);
    }

    public function test_the_job_defers_before_fetching_a_byte_when_the_destination_is_dead(): void
    {
        $source = $this->source();
        $drive = new StubDriveClient();
        $drive->message = 'مقصد آپلود آماده نیست: هیچ حساب تلگرامی اجازه نوشتن در این کانال را ندارد.';

        $this->app->instance(DestinationCheck::class, new DestinationCheck($drive));

        $this->jobFor($source)->handle();

        $source = $source->fresh();

        $this->assertFalse(DestinationProbeJob::$probed, 'the source must not be touched');
        $this->assertSame('retrying', (string) $source->ingest_status);
        $this->assertSame(0, (int) $source->ingest_attempts, 'no attempt is spent on the destination');
        $this->assertSame(0, (int) $source->ingest_fail_count);
        $this->assertNotNull($source->ingest_next_retry_at);
        $this->assertStringContainsString('مقصد آپلود', (string) $source->ingest_error);
        $this->assertFileDoesNotExist(storage_path('app/ingest/ingest-' . $source->id . '.part'));

        Queue::assertPushed(DestinationProbeJob::class);
    }

    public function test_the_job_reaches_the_source_when_the_destination_is_ready(): void
    {
        $source = $this->source();
        $drive = new StubDriveClient();

        $this->app->instance(DestinationCheck::class, new DestinationCheck($drive));

        $job = $this->jobFor($source);
        $job->handle();

        $source = $source->fresh();

        $this->assertTrue(DestinationProbeJob::$probed);
        $this->assertSame(1, (int) $source->ingest_attempts);
        $this->assertSame(1, (int) $source->ingest_fail_count);
        $this->assertSame('retrying', (string) $source->ingest_status);
        $this->assertSame([300], $job->job->released);
        Queue::assertNothingPushed();
    }

    public function test_a_409_probe_shows_the_services_persian_refusal_verbatim(): void
    {
        $drive = new MockedDriveClient([
            new Response(409, [], (string) json_encode([
                'detail' => 'مقصد آپلود آماده نیست: هیچ حساب تلگرامی به کانال ذخیره سازی دسترسی نوشتن ندارد.',
            ])),
        ]);

        $message = (string) $drive->destinationUnavailable('@store');

        $this->assertStringContainsString('دسترسی نوشتن ندارد', $message);
    }

    public function test_other_probe_errors_are_reported_in_persian_with_the_status(): void
    {
        $drive = new MockedDriveClient([new Response(500, [], 'boom')]);

        $message = (string) $drive->destinationUnavailable('@store');

        $this->assertStringContainsString('مقصد آپلود آماده نیست', $message);
        $this->assertStringContainsString('500', $message);
    }

    public function test_a_ready_probe_cancels_the_empty_session_it_created(): void
    {
        $drive = new MockedDriveClient([
            new Response(200, [], (string) json_encode(['session_id' => 'us_probe1'])),
            new Response(200, [], '{}'),
        ]);

        $this->assertNull($drive->destinationUnavailable('@store'));

        $this->assertCount(2, $drive->history);
        $this->assertSame('POST', $drive->history[0]['method']);
        $this->assertStringContainsString('upload/session', $drive->history[0]['path']);
        $this->assertSame('@store', $drive->history[0]['chat']);
        $this->assertSame('DELETE', $drive->history[1]['method']);
        $this->assertStringContainsString('us_probe1', $drive->history[1]['path']);
    }
}

/**
 * Stands in for Telegram Drive: records how often the destination was asked
 * and returns (or throws) whatever the test wants.
 */
class StubDriveClient extends TelegramDriveClient
{
    public int $calls = 0;

    public ?string $message = null;

    public ?string $error = null;

    public function __construct()
    {
        parent::__construct(['base_url' => 'http://tgdrive.test']);
    }

    public function baseUrl(): string
    {
        return 'http://tgdrive.test';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function destinationUnavailable(string $storageChat = ''): ?string
    {
        $this->calls++;

        if ($this->error !== null) {
            throw new RuntimeException($this->error);
        }

        return $this->message;
    }
}

/**
 * The real client with its HTTP layer mocked: proves what the probe sends and
 * how each answer is turned into a panel message.
 */
class MockedDriveClient extends TelegramDriveClient
{
    /** @var array<int, array{method:string,path:string,chat:string}> */
    public array $history = [];

    public function __construct(array $responses)
    {
        parent::__construct(['base_url' => 'http://tgdrive.test', 'api_key' => 'test-key']);

        $stack = HandlerStack::create(new MockHandler($responses));

        $stack->push(Middleware::mapRequest(function ($request) {
            $this->history[] = [
                'method' => $request->getMethod(),
                'path' => (string) $request->getUri(),
                'chat' => $request->getHeaderLine('X-Storage-Chat'),
            ];

            return $request;
        }));

        $this->http = new HttpClient([
            'handler' => $stack,
            'base_uri' => 'http://tgdrive.test/',
            'http_errors' => false,
        ]);
    }
}

/** The job with only the source probe replaced: reaching it proves preflight passed. */
class DestinationProbeJob extends IngestUrlJob
{
    public static bool $probed = false;

    protected function probeSource(): array
    {
        self::$probed = true;

        throw new RuntimeException('source is unreachable');
    }
}
