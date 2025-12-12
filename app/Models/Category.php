<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'categories';

    protected $fillable = [
        'path',
        'path_norm',
        'slug',
        'products_count',
        'is_active',
    ];

    protected $casts = [
        'products_count' => 'integer',
        'is_active'      => 'boolean',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(CategoryAlias::class, 'category_id');
    }
}
