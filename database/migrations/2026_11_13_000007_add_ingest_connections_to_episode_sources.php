<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actual concurrent slots used by the current download.
 *
 * This is not the configured ceiling: it is the number of curl handles the
 * job really opened after pool size, streams and file size were considered.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('episode_sources')) {
            return;
        }

        Schema::table('episode_sources', function (Blueprint $table) {
            if (!Schema::hasColumn('episode_sources', 'ingest_connections')) {
                $table->unsignedSmallInteger('ingest_connections')->nullable();
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('episode_sources')) {
            return;
        }

        Schema::table('episode_sources', function (Blueprint $table) {
            if (Schema::hasColumn('episode_sources', 'ingest_connections')) {
                $table->dropColumn('ingest_connections');
            }
        });
    }
};
