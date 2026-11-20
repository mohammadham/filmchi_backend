<?php

namespace Tests\Feature;

use App\ContentSource;
use App\EpisodeSource;
use App\Jobs\IngestUrlJob;
use App\Jobs\ProbeSourceLinkJob;
use App\Services\Relay\SourceRequeuer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Concerns\CreatesRelaySourceTables;
use Tests\Feature\Concerns\ReadyDestination;
use Tests\TestCase;

/**
 * A link that fetched zero bytes over several attempts and several Workers is
 * dead: it is marked link_status=dead and nothing requeues it until a probe
 * proves the origin answers again.
 */
class SourceQuarantineTest extends TestCase
{
    use CreatesRelaySourceTables, ReadyDestination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSourceTables();
        DB::table('episode_sources')->delete();
        DB::table('content_sources')->delete();
        Cache::flush();

        config([
            'media_relay.enabled' => true,
            'media_relay.telegram.base_url' => 'http://tgdrive.test',
            'media_relay.telegram.storage_chat' => '@store',
            'media_relay.workers.enabled' => true,
            'media_relay.workers.hosts' => 'cdn.test',
            'media_relay.quarantine.zero_attempts' => 2,
            'media_relay.quarantine.zero_workers' => 2,
            'media_relay.ingest.retry_max_attempts' => 3,
            'media_relay.ingest.retry_delay_seconds' => 300,
            'media_relay.ingest.connections' => 1,
            'media_relay.ingest.total_connections' => 1,
        ]);

        Queue::fake();
        $this->stubReadyDestination();

        ZeroByteJob::$workers = [];
        ZeroByteJob::$probed = false;
        ZeroByteJob::$bytesSeen = 0;
    }

    protected function tearDown(): void
    {
        DB::table('episode_sources')->delete();
        DB::table('content_sources')->delete();
        $this->dropSourceTables();

        parent::tearDown();
    }

    protected function source(string $url): EpisodeSource
    {
        $source = new EpisodeSource();
        $source->forceFill([
            'episode_id' => 1,
            'source' => $url,
            'ingest_status' => 'queued',
            'ingest_progress' => 0,
            'ingest_attempts' => 0,
            'ingest_fail_count' => 0,
            'ingest_zero_attempts' => 0,
            'ingest_zero_workers' => [],
        ])->save();

        return $source;
    }

    protected function contentSource(): ContentSource
    {
        $source = new ContentSource();
        $source->forceFill([
            'content_id' => 1,
            'title' => 'film',
            'source' => 'https://cdn.test/film.mkv',
            'ingest_status' => 'failed',
            'link_status' => 'dead',
            'ingest_zero_attempts' => 2,
            'ingest_zero_workers' => [11, 12],
        ])->save();

        return $source;
    }

    protected function runAttempt(EpisodeSource $source, array $workers): ZeroByteJob
    {
        ZeroByteJob::$workers = $workers;
        ZeroByteJob::$probed = false;

        $job = new ZeroByteJob('episode', (int) $source->id, (string) $source->source, 'test.mkv');

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

        $job->handle();

        return $job;
    }

    public function test_two_workers_with_zero_bytes_quarantine_the_link_before_the_queue_gives_up(): void
    {
        $source = $this->source('https://cdn.test/film.mkv');

        $first = $this->runAttempt($source, [11]);
        $source = $source->fresh();

        $this->assertSame('retrying', (string) $source->ingest_status);
        $this->assertSame(1, (int) $source->ingest_zero_attempts);
        $this->assertSame([11], $source->ingest_zero_workers);
        $this->assertSame([300], $first->job->released);

        $second = $this->runAttempt($source, [12]);
        $source = $source->fresh();

        $this->assertSame('dead', (string) $source->link_status);
        $this->assertSame('failed', (string) $source->ingest_status);
        $this->assertSame(2, (int) $source->ingest_zero_attempts);
        $this->assertSame([11, 12], $source->ingest_zero_workers);
        $this->assertNull($source->ingest_next_retry_at);
        $this->assertStringContainsString('قرنطینه', (string) $source->ingest_error);
        $this->assertSame([], $second->job->released, 'the third try must not be spent on a dead link');
        Queue::assertNothingPushed();
    }

    public function test_one_worker_alone_is_not_proof_of_a_dead_link(): void
    {
        $source = $this->source('https://cdn.test/film.mkv');

        foreach ([1, 2, 3] as $ignored) {
            $this->runAttempt($source->fresh(), [11]);
        }

        $source = $source->fresh();

        $this->assertSame(3, (int) $source->ingest_zero_attempts);
        $this->assertSame([11], $source->ingest_zero_workers);
        $this->assertNotSame('dead', (string) $source->link_status);
        $this->assertSame('failed', (string) $source->ingest_status);
    }

    public function test_bytes_on_disk_clear_the_zero_byte_evidence(): void
    {
        $source = $this->source('https://cdn.test/film.mkv');

        $this->runAttempt($source, [11]);
        $this->assertSame(1, (int) $source->fresh()->ingest_zero_attempts);

        ZeroByteJob::$bytesSeen = 4096;
        $this->runAttempt($source->fresh(), [12]);

        $source = $source->fresh();

        $this->assertSame(0, (int) $source->ingest_zero_attempts);
        $this->assertSame([], $source->ingest_zero_workers);
        $this->assertNotSame('dead', (string) $source->link_status);
    }

    public function test_a_direct_source_needs_no_worker_proof(): void
    {
        $source = $this->source('http://127.0.0.1:9/film.mkv');

        $this->runAttempt($source, []);

        $this->assertSame('retrying', (string) $source->fresh()->ingest_status);

        $this->runAttempt($source->fresh(), []);

        $source = $source->fresh();

        $this->assertSame('dead', (string) $source->link_status);
        $this->assertSame([], $source->ingest_zero_workers);
    }

    public function test_the_thresholds_come_from_config(): void
    {
        config([
            'media_relay.quarantine.zero_attempts' => 3,
            'media_relay.quarantine.zero_workers' => 3,
        ]);

        $source = $this->source('https://cdn.test/film.mkv');

        $this->runAttempt($source, [11]);
        $this->runAttempt($source->fresh(), [12]);

        $source = $source->fresh();

        $this->assertSame(2, (int) $source->ingest_zero_attempts);
        $this->assertSame('retrying', (string) $source->ingest_status);
        $this->assertNotSame('dead', (string) $source->link_status);
    }

    public function test_a_queued_job_does_not_touch_a_quarantined_source(): void
    {
        $source = $this->source('https://cdn.test/film.mkv');
        $source->link_status = 'dead';
        $source->save();

        $job = $this->runAttempt($source, [11]);

        $source = $source->fresh();

        $this->assertFalse(ZeroByteJob::$probed, 'the dead link must not be probed again');
        $this->assertSame('failed', (string) $source->ingest_status);
        $this->assertSame(0, (int) $source->ingest_attempts);
        $this->assertStringContainsString('قرنطینه', (string) $source->ingest_error);
        $this->assertSame([], $job->job->released);
    }

    public function test_requeue_is_blocked_until_a_successful_probe_revives_the_link(): void
    {
        $source = $this->contentSource();
        $requeuer = app(SourceRequeuer::class);

        $rejected = $requeuer->requeue('content', (int) $source->id);

        $this->assertFalse($rejected['ok']);
        $this->assertStringContainsString('قرنطینه', $rejected['message']);
        Queue::assertNothingPushed();

        Http::fake(['*' => Http::response('', 200)]);
        (new ProbeSourceLinkJob('content', (int) $source->id))->handle();

        $source = $source->fresh();

        $this->assertSame('alive', (string) $source->link_status);
        $this->assertSame(0, (int) $source->ingest_zero_attempts);
        $this->assertSame([], $source->ingest_zero_workers);

        $accepted = $requeuer->requeue('content', (int) $source->id);

        $this->assertTrue($accepted['ok'], $accepted['message']);
        Queue::assertPushed(IngestUrlJob::class);
    }
}

/** The job with the source probe replaced: it always fails before a byte. */
class ZeroByteJob extends IngestUrlJob
{
    /** @var array<int,int> */
    public static array $workers = [];

    public static bool $probed = false;

    /** Bytes the attempt managed to write before it failed. */
    public static int $bytesSeen = 0;

    protected function download($source, string $part): void
    {
        if (self::$bytesSeen > 0) {
            $source->ingest_bytes = self::$bytesSeen;
            $source->save();
        }

        parent::download($source, $part);
    }

    protected function probeSource(): array
    {
        self::$probed = true;
        $this->triedWorkers = self::$workers;

        throw new RuntimeException('could not open the source URL');
    }
}
