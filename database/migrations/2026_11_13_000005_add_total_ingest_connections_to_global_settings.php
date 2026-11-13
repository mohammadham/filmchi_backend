<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregate connection ceiling for all concurrent ingest jobs.
 *
 * 0 keeps the old behaviour (per-file connections × concurrency). A positive
 * value is divided by the current concurrency, so raising concurrency cannot
 * multiply the socket count.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'relay_ingest_total_connections')) {
                $table->unsignedSmallInteger('relay_ingest_total_connections')->default(0);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (Schema::hasColumn('global_settings', 'relay_ingest_total_connections')) {
                $table->dropColumn('relay_ingest_total_connections');
            }
        });
    }
};
