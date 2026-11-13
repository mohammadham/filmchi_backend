<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class EpisodeSource extends Model
{
    use HasFactory;
    protected $table = 'episode_sources';

    protected $casts = [
        'upload_started_at' => 'datetime',
        'upload_finished_at' => 'datetime',
        'upload_bytes' => 'integer',
        'upload_duration_ms' => 'integer',
        'upload_speed_bps' => 'integer',
        'upload_attempts' => 'integer',
        'ingest_bytes' => 'integer',
        'ingest_speed_bps' => 'integer',
        'ingest_started_at' => 'datetime',
    ];

    public function media()
    {
        return $this->hasOne(MediaGallery::class, 'id', 'source');
    }

    public function relayFile()
    {
        return $this->belongsTo(RelayFile::class, 'relay_file_id');
    }

}
