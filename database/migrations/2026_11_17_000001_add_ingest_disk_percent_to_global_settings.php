<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disk-based ingest admission: use at most this percentage of the free disk
 * for files that are downloading or waiting to upload. 0 keeps the old fixed
 * concurrency behaviour.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'relay_ingest_disk_percent')) {
                $table->unsignedTinyInteger('relay_ingest_disk_percent')->default(50);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (Schema::hasColumn('global_settings', 'relay_ingest_disk_percent')) {
                $table->dropColumn('relay_ingest_disk_percent');
            }
        });
    }
};
