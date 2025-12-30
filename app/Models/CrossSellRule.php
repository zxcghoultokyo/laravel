<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrossSellRule extends Model
{
    protected $fillable = [
        'source_category',
        'target_category',
        'reason',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];
}
