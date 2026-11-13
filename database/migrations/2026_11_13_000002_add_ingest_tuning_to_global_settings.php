<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the ingest tuning that operators can change from the panel. The
 * values are mirrored onto media_relay config by RelaySettings.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'relay_worker_selection_strategy')) {
                $table->string('relay_worker_selection_strategy', 16)->default('round_robin');
            }
            if (!Schema::hasColumn('global_settings', 'relay_ingest_max_workers')) {
                $table->unsignedSmallInteger('relay_ingest_max_workers')->default(0);
            }
            if (!Schema::hasColumn('global_settings', 'relay_ingest_max_pending_chunks')) {
                $table->unsignedInteger('relay_ingest_max_pending_chunks')->default(0);
            }
            if (!Schema::hasColumn('global_settings', 'relay_ingest_chunk_mb')) {
                $table->unsignedSmallInteger('relay_ingest_chunk_mb')->default(8);
            }
            if (!Schema::hasColumn('global_settings', 'relay_ingest_streams')) {
                $table->unsignedSmallInteger('relay_ingest_streams')->default(2);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            foreach ([
                'relay_worker_selection_strategy',
                'relay_ingest_max_workers',
                'relay_ingest_max_pending_chunks',
                'relay_ingest_chunk_mb',
                'relay_ingest_streams',
            ] as $column) {
                if (Schema::hasColumn('global_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
