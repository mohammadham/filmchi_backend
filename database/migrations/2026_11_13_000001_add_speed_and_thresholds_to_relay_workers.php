<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The registry records real throughput and per-Worker admission thresholds,
 * but the original table only had latency/health fields. Keep this migration
 * idempotent because production may already have some of these columns.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('relay_workers')) {
            return;
        }

        Schema::table('relay_workers', function (Blueprint $table) {
            if (!Schema::hasColumn('relay_workers', 'last_speed_bps')) {
                $table->unsignedBigInteger('last_speed_bps')->nullable();
            }
            if (!Schema::hasColumn('relay_workers', 'last_speed_at')) {
                $table->timestamp('last_speed_at')->nullable();
            }
            if (!Schema::hasColumn('relay_workers', 'success_count')) {
                $table->unsignedInteger('success_count')->default(0);
            }
            if (!Schema::hasColumn('relay_workers', 'min_speed_bps')) {
                $table->unsignedInteger('min_speed_bps')->nullable();
            }
            if (!Schema::hasColumn('relay_workers', 'max_latency_ms')) {
                $table->unsignedInteger('max_latency_ms')->nullable();
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('relay_workers')) {
            return;
        }

        Schema::table('relay_workers', function (Blueprint $table) {
            foreach (['min_speed_bps', 'max_latency_ms', 'success_count', 'last_speed_at', 'last_speed_bps'] as $column) {
                if (Schema::hasColumn('relay_workers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
