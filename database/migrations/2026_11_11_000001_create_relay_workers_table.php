<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel-managed Cloudflare Worker endpoints used for source downloads.
 *
 * Workers are intentionally a separate table rather than a JSON column in
 * global_settings: each one has its own health state, cooldown and encrypted
 * API key, and the queue can rotate between them without rewriting settings.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('relay_workers')) {
            Schema::create('relay_workers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('base_url', 500);
                $table->text('api_key')->nullable();
                $table->boolean('enabled')->default(true);
                $table->unsignedSmallInteger('priority')->default(100);

                // Health/telemetry shown in the panel.
                $table->string('last_status', 16)->default('unknown');
                $table->unsignedInteger('last_latency_ms')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->text('last_error')->nullable();
                $table->unsignedSmallInteger('failure_count')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamp('last_used_at')->nullable();

                $table->timestamps();

                $table->index(['enabled', 'priority']);
                $table->index('cooldown_until');
                $table->index('last_used_at');
            });
        }

        if (Schema::hasTable('global_settings') && !Schema::hasColumn('global_settings', 'relay_worker_enabled')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->boolean('relay_worker_enabled')->default(true);
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('global_settings') && Schema::hasColumn('global_settings', 'relay_worker_enabled')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->dropColumn('relay_worker_enabled');
            });
        }

        Schema::dropIfExists('relay_workers');
    }
};
