<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per finished ingest attempt, kept apart from the counters on
 * episode_sources/content_sources.
 *
 * Those counters have to be reset for a requeue to get a fresh retry budget,
 * which also erased what the row had already been through: how many attempts
 * it burned, on which Workers, and why each one failed. The history table holds
 * that permanently while the counters stay disposable.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('ingest_attempt_log')) {
            return;
        }

        Schema::create('ingest_attempt_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');
            // The source's own attempt counter at the time, so a requeue that
            // restarts at 1 is still readable in order.
            $table->unsignedSmallInteger('attempt_no')->default(0);
            // downloaded, retrying, failed, deferred, quarantined, requeued
            $table->string('outcome', 24);
            $table->unsignedBigInteger('bytes')->default(0);
            // Worker ids that served the attempt ("11,12") or null for direct.
            $table->string('route', 191)->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id', 'id'], 'ingest_attempt_log_source_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ingest_attempt_log');
    }
};
