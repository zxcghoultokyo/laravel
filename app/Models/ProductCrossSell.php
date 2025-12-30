<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCrossSell extends Model
{
    protected $fillable = [
        'product_id',
        'cross_sell_product_id',
        'type',
        'reason',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function crossSellProduct()
    {
        return $this->belongsTo(Product::class, 'cross_sell_product_id');
    }
}
