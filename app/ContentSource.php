<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentSource extends Model
{
    use HasFactory;
    protected $table = 'content_sources';

    protected $casts = [
        'upload_started_at' => 'datetime',
        'upload_finished_at' => 'datetime',
        'upload_bytes' => 'integer',
        'upload_duration_ms' => 'integer',
        'upload_speed_bps' => 'integer',
        'upload_attempts' => 'integer',
    ];

    public function media()
    {
        return $this->hasOne(MediaGallery::class, 'id', 'source');
    }

    /**
     * Set when this source was uploaded through the relay; kept in sync with
     * media_gallery so the panel can show where the bytes really live.
     */
    public function relayFile()
    {
        return $this->belongsTo(RelayFile::class, 'relay_file_id');
    }
}
