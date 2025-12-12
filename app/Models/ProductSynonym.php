<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSynonym extends Model
{
    protected $table = 'product_synonyms';

    protected $fillable = [
        // Реляційна схема (ВАРІАНТ A):
        // product_type = канонічний тип товару (наприклад: "плитоноска")
        // synonym      = конкретний варіант, який може бути в запиті (наприклад: "бронік", "tq")
        'product_type',
        'synonym',
        'language',
        'weight',
        'domain',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'weight'    => 'float',
    ];
}
