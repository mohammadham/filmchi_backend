<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Casts\EncryptedString;

class GlobalSettings extends Model
{
    use HasFactory;
    protected $table = 'global_settings';

    protected $casts = [
        'storage_type' => 'integer',
        'relay_enabled' => 'boolean',
        'relay_worker_enabled' => 'boolean',
        'relay_ingest_max_workers' => 'integer',
        'relay_ingest_max_pending_chunks' => 'integer',
        'relay_ingest_chunk_mb' => 'integer',
        'relay_ingest_streams' => 'integer',
        'relay_ingest_connections' => 'integer',
        'relay_ingest_concurrency' => 'integer',
        'relay_worker_min_speed_kbps' => 'integer',
        'relay_worker_max_latency_ms' => 'integer',
        'relay_promote_threshold' => 'integer',
        'relay_demand_window' => 'integer',
        'relay_tg_password' => EncryptedString::class,
        'relay_tg_api_key' => EncryptedString::class,
        'relay_nine_password' => EncryptedString::class,
        'relay_nine_api_key' => EncryptedString::class,
    ];

    const STORAGE_LOCAL = 0;
    const STORAGE_AWS = 1;
    const STORAGE_DO = 2;
    const STORAGE_COMBINED = 3;
}
