<?php

namespace Tests\Feature;

use App\Jobs\IngestUrlJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RelayRequeueStaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tables ship with the production dump, not with migrations.
        if (!Schema::hasTable('content_sources')) {
            Schema::create('content_sources', function ($t) {
                $t->increments('id');
                $t->unsignedInteger('content_id')->default(0);
                $t->string('title')->nullable();
                $t->string('quality')->nullable();
                $t->text('source')->nullable();
                $t->string('ingest_status', 16)->nullable();
                $t->unsignedInteger('ingest_progress')->default(0);
                $t->text('ingest_error')->nullable();
                $t->unsignedInteger('ingest_attempts')->default(0);
                $t->unsignedInteger('ingest_fail_count')->default(0);
                $t->timestamp('ingest_next_retry_at')->nullable();
                $t->string('upload_status', 16)->nullable();
                $t->unsignedInteger('upload_attempts')->default(0);
                $t->timestamp('upload_next_retry_at')->nullable();
                $t->timestamps();
            });
        }

        DB::table('content_sources')->delete();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('content_sources');

        parent::tearDown();
    }

    protected function row(array $attributes): int
    {
        return (int) DB::table('content_sources')->insertGetId(array_merge([
            'content_id' => 1,
            'title' => 'Film',
            'source' => 'https://example.com/film.mkv',
            'ingest_status' => 'failed',
            'ingest_progress' => 0,
            'ingest_error' => 'could not open the source URL',
            'ingest_attempts' => 3,
            'ingest_fail_count' => 2,
            'updated_at' => now()->subDays(7),
            'created_at' => now()->subDays(8),
        ], $attributes));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $id = $this->row([]);

        $this->artisan('relay:requeue-stale', ['--older-than' => 60])
            ->expectsOutputToContain('would be requeued')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame('failed', DB::table('content_sources')->where('id', $id)->value('ingest_status'));
    }

    public function test_apply_requeues_with_fresh_retry_counters(): void
    {
        $id = $this->row([]);

        $this->artisan('relay:requeue-stale', ['--older-than' => 60, '--apply' => true])
            ->assertExitCode(0);

        Queue::assertPushed(IngestUrlJob::class, 1);

        $row = DB::table('content_sources')->where('id', $id)->first();

        $this->assertSame('queued', $row->ingest_status);
        // The old attempts are cleared, otherwise the row would fail again on
        // its first try instead of using the retry policy.
        $this->assertSame(0, (int) $row->ingest_attempts);
        $this->assertSame(2, (int) $row->ingest_fail_count);
        $this->assertNull($row->ingest_error);
        $this->assertNull($row->ingest_next_retry_at);
    }

    public function test_rows_inside_the_cutoff_are_left_alone(): void
    {
        $id = $this->row(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('relay:requeue-stale', ['--older-than' => 60, '--apply' => true])
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame('failed', DB::table('content_sources')->where('id', $id)->value('ingest_status'));
    }

    public function test_a_retry_that_is_still_scheduled_is_not_duplicated(): void
    {
        $id = $this->row([
            'ingest_status' => 'retrying',
            'ingest_attempts' => 1,
            'ingest_next_retry_at' => now()->addMinutes(10),
        ]);

        $this->artisan('relay:requeue-stale', ['--status' => 'retrying', '--older-than' => 60, '--apply' => true])
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame('retrying', DB::table('content_sources')->where('id', $id)->value('ingest_status'));
    }

    public function test_a_stale_retry_is_requeued(): void
    {
        $id = $this->row([
            'ingest_status' => 'retrying',
            'ingest_attempts' => 1,
            'ingest_next_retry_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('relay:requeue-stale', ['--status' => 'retrying', '--older-than' => 60, '--apply' => true])
            ->assertExitCode(0);

        Queue::assertPushed(IngestUrlJob::class, 1);
        $this->assertSame('queued', DB::table('content_sources')->where('id', $id)->value('ingest_status'));
    }

    public function test_the_error_filter_selects_a_single_cause(): void
    {
        $this->row(['ingest_error' => 'could not open the source URL']);
        $storage = $this->row(['ingest_error' => 'Telegram Drive failed to store the file: no account can access storage chat']);

        $this->artisan('relay:requeue-stale', [
            '--older-than' => 60,
            '--exclude-error-like' => 'no account can access storage chat',
            '--apply' => true,
        ])->assertExitCode(0);

        Queue::assertPushed(IngestUrlJob::class, 1);
        $this->assertSame('failed', DB::table('content_sources')->where('id', $storage)->value('ingest_status'));
    }

    public function test_non_http_and_missing_sources_are_skipped(): void
    {
        $broken = $this->row(['source' => 'magnet:?xt=urn:btih:deadbeef']);

        $this->artisan('relay:requeue-stale', ['--older-than' => 60, '--apply' => true])
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame('failed', DB::table('content_sources')->where('id', $broken)->value('ingest_status'));
    }
}
