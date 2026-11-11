<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add transfer telemetry used by the panel and the Worker selector.
 *
 * The Worker columns are measured from real ranged transfers, not from the
 * tiny sitemap health probe. The upload columns make Telegram Drive upload
 * duration and throughput visible per file, including queued ingest jobs.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('relay_workers')) {
            Schema::table('relay_workers', function (Blueprint $table) {
                if (!Schema::hasColumn('relay_workers', 'last_speed_bps')) {
                    $table->unsignedBigInteger('last_speed_bps')->nullable();
                }

                if (!Schema::hasColumn('relay_workers', 'last_speed_at')) {
                    $table->timestamp('last_speed_at')->nullable();
                }

                if (!Schema::hasColumn('relay_workers', 'success_count')) {
                    $table->unsignedBigInteger('success_count')->default(0);
                }
            });
        }

        foreach (['relay_files', 'content_sources', 'episode_sources'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'upload_status')) {
                    $table->string('upload_status', 24)->nullable();
                }

                if (!Schema::hasColumn($tableName, 'upload_started_at')) {
                    $table->timestamp('upload_started_at')->nullable();
                }

                if (!Schema::hasColumn($tableName, 'upload_finished_at')) {
                    $table->timestamp('upload_finished_at')->nullable();
                }

                if (!Schema::hasColumn($tableName, 'upload_bytes')) {
                    $table->unsignedBigInteger('upload_bytes')->default(0);
                }

                if (!Schema::hasColumn($tableName, 'upload_duration_ms')) {
                    $table->unsignedBigInteger('upload_duration_ms')->nullable();
                }

                if (!Schema::hasColumn($tableName, 'upload_speed_bps')) {
                    $table->unsignedBigInteger('upload_speed_bps')->nullable();
                }

                if (!Schema::hasColumn($tableName, 'upload_attempts')) {
                    $table->unsignedSmallInteger('upload_attempts')->default(0);
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('relay_workers')) {
            Schema::table('relay_workers', function (Blueprint $table) {
                $columns = array_values(array_filter(
                    ['last_speed_bps', 'last_speed_at', 'success_count'],
                    fn ($column) => Schema::hasColumn('relay_workers', $column)
                ));

                if ($columns) {
                    $table->dropColumn($columns);
                }
            });
        }

        foreach (['relay_files', 'content_sources', 'episode_sources'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $columns = array_values(array_filter(
                    ['upload_status', 'upload_started_at', 'upload_finished_at', 'upload_bytes', 'upload_duration_ms', 'upload_speed_bps', 'upload_attempts'],
                    fn ($column) => Schema::hasColumn($tableName, $column)
                ));

                if ($columns) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
