<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scenario extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'handler_class',
        'is_active',
        'config',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'config'    => 'array',
    ];
}
