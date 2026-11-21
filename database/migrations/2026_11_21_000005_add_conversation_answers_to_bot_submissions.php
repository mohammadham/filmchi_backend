<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation's two answers. They do not change the stored bytes — the
 * bot adopts the document exactly as Telegram holds it — so they live next to
 * the submission, where the panel can read them.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('bot_submissions') && !Schema::hasColumn('bot_submissions', 'quality')) {
            Schema::table('bot_submissions', function (Blueprint $table) {
                $table->string('quality', 16)->nullable();
            });
        }

        if (Schema::hasTable('bot_submissions') && !Schema::hasColumn('bot_submissions', 'is_series')) {
            Schema::table('bot_submissions', function (Blueprint $table) {
                $table->boolean('is_series')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('bot_submissions') && Schema::hasColumn('bot_submissions', 'is_series')) {
            Schema::table('bot_submissions', function (Blueprint $table) {
                $table->dropColumn('is_series');
            });
        }

        if (Schema::hasTable('bot_submissions') && Schema::hasColumn('bot_submissions', 'quality')) {
            Schema::table('bot_submissions', function (Blueprint $table) {
                $table->dropColumn('quality');
            });
        }
    }
};
