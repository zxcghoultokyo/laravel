<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'article',
        'title',
        'price',
        'quantity',
        'total_price',
        'discount_marker',
        'type',
    ];

    protected $casts = [
        'price'       => 'decimal:2',
        'quantity'    => 'integer',
        'total_price' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product by article.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'article', 'article');
    }
}
