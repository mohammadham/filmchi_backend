<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'relay_enabled')) {
                $table->boolean('relay_enabled')->default(true);
            }
            if (!Schema::hasColumn('global_settings', 'relay_tg_base_url')) {
                $table->string('relay_tg_base_url')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_tg_username')) {
                $table->string('relay_tg_username')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_tg_password')) {
                $table->string('relay_tg_password')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_tg_api_key')) {
                $table->string('relay_tg_api_key')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_tg_storage_chat')) {
                $table->string('relay_tg_storage_chat')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_nine_base_url')) {
                $table->string('relay_nine_base_url')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_nine_api_key')) {
                $table->string('relay_nine_api_key')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_nine_email')) {
                $table->string('relay_nine_email')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_nine_password')) {
                $table->string('relay_nine_password')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_nine_folder_id')) {
                $table->string('relay_nine_folder_id')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_nine_frontend_url')) {
                $table->string('relay_nine_frontend_url')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_nine_serve_mode')) {
                $table->string('relay_nine_serve_mode', 16)->default('auto');
            }
            if (!Schema::hasColumn('global_settings', 'relay_promote_threshold')) {
                $table->unsignedInteger('relay_promote_threshold')->default(3);
            }
            if (!Schema::hasColumn('global_settings', 'relay_demand_window')) {
                $table->unsignedInteger('relay_demand_window')->default(86400);
            }
        });
    }

    public function down()
    {
        Schema::table('global_settings', function (Blueprint $table) {
            foreach ([
                'relay_enabled',
                'relay_tg_base_url',
                'relay_tg_username',
                'relay_tg_password',
                'relay_tg_api_key',
                'relay_tg_storage_chat',
                'relay_nine_base_url',
                'relay_nine_api_key',
                'relay_nine_email',
                'relay_nine_password',
                'relay_nine_folder_id',
                'relay_nine_frontend_url',
                'relay_nine_serve_mode',
                'relay_promote_threshold',
                'relay_demand_window',
            ] as $column) {
                if (Schema::hasColumn('global_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
