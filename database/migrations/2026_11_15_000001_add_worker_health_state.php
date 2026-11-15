<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate a Worker's operator switch from its observed health.
 *
 * enabled          = the operator allows this Worker to be used at all
 * last_status      = result of the last transfer/probe
 * auto_disabled_at = the health policy parked this Worker
 * next_probe_at    = when the background prober may try it again
 *
 * Keeping these fields separate is what makes the panel able to show
 * "auto-disabled" without silently changing the operator's enabled choice.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('relay_workers')) {
            return;
        }

        Schema::table('relay_workers', function (Blueprint $table) {
            if (!Schema::hasColumn('relay_workers', 'auto_disabled_at')) {
                $table->timestamp('auto_disabled_at')->nullable()->after('cooldown_until');
            }
            if (!Schema::hasColumn('relay_workers', 'disabled_reason')) {
                $table->string('disabled_reason', 255)->nullable()->after('auto_disabled_at');
            }
            if (!Schema::hasColumn('relay_workers', 'last_failure_at')) {
                $table->timestamp('last_failure_at')->nullable()->after('failure_count');
            }
            if (!Schema::hasColumn('relay_workers', 'probe_success_count')) {
                $table->unsignedTinyInteger('probe_success_count')->default(0)->after('disabled_reason');
            }
            if (!Schema::hasColumn('relay_workers', 'next_probe_at')) {
                $table->timestamp('next_probe_at')->nullable()->after('probe_success_count');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('relay_workers')) {
            return;
        }

        Schema::table('relay_workers', function (Blueprint $table) {
            foreach (['next_probe_at', 'probe_success_count', 'last_failure_at', 'disabled_reason', 'auto_disabled_at'] as $column) {
                if (Schema::hasColumn('relay_workers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
