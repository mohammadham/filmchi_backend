<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RelayEvent extends Model
{
    use HasFactory;

    protected $table = 'relay_events';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    const DEMAND = 'demand';
    const STREAM = 'stream';
    const DOWNLOAD = 'download';
    const PROMOTE_REQUESTED = 'promote_requested';
    const PROMOTED = 'promoted';
    const PROMOTE_FAILED = 'promote_failed';

    public function file()
    {
        return $this->belongsTo(RelayFile::class, 'relay_file_id');
    }
}
