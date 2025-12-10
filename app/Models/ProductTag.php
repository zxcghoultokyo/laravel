<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;

class ProductTag extends Model
{
    protected $table = 'product_tags';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_auto_generated',
        'domain',
    ];

    protected $casts = [
        'is_auto_generated' => 'boolean',
    ];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_product_tag');
    }
}
