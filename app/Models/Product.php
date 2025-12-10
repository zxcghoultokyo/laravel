<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'title_json'           => 'array',
        'images'               => 'array',
        'raw'                  => 'array',
        'price'                => 'float',
        'price_old'            => 'float',
        'orders_count'         => 'integer',
        'views_count'          => 'integer',
        'added_to_cart_count'  => 'integer',
    ];
}
