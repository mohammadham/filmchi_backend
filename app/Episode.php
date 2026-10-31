<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Episode extends Model
{
    use HasFactory;
    protected $table = 'episodes';

    public function sources()
    {
        return $this->hasMany(EpisodeSource::class, 'episode_id', 'id');
    }

    /**
     * Sources as the app should see them: dead links hidden and pre-ingest
     * external URLs replaced by their completed relay copy.
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
                $q->whereNull('ingest_status')
                    ->orWhere('ingest_status', '!=', 'completed')
                    ->orWhere('source', 'LIKE', '%' . url('/') . '%');
            });
    }

    public function subtitles()
    {
        return $this->hasMany(EpisodeSubtitle::class, 'episode_id', 'id');
    }
    
    
}
