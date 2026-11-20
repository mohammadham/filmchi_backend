<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel-editable settings for the Telegram bot.
 *
 * Defaults keep the feature inert (enabled=false, no ids, no channel), so the
 * row that already exists in a live install needs no manual edit.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'relay_bot_enabled')) {
                $table->boolean('relay_bot_enabled')->default(false);
            }
            if (!Schema::hasColumn('global_settings', 'relay_bot_token')) {
                $table->text('relay_bot_token')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_bot_webhook_secret')) {
                $table->text('relay_bot_webhook_secret')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_bot_allowed_ids')) {
                $table->text('relay_bot_allowed_ids')->nullable();
            }
            if (!Schema::hasColumn('global_settings', 'relay_bot_storage_chat')) {
                $table->string('relay_bot_storage_chat', 255)->default('');
            }
            if (!Schema::hasColumn('global_settings', 'relay_bot_channel_id')) {
                $table->unsignedInteger('relay_bot_channel_id')->default(0);
            }
            if (!Schema::hasColumn('global_settings', 'relay_bot_quality')) {
                $table->string('relay_bot_quality', 16)->default('1080');
            }
            if (!Schema::hasColumn('global_settings', 'relay_bot_daily_limit')) {
                $table->unsignedInteger('relay_bot_daily_limit')->default(20);
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            foreach ([
                'relay_bot_enabled', 'relay_bot_token', 'relay_bot_webhook_secret',
                'relay_bot_allowed_ids', 'relay_bot_storage_chat', 'relay_bot_channel_id',
                'relay_bot_quality', 'relay_bot_daily_limit',
            ] as $column) {
                if (Schema::hasColumn('global_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
