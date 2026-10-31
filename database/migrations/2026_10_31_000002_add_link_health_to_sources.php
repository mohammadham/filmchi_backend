<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLinkHealthToSources extends Migration
{
    protected $tables = ['content_sources', 'episode_sources'];

    public function up()
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (!Schema::hasColumn($table, 'link_status')) {
                    $blueprint->string('link_status', 16)->nullable()->comment('null=unchecked, alive, dead');
                }

                if (!Schema::hasColumn($table, 'link_checked_at')) {
                    $blueprint->timestamp('link_checked_at')->nullable();
                }

                if (!Schema::hasColumn($table, 'link_fail_count')) {
                    $blueprint->unsignedInteger('link_fail_count')->default(0);
                }
            });
        }
    }

    public function down()
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $drop = array_intersect(['link_status', 'link_checked_at', 'link_fail_count'], Schema::getColumnListing($table));

                if ($drop) {
                    $blueprint->dropColumn($drop);
                }
            });
        }
    }
}
