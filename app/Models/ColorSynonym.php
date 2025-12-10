<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ColorSynonym extends Model
{
    protected $table = 'color_synonyms';

    protected $fillable = [
        'color_group',
        'synonym',
        'language',
        'is_primary',
        'domain',
        'is_active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active'  => 'boolean',
    ];
}
