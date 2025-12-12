<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchEvalCase extends Model
{
    protected $table = 'search_eval_cases';

    protected $fillable = [
        'query',
        'expected_product_ids',
        'language',
        'domain',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'expected_product_ids' => 'array',
        'is_active' => 'boolean',
    ];
}
