<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlobalSettings extends Model
{
    use HasFactory;
    protected $table = 'global_settings';

    protected $casts = [
        'storage_type' => 'integer',
        'relay_enabled' => 'boolean',
        'relay_promote_threshold' => 'integer',
        'relay_demand_window' => 'integer',
    ];

    const STORAGE_LOCAL = 0;
    const STORAGE_AWS = 1;
    const STORAGE_DO = 2;
    const STORAGE_COMBINED = 3;
}
