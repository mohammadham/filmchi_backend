<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel-editable retry policy and the tuning of the newer chunk/speed
 * strategies. These keys already existed in config/media_relay.php but had no
 * column, so they could only be changed by editing .env and restarting.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    protected $columns = [
        'relay_ingest_retry_max_attempts' => 'unsignedTinyInteger',
        'relay_ingest_retry_delay' => 'unsignedSmallInteger',
        'relay_upload_retry_max_attempts' => 'unsignedTinyInteger',
        'relay_upload_retry_delay' => 'unsignedSmallInteger',
        'relay_ingest_chunk_retry_budget' => 'unsignedSmallInteger',
        'relay_ingest_chunk_stall_timeout' => 'unsignedSmallInteger',
        'relay_ingest_speed_check_seconds' => 'speed_seconds',
        'relay_ingest_speed_window_samples' => 'unsignedTinyInteger',
        'relay_ingest_speed_drop_ratio' => 'speed_ratio',
        'relay_ingest_speed_drop_confirmations' => 'unsignedTinyInteger',
        'relay_ingest_speed_reprobe_chunks' => 'unsignedTinyInteger',
        'relay_ingest_speed_reprobe_cooldown' => 'unsignedSmallInteger',
        'relay_ingest_stall_timeout' => 'unsignedSmallInteger',
        'relay_ingest_stream_min_kbps' => 'unsignedSmallInteger',
        'relay_ingest_stream_stall_seconds' => 'unsignedSmallInteger',
        'relay_ingest_pool_refresh_seconds' => 'unsignedTinyInteger',
        'relay_ingest_timeout' => 'unsignedSmallInteger',
        'relay_ingest_min_chunk_mb' => 'unsignedTinyInteger',
        'relay_ingest_max_chunk_mb' => 'unsignedTinyInteger',
        'relay_worker_probe_concurrency' => 'unsignedTinyInteger',
    ];

    public function up()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            $defaults = [
                'relay_ingest_retry_max_attempts' => 3,
                'relay_ingest_retry_delay' => 300,
                'relay_upload_retry_max_attempts' => 3,
                'relay_upload_retry_delay' => 120,
                'relay_ingest_chunk_retry_budget' => 180,
                'relay_ingest_chunk_stall_timeout' => 60,
                'relay_ingest_speed_check_seconds' => 5,
                'relay_ingest_speed_window_samples' => 6,
                'relay_ingest_speed_drop_ratio' => 0.70,
                'relay_ingest_speed_drop_confirmations' => 2,
                'relay_ingest_speed_reprobe_chunks' => 2,
                'relay_ingest_speed_reprobe_cooldown' => 30,
                'relay_ingest_stall_timeout' => 300,
                'relay_ingest_stream_min_kbps' => 64,
                'relay_ingest_stream_stall_seconds' => 180,
                'relay_ingest_pool_refresh_seconds' => 10,
                'relay_ingest_timeout' => 3600,
                'relay_ingest_min_chunk_mb' => 1,
                'relay_ingest_max_chunk_mb' => 16,
                'relay_worker_probe_concurrency' => 1,
            ];

            foreach ($this->columns as $column => $type) {
                if (Schema::hasColumn('global_settings', $column)) {
                    continue;
                }

                if ($type === 'speed_seconds') {
                    $table->decimal($column, 5, 2)->default($defaults[$column]);
                } elseif ($type === 'speed_ratio') {
                    $table->decimal($column, 4, 2)->default($defaults[$column]);
                } else {
                    $table->{$type}($column)->default($defaults[$column]);
                }
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        Schema::table('global_settings', function (Blueprint $table) {
            foreach (array_keys($this->columns) as $column) {
                if (Schema::hasColumn('global_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
