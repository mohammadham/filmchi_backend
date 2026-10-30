<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddThumbToRelayFiles extends Migration
{
    public function up()
    {
        if (Schema::hasTable('relay_files') && !Schema::hasColumn('relay_files', 'thumb')) {
            Schema::table('relay_files', function (Blueprint $table) {
                $table->string('thumb')->nullable()->after('extension');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('relay_files') && Schema::hasColumn('relay_files', 'thumb')) {
            Schema::table('relay_files', function (Blueprint $table) {
                $table->dropColumn('thumb');
            });
        }
    }
}
