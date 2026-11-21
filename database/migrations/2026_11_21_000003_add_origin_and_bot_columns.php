<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a relay file came from, and what the bot needs to know about it later.
 *
 *  - `relay_files.origin` separates panel uploads, link ingests and bot
 *    submissions, so the library can filter and audit them.
 *  - `tg_unique_id` is Telegram's file_unique_id: the same file sent twice
 *    produces a new message and a new file_id but the same unique id, which is
 *    the dedup key that saves a second copyMessage and a second row.
 *  - `bot_user_id` records which allowlisted user submitted it.
 *
 * `bot_submissions` also gains the document metadata: the queued job reads it
 * back from the row instead of carrying a fat payload, so retries and the panel
 * see the same file name and size the user sent.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('relay_files')) {
            Schema::table('relay_files', function (Blueprint $table) {
                if (!Schema::hasColumn('relay_files', 'origin')) {
                    $table->string('origin', 16)->default('upload')->index();
                }

                if (!Schema::hasColumn('relay_files', 'tg_unique_id')) {
                    // Unique: two workers importing the same file at once must
                    // not leave two rows (and two public links) behind.
                    $table->string('tg_unique_id', 64)->nullable()->unique();
                }

                if (!Schema::hasColumn('relay_files', 'bot_user_id')) {
                    $table->bigInteger('bot_user_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('bot_submissions')) {
            Schema::table('bot_submissions', function (Blueprint $table) {
                if (!Schema::hasColumn('bot_submissions', 'name')) {
                    $table->string('name', 191)->nullable();
                }

                if (!Schema::hasColumn('bot_submissions', 'size')) {
                    $table->unsignedBigInteger('size')->default(0);
                }

                if (!Schema::hasColumn('bot_submissions', 'mime')) {
                    $table->string('mime', 191)->nullable();
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('bot_submissions')) {
            Schema::table('bot_submissions', function (Blueprint $table) {
                $drop = array_values(array_filter(
                    ['name', 'size', 'mime'],
                    static fn (string $column): bool => Schema::hasColumn('bot_submissions', $column)
                ));

                if ($drop) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('relay_files')) {
            Schema::table('relay_files', function (Blueprint $table) {
                if (Schema::hasColumn('relay_files', 'tg_unique_id')) {
                    $table->dropUnique(['tg_unique_id']);
                }

                $drop = array_values(array_filter(
                    ['origin', 'tg_unique_id', 'bot_user_id'],
                    static fn (string $column): bool => Schema::hasColumn('relay_files', $column)
                ));

                if ($drop) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
