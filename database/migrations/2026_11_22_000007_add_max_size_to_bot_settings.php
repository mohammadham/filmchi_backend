<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bot's per-file ceiling, next to the daily limit: a chat can send twenty
 * documents a day and one multi-gigabyte file would still eat the disk budget,
 * so the size cap is its own panel value.
 *
 * Guarded like the other bot migrations: an install that already has the column
 * (or a re-run) is a no-op.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('global_settings') || Schema::hasColumn('global_settings', 'relay_bot_max_size_mb')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            $table->unsignedInteger('relay_bot_max_size_mb')->default(2048);
        });
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings') || !Schema::hasColumn('global_settings', 'relay_bot_max_size_mb')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            $table->dropColumn('relay_bot_max_size_mb');
        });
    }
};
