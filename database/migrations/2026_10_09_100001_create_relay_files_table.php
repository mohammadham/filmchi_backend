<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('relay_files', function (Blueprint $table) {
            $table->id();

            // File identity
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime')->nullable();
            $table->string('extension', 32)->nullable();
            $table->string('checksum', 64)->nullable();

            // Primary storage: Telegram Drive
            $table->string('tg_file_id')->nullable()->index();
            $table->string('tg_backend', 32)->default('telegram');
            $table->string('tg_storage_chat')->nullable();
            $table->json('tg_parts')->nullable();

            // Cache tier: 9drive (Google Drive)
            $table->string('nine_file_id')->nullable()->index();
            $table->string('nine_share_token')->nullable();
            $table->string('nine_share_url')->nullable();
            $table->text('nine_preview_url')->nullable();
            $table->string('nine_account', 190)->nullable();
            $table->string('nine_provider_file_id')->nullable();

            // cold | queued | promoting | warm | failed
            $table->string('cache_state', 16)->default('cold')->index();
            $table->text('cache_error')->nullable();
            $table->unsignedInteger('cache_attempts')->default(0);
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('cache_checked_at')->nullable();

            // Usage accounting
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('bytes_served')->default(0);
            $table->timestamp('last_access_at')->nullable()->index();

            $table->boolean('is_blocked')->default(false)->index();
            $table->string('owner')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['cache_state', 'last_access_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('relay_files');
    }
};
