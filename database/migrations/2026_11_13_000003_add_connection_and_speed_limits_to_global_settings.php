<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel-editable connection width and Worker admission thresholds.
 *
 * The 48-connection default preserves the tuned production value; the speed
 * threshold defaults to 0 (disabled) so a fresh install keeps every Worker
 * eligible until an operator chooses a floor.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'relay_ingest_connections')) {
                $table->unsignedSmallInteger('relay_ingest_connections')->default(48);
            }
            if (!Schema::hasColumn('global_settings', 'relay_worker_min_speed_kbps')) {
                $table->unsignedInteger('relay_worker_min_speed_kbps')->default(0);
            }
            if (!Schema::hasColumn('global_settings', 'relay_worker_max_latency_ms')) {
                $table->unsignedInteger('relay_worker_max_latency_ms')->default(0);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            foreach (['relay_ingest_connections', 'relay_worker_min_speed_kbps', 'relay_worker_max_latency_ms'] as $column) {
                if (Schema::hasColumn('global_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
