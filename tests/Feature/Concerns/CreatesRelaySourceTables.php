<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * The relay source tables ship with the production dump, not with migrations,
 * so tests that exercise the ingest jobs create the columns those jobs touch.
 * Only tables this trait created are dropped again.
 */
trait CreatesRelaySourceTables
{
    /** @var string[] */
    protected array $createdSourceTables = [];

    protected function createSourceTables(): void
    {
        foreach (['episode_sources' => 'episode_id', 'content_sources' => 'content_id'] as $table => $ownerColumn) {
            if (Schema::hasTable($table)) {
                continue;
            }

            Schema::create($table, function ($blueprint) use ($ownerColumn) {
                $blueprint->increments('id');
                $blueprint->unsignedInteger($ownerColumn)->default(0);
                $blueprint->string('title')->nullable();
                $blueprint->string('quality')->nullable();
                $blueprint->string('size')->nullable();
                $blueprint->text('source')->nullable();
                $blueprint->string('ingest_status', 16)->nullable();
                $blueprint->unsignedInteger('ingest_progress')->default(0);
                $blueprint->text('ingest_error')->nullable();
                $blueprint->unsignedInteger('ingest_attempts')->default(0);
                $blueprint->unsignedInteger('ingest_fail_count')->default(0);
                $blueprint->unsignedSmallInteger('ingest_zero_attempts')->default(0);
                $blueprint->text('ingest_zero_workers')->nullable();
                $blueprint->string('link_status', 16)->nullable();
                $blueprint->timestamp('link_checked_at')->nullable();
                $blueprint->unsignedInteger('link_fail_count')->default(0);
                $blueprint->timestamp('ingest_next_retry_at')->nullable();
                $blueprint->unsignedBigInteger('ingest_bytes')->nullable();
                $blueprint->unsignedBigInteger('ingest_speed_bps')->nullable();
                $blueprint->unsignedInteger('ingest_connections')->default(0);
                $blueprint->timestamp('ingest_started_at')->nullable();
                $blueprint->string('upload_status', 16)->nullable();
                $blueprint->unsignedInteger('upload_attempts')->default(0);
                $blueprint->unsignedInteger('upload_fail_count')->default(0);
                $blueprint->timestamp('upload_next_retry_at')->nullable();
                $blueprint->unsignedBigInteger('upload_bytes')->nullable();
                $blueprint->unsignedInteger('upload_duration_ms')->nullable();
                $blueprint->timestamp('upload_started_at')->nullable();
                $blueprint->timestamp('upload_finished_at')->nullable();
                $blueprint->unsignedBigInteger('upload_speed_bps')->nullable();
                $blueprint->timestamps();
            });

            $this->createdSourceTables[] = $table;
        }

        // The attempt history ships with a migration; run it here instead of
        // duplicating the schema, so the test table cannot drift from it.
        if (!Schema::hasTable('ingest_attempt_log')) {
            $migration = require base_path('database/migrations/2026_11_20_000002_create_ingest_attempt_log.php');
            $migration->up();

            $this->createdSourceTables[] = 'ingest_attempt_log';
        }
    }

    protected function dropSourceTables(): void
    {
        foreach ($this->createdSourceTables as $table) {
            Schema::dropIfExists($table);
        }

        $this->createdSourceTables = [];
    }
}
