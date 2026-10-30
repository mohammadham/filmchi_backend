<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPanelLangToGlobalSettings extends Migration
{
    public function up()
    {
        if (Schema::hasTable('global_settings') && !Schema::hasColumn('global_settings', 'panel_lang')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->string('panel_lang', 5)->default('en')->after('storage_type');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('global_settings') && Schema::hasColumn('global_settings', 'panel_lang')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->dropColumn('panel_lang');
            });
        }
    }
}
