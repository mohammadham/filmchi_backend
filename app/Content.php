<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
    use HasFactory;

    protected $table = 'contents';

    public function language()
    {
        return $this->hasOne(Language::class, 'id', 'language_id');
    }

    public function sources()
    {
        return $this->hasMany(ContentSource::class, 'content_id');
    }

    /**
     * Sources as the app should see them: dead links hidden, the rest
     * ordered by quality label when possible.
     */
    public function appSources()
    {
        return $this->sources()
            ->where(function ($q) {
                $q->whereNull('link_status')->whereNull('ingest_status')
                    ->orWhere('link_status', '!=', 'dead')
                    ->orWhere('ingest_status', '!=', 'completed');
            })
            ->where(function ($q) {
                // A source that was ingested points at the relay link now;
                // the pre-ingest external URL must not reach the app.
                $q->whereNull('ingest_status')
                    ->orWhere('ingest_status', '!=', 'completed')
                    ->orWhere('source', 'LIKE', '%' . url('/') . '%');
            });
    }

    public function casts()
    {
        return $this->hasMany(ContentCast::class, 'content_id');
    }

    public function subtitles()
    {
        return $this->hasMany(Subtitle::class, 'content_id');
    }

    public function seasons()
    {
        return $this->hasMany(Season::class, 'content_id');
    }

 
}
