<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties the three media surfaces together:
 *
 *  - `provider` lets one quality appear several times (one row per mirror
 *    site), so 480p from two different hosts no longer collides.
 *  - `media_gallery.relay_file_id` points a library entry at the relay file
 *    that actually holds its bytes, so relay uploads are selectable from the
 *    movie/episode source forms.
 *  - `relay_files.ingest_*` tracks "add this link to the relay" jobs.
 */
class AddLibraryLinksAndProviderColumns extends Migration
{
    public function up()
    {
        foreach (['content_sources', 'episode_sources'] as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'provider')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('provider', 64)->nullable()->after('quality');
            });
        }

        if (Schema::hasTable('media_gallery') && !Schema::hasColumn('media_gallery', 'relay_file_id')) {
            Schema::table('media_gallery', function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('relay_file_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('relay_files')) {
            Schema::table('relay_files', function (Blueprint $blueprint) {
                if (!Schema::hasColumn('relay_files', 'ingest_status')) {
                    $blueprint->string('ingest_status', 16)->nullable();
                }

                if (!Schema::hasColumn('relay_files', 'ingest_progress')) {
                    $blueprint->unsignedSmallInteger('ingest_progress')->default(0);
                }

                if (!Schema::hasColumn('relay_files', 'ingest_error')) {
                    $blueprint->text('ingest_error')->nullable();
                }

                if (!Schema::hasColumn('relay_files', 'source_url')) {
                    $blueprint->text('source_url')->nullable();
                }
            });
        }
    }

    public function down()
    {
        foreach (['content_sources', 'episode_sources'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'provider')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('provider');
                });
            }
        }

        if (Schema::hasTable('media_gallery') && Schema::hasColumn('media_gallery', 'relay_file_id')) {
            Schema::table('media_gallery', function (Blueprint $blueprint) {
                $blueprint->dropColumn('relay_file_id');
            });
        }

        if (Schema::hasTable('relay_files')) {
            Schema::table('relay_files', function (Blueprint $blueprint) {
                $drop = array_values(array_filter(
                    ['ingest_status', 'ingest_progress', 'ingest_error', 'source_url'],
                    function ($column) {
                        return Schema::hasColumn('relay_files', $column);
                    }
                ));

                if ($drop) {
                    $blueprint->dropColumn($drop);
                }
            });
        }
    }
}
