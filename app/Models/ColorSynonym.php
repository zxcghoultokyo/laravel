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
        'domain',
        'is_primary',
        'is_active',
    ];
}
