<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live download telemetry for the panel.
 *
 * The queue worker writes these fields while it downloads, so the settings
 * page can show file name, progress and speed without guessing from rounded
 * percentages or parsing logs.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('episode_sources')) {
            return;
        }

        Schema::table('episode_sources', function (Blueprint $table) {
            if (!Schema::hasColumn('episode_sources', 'ingest_bytes')) {
                $table->unsignedBigInteger('ingest_bytes')->nullable();
            }
            if (!Schema::hasColumn('episode_sources', 'ingest_speed_bps')) {
                $table->unsignedBigInteger('ingest_speed_bps')->nullable();
            }
            if (!Schema::hasColumn('episode_sources', 'ingest_started_at')) {
                $table->timestamp('ingest_started_at')->nullable();
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('episode_sources')) {
            return;
        }

        Schema::table('episode_sources', function (Blueprint $table) {
            foreach (['ingest_bytes', 'ingest_speed_bps', 'ingest_started_at'] as $column) {
                if (Schema::hasColumn('episode_sources', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
