<?php

namespace Tests\Feature;

use App\ContentSource;
use App\EpisodeSource;
use App\Jobs\ProbeSourceLinkJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LinkHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tables ship with the production dump, not with migrations.
        if (!Schema::hasTable('content_sources')) {
            Schema::create('content_sources', function ($t) {
                $t->increments('id');
                $t->unsignedInteger('content_id');
                $t->string('title')->nullable();
                $t->string('quality')->nullable();
                $t->string('size')->nullable();
                $t->unsignedInteger('is_download')->default(0);
                $t->unsignedTinyInteger('access_type')->default(0);
                $t->unsignedTinyInteger('type')->default(0);
                $t->text('source')->nullable();
                $t->string('link_status', 16)->nullable();
                $t->timestamp('link_checked_at')->nullable();
                $t->unsignedInteger('link_fail_count')->default(0);
                $t->string('ingest_status', 16)->nullable();
                $t->unsignedInteger('ingest_progress')->default(0);
                $t->text('ingest_error')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('episode_sources')) {
            Schema::create('episode_sources', function ($t) {
                $t->increments('id');
                $t->unsignedInteger('episode_id');
                $t->string('title')->nullable();
                $t->string('quality')->nullable();
                $t->string('size')->nullable();
                $t->unsignedInteger('is_download')->default(0);
                $t->unsignedTinyInteger('access_type')->default(0);
                $t->unsignedTinyInteger('type')->default(0);
                $t->string('source')->nullable();
                $t->string('link_status', 16)->nullable();
                $t->timestamp('link_checked_at')->nullable();
                $t->unsignedInteger('link_fail_count')->default(0);
                $t->string('ingest_status', 16)->nullable();
                $t->unsignedInteger('ingest_progress')->default(0);
                $t->text('ingest_error')->nullable();
                $t->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('content_sources');
        Schema::dropIfExists('episode_sources');

        parent::tearDown();
    }

    protected function makeContentSource(string $url, int $type = 4): ContentSource
    {
        $s = new ContentSource();
        $s->content_id = 1;
        $s->title = 'src';
        $s->quality = '1080p';
        $s->size = '1MB';
        $s->is_download = 1;
        $s->type = $type;
        $s->access_type = 1;
        $s->source = $url;
        $s->save();

        return $s;
    }

    public function test_probe_marks_alive_and_resets_failures()
    {
        Http::fake(['*' => Http::response('', 200)]);

        $source = $this->makeContentSource('https://cdn.example/movie.mp4');
        $source->link_fail_count = 2;
        $source->save();

        (new ProbeSourceLinkJob('content', $source->id))->handle();

        $source->refresh();
        $this->assertSame('alive', $source->link_status);
        $this->assertSame(0, $source->link_fail_count);
        $this->assertNotNull($source->link_checked_at);
    }

    public function test_dead_only_after_consecutive_failures()
    {
        config(['media_relay.linkcheck.dead_after' => 3]);

        $source = $this->makeContentSource('https://cdn.example/movie.mp4');

        // First failure: 404 on both HEAD and ranged GET.
        Http::fake(['*' => Http::sequence()->push('', 404)->push('', 404)]);
        (new ProbeSourceLinkJob('content', $source->id))->handle();
        $source->refresh();
        $this->assertSame('alive', $source->link_status);
        $this->assertSame(1, $source->link_fail_count);

        // Second failure: still alive.
        Http::fake(['*' => Http::sequence()->push('', 404)->push('', 404)]);
        (new ProbeSourceLinkJob('content', $source->id))->handle();
        $source->refresh();
        $this->assertSame('alive', $source->link_status);
        $this->assertSame(2, $source->link_fail_count);

        // Third failure: dead.
        Http::fake(['*' => Http::sequence()->push('', 404)->push('', 404)]);
        (new ProbeSourceLinkJob('content', $source->id))->handle();
        $source->refresh();
        $this->assertSame('dead', $source->link_status);
    }

    public function test_head_refused_falls_back_to_ranged_get()
    {
        $source = $this->makeContentSource('https://cdn.example/movie.mp4');

        Http::fake([
            '*' => Http::sequence()
                ->push('', 405)      // HEAD refused
                ->push('', 206),     // ranged GET works
        ]);

        (new ProbeSourceLinkJob('content', $source->id))->handle();

        $source->refresh();
        $this->assertSame('alive', $source->link_status);
    }

    public function test_unprobeable_types_are_skipped()
    {
        Http::fake(['*' => Http::response('', 500)]);

        $youtube = $this->makeContentSource('abc123', 1);
        (new ProbeSourceLinkJob('content', $youtube->id))->handle();
        $youtube->refresh();
        $this->assertNull($youtube->link_status);

        $file = $this->makeContentSource('uploads/x.mp4', 7);
        (new ProbeSourceLinkJob('content', $file->id))->handle();
        $file->refresh();
        $this->assertNull($file->link_status);
    }

    public function test_episode_source_probed_too()
    {
        Http::fake(['*' => Http::response('', 200)]);

        $s = new EpisodeSource();
        $s->episode_id = 1;
        $s->title = 'src';
        $s->quality = '720p';
        $s->size = '1MB';
        $s->is_download = 0;
        $s->type = 4;
        $s->access_type = 1;
        $s->source = 'https://cdn.example/ep.mp4';
        $s->save();

        (new ProbeSourceLinkJob('episode', $s->id))->handle();

        $s->refresh();
        $this->assertSame('alive', $s->link_status);
    }

    public function test_check_command_dispatches_probes_for_url_sources()
    {
        Http::fake();

        $this->makeContentSource('https://cdn.example/a.mp4', 4);
        $this->makeContentSource('abc123', 1);      // youtube: skipped
        $this->makeContentSource('uploads/b.mp4', 7); // file: skipped

        $this->artisan('sources:check')->expectsOutput('Dispatched 1 link probes.');
    }
}
