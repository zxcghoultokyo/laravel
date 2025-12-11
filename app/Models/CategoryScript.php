<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryScript extends Model
{
    protected $fillable = [
        'category_key',
        'level',
        'question_template',
        'metadata',
        'is_auto_generated',
    ];

    protected $casts = [
        'metadata'          => 'array',
        'is_auto_generated' => 'boolean',
    ];
}
