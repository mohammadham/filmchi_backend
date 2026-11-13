<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Number of ingest files that may be downloaded/uploaded at the same time.
 *
 * Kept at 1 by default: the previous worker ran one queue job at a time, and
 * multiple simultaneous 48-connection pulls are an opt-in trade-off between
 * aggregate throughput and per-file speed.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'relay_ingest_concurrency')) {
                $table->unsignedTinyInteger('relay_ingest_concurrency')->default(1);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (Schema::hasColumn('global_settings', 'relay_ingest_concurrency')) {
                $table->dropColumn('relay_ingest_concurrency');
            }
        });
    }
};
