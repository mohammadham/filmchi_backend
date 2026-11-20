<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State for the Telegram bot: one row per conversation, one per received
 * update (the webhook dedup key) and one per submitted file.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('bot_sessions')) {
            Schema::create('bot_sessions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('telegram_user_id');
                $table->bigInteger('chat_id');
                $table->string('state', 32)->default('idle');
                $table->json('payload')->nullable();
                $table->dateTime('expires_at');
                $table->timestamps();
                $table->unique(['telegram_user_id', 'chat_id']);
                $table->index('expires_at');
            });
        }

        if (!Schema::hasTable('bot_updates')) {
            Schema::create('bot_updates', function (Blueprint $table) {
                // Telegram's update_id is the natural dedup key: a retried
                // webhook delivery must never start the flow twice.
                $table->bigInteger('update_id')->primary();
                $table->string('kind', 24)->nullable();
                $table->dateTime('received_at');
            });
        }

        if (!Schema::hasTable('bot_submissions')) {
            Schema::create('bot_submissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->bigInteger('telegram_user_id');
                $table->bigInteger('chat_id');
                $table->bigInteger('message_id');
                $table->string('file_unique_id', 64);
                $table->bigInteger('copy_message_id')->nullable();
                $table->unsignedBigInteger('relay_file_id')->nullable();
                $table->string('status', 16)->default('received');
                $table->text('error')->nullable();
                $table->timestamps();
                $table->unique(['chat_id', 'message_id']);
                $table->index('file_unique_id');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('bot_submissions');
        Schema::dropIfExists('bot_updates');
        Schema::dropIfExists('bot_sessions');
    }
};
