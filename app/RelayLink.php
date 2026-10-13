<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RelayLink extends Model
{
    use HasFactory;

    protected $table = 'relay_links';

    protected $casts = [
        'is_disabled' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    const MODE_STREAM = 'stream';
    const MODE_DOWNLOAD = 'download';

    public function file()
    {
        return $this->belongsTo(RelayFile::class, 'relay_file_id');
    }

    /**
     * A link is usable when it is enabled, not expired and under its hit cap.
     */
    public function isUsable()
    {
        if ($this->is_disabled) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_downloads > 0 && $this->hits >= $this->max_downloads) {
            return false;
        }

        return true;
    }

    public function streamUrl()
    {
        return url(str_replace('{token}', $this->token, config('media_relay.relay.stream_path')));
    }

    public function downloadUrl()
    {
        return url(str_replace('{token}', $this->token, config('media_relay.relay.download_path')));
    }
}
