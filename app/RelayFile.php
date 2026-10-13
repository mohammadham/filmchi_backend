<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RelayFile extends Model
{
    use HasFactory;

    protected $table = 'relay_files';

    protected $casts = [
        'tg_parts' => 'array',
        'is_blocked' => 'boolean',
        'promoted_at' => 'datetime',
        'cache_checked_at' => 'datetime',
        'last_access_at' => 'datetime',
    ];

    const COLD = 'cold';
    const QUEUED = 'queued';
    const PROMOTING = 'promoting';
    const WARM = 'warm';
    const FAILED = 'failed';

    public function links()
    {
        return $this->hasMany(RelayLink::class, 'relay_file_id');
    }

    public function events()
    {
        return $this->hasMany(RelayEvent::class, 'relay_file_id');
    }

    public function getIsCachedAttribute()
    {
        return $this->cache_state === self::WARM && !empty($this->nine_share_url);
    }

    public function getHumanSizeAttribute()
    {
        $bytes = (int) $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        foreach ($units as $index => $unit) {
            if ($bytes < 1024 || $index === count($units) - 1) {
                return $index === 0 ? $bytes . ' B' : round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return $bytes . ' B';
    }
}
