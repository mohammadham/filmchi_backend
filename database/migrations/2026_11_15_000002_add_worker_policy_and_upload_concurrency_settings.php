<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel-editable Worker health policy and the missing upload concurrency.
 *
 * The download concurrency column already exists; upload concurrency was only
 * an .env value, which made it impossible to tune from the settings page.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('global_settings', 'relay_worker_auto_disable')) {
                $table->boolean('relay_worker_auto_disable')->default(true);
            }
            if (!Schema::hasColumn('global_settings', 'relay_worker_failure_threshold')) {
                $table->unsignedTinyInteger('relay_worker_failure_threshold')->default(3);
            }
            if (!Schema::hasColumn('global_settings', 'relay_worker_failure_window')) {
                $table->unsignedSmallInteger('relay_worker_failure_window')->default(600);
            }
            if (!Schema::hasColumn('global_settings', 'relay_worker_backoff_base')) {
                $table->unsignedSmallInteger('relay_worker_backoff_base')->default(60);
            }
            if (!Schema::hasColumn('global_settings', 'relay_worker_backoff_max')) {
                $table->unsignedSmallInteger('relay_worker_backoff_max')->default(3600);
            }
            if (!Schema::hasColumn('global_settings', 'relay_worker_probe_success_threshold')) {
                $table->unsignedTinyInteger('relay_worker_probe_success_threshold')->default(2);
            }
            if (!Schema::hasColumn('global_settings', 'relay_worker_probe_interval')) {
                $table->unsignedSmallInteger('relay_worker_probe_interval')->default(60);
            }
            if (!Schema::hasColumn('global_settings', 'relay_upload_concurrency')) {
                $table->unsignedTinyInteger('relay_upload_concurrency')->default(2);
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
                'relay_worker_auto_disable',
                'relay_worker_failure_threshold',
                'relay_worker_failure_window',
                'relay_worker_backoff_base',
                'relay_worker_backoff_max',
                'relay_worker_probe_success_threshold',
                'relay_worker_probe_interval',
                'relay_upload_concurrency',
            ] as $column) {
                if (Schema::hasColumn('global_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
