<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSynonym extends Model
{
    protected $table = 'product_synonyms';

    protected $fillable = [
        'product_type',
        'synonym',
        'language',
        'weight',
        'domain',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'weight' => 'integer',
    ];
}
