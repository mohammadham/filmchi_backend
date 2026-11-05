<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-title storage folders and catalog metadata:
 *
 *  - `contents.storage_folder` is the folder an anime's files are kept in on
 *    both storage tiers (Telegram Drive and the 9drive cache), so a series
 *    does not end up as a flat pile of episodes in the root.
 *  - `relay_files.folder` remembers that folder on the stored file, so a
 *    later cache promotion lands in the same folder.
 *  - `contents.tags` keeps the source site's tags (keywords) alongside the
 *    genres, which the panel had nowhere to put.
 */
class AddStorageFoldersAndTags extends Migration
{
    public function up()
    {
        if (Schema::hasTable('contents')) {
            Schema::table('contents', function (Blueprint $table) {
                if (!Schema::hasColumn('contents', 'tags')) {
                    $table->text('tags')->nullable()->after('release_year');
                }

                if (!Schema::hasColumn('contents', 'storage_folder')) {
                    $table->string('storage_folder', 191)->nullable()->after('tags');
                }
            });
        }

        if (Schema::hasTable('relay_files')) {
            Schema::table('relay_files', function (Blueprint $table) {
                if (!Schema::hasColumn('relay_files', 'folder')) {
                    $table->string('folder', 191)->nullable()->after('extension');
                    $table->index('folder');
                }

                if (!Schema::hasColumn('relay_files', 'nine_folder_id')) {
                    $table->string('nine_folder_id', 64)->nullable()->after('folder');
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('relay_files')) {
            Schema::table('relay_files', function (Blueprint $table) {
                if (Schema::hasColumn('relay_files', 'nine_folder_id')) {
                    $table->dropColumn('nine_folder_id');
                }

                if (Schema::hasColumn('relay_files', 'folder')) {
                    $table->dropIndex(['folder']);
                    $table->dropColumn('folder');
                }
            });
        }

        if (Schema::hasTable('contents')) {
            Schema::table('contents', function (Blueprint $table) {
                if (Schema::hasColumn('contents', 'storage_folder')) {
                    $table->dropColumn('storage_folder');
                }

                if (Schema::hasColumn('contents', 'tags')) {
                    $table->dropColumn('tags');
                }
            });
        }
    }
}
