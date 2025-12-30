<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_id',
        'status_code',
        'status_label',
        'currency',
        'total_default',
        'total_sum',
        'total_quantity',
        'discount_value',
        'coupon_code',
        'coupon_discount_value',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_city',
        'customer_address',
        'delivery_type_id',
        'delivery_type_title',
        'delivery_price',
        'delivery_comment',
        'payment_type_id',
        'payment_type_title',
        'payment_price',
        'payed',
        'raw',
        'ordered_at',
    ];

    protected $casts = [
        'order_id'              => 'integer',
        'status_code'           => 'integer',
        'total_default'         => 'decimal:2',
        'total_sum'             => 'decimal:2',
        'total_quantity'        => 'integer',
        'discount_value'        => 'decimal:2',
        'coupon_discount_value' => 'decimal:2',
        'delivery_type_id'      => 'integer',
        'delivery_price'        => 'decimal:2',
        'payment_type_id'       => 'integer',
        'payment_price'         => 'decimal:2',
        'payed'                 => 'boolean',
        'raw'                   => 'array',
        'ordered_at'            => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Scope for delivered orders (for counting sales).
     */
    public function scopeDelivered($query)
    {
        return $query->where('status_code', 3);
    }

    /**
     * Scope for completed orders (delivered + in delivery).
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('status_code', [3, 6]);
    }

    /**
     * Scope for not cancelled orders.
     */
    public function scopeNotCancelled($query)
    {
        return $query->where('status_code', '!=', 4);
    }
}
