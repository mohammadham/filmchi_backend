<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('relay_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('relay_file_id')->nullable()->index();
            $table->unsignedBigInteger('relay_link_id')->nullable()->index();

            // demand | stream | download | promote_requested | promoted | promote_failed
            $table->string('event', 32)->index();
            // cache | telegram
            $table->string('served_by', 16)->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('ip', 45)->nullable();
            $table->text('message')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('relay_events');
    }
};
