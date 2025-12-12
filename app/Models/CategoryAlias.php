<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryAlias extends Model
{
    protected $table = 'category_aliases';

    protected $fillable = [
        'category_id',
        'phrase',
        'phrase_norm',
        'weight',
        'source',
        'is_active',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'weight'      => 'integer',
        'is_active'   => 'boolean',
    ];
}
