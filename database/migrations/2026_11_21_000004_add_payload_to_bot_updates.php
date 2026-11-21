<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bot import job works from the update itself, so the webhook keeps the
 * payload next to the dedup key. A few KB per update, and no second request to
 * Telegram to fetch a file the user already sent.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('bot_updates') && !Schema::hasColumn('bot_updates', 'payload')) {
            Schema::table('bot_updates', function (Blueprint $table) {
                $table->longText('payload')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('bot_updates') && Schema::hasColumn('bot_updates', 'payload')) {
            Schema::table('bot_updates', function (Blueprint $table) {
                $table->dropColumn('payload');
            });
        }
    }
};
