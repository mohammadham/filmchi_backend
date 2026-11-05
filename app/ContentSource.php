<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentSource extends Model
{
    use HasFactory;
    protected $table = 'content_sources';

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
