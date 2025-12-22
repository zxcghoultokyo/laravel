<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Brand extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'product_count',
        'is_active',
    ];

    protected $casts = [
        'product_count' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Generate slug from name
     */
    public static function boot()
    {
        parent::boot();

        static::creating(function ($brand) {
            if (empty($brand->slug)) {
                $brand->slug = Str::slug($brand->name);
            }
        });
    }

    /**
     * Get products with this brand
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'brand', 'name');
    }
}
