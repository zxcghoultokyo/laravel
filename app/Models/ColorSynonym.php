<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ColorSynonym extends Model
{
    protected $table = 'color_synonyms';

    protected $fillable = [
        'phrase',            // "олива", "olive", "blk"...
        'color_normalized',  // "оливковий", "чорний", "койот", ...
        'language',
        'domain',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
