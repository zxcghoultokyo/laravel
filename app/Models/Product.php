<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ProductAiIndex;

class Product extends Model
{
    protected $fillable = [
        'article',
        'title',
        'title_json',
        'price',
        'price_old',
        'category_path',
        'slug',
        'link',
        'images',
        'raw',
        'search_index',
        'orders_count',
        'views_count',
        'added_to_cart_count',
        'display_in_showcase',
        'in_stock',
        'presence',
        'quantity',
        'popularity',
        'we_recommended',
        'color',
    ];

    protected $casts = [
        'title_json'           => 'array',
        'images'               => 'array',
        'raw'                  => 'array',
        'orders_count'         => 'integer',
        'views_count'          => 'integer',
        'added_to_cart_count'  => 'integer',
        'display_in_showcase'  => 'boolean',
        'we_recommended'       => 'boolean',
        'in_stock'             => 'boolean',
        'quantity'             => 'integer',
        'popularity'           => 'integer',
        'presence'             => 'string',
        'color'                => 'string',
    ];
    
    public function tags()
    {
        return $this->belongsToMany(\App\Models\ProductTag::class, 'product_product_tag');
    }
    
    public function aiIndex()
    {
        return $this->hasOne(ProductAiIndex::class, 'product_id');
    }
}
