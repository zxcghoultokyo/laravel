<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSynonym extends Model
{
    protected $table = 'product_synonyms';

    protected $fillable = [
        'phrase',        // базова фраза (те, що ввів юзер)
        'synonyms',      // JSON масив синонімів
        'language',
        'weight',
        'domain',
        'is_active',
    ];

    protected $casts = [
        'synonyms'  => 'array',
        'is_active' => 'boolean',
        'weight'    => 'float',
    ];
}
