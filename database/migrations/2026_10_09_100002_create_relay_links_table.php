<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('relay_links', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('relay_file_id')->index();
            $table->string('token', 64)->unique();

            // stream = inline playback, download = attachment
            $table->string('mode', 16)->default('stream');
            $table->string('label')->nullable();

            $table->string('password_hash')->nullable();
            $table->unsignedInteger('max_downloads')->default(0);
            $table->unsignedBigInteger('hits')->default(0);
            $table->boolean('is_disabled')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('relay_links');
    }
};
