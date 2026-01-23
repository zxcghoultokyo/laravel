<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Order extends Model
{
    protected $fillable = [
        'tenant_id',
        'order_id',
        'session_id',
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
        'had_chat',
        'products_from_chat',
        'analytics',
        'ordered_at',
    ];

    protected $casts = [
        'tenant_id'             => 'integer',
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
        'had_chat'              => 'boolean',
        'products_from_chat'    => 'integer',
        'analytics'             => 'array',
        'ordered_at'            => 'datetime',
    ];

    /**
     * Apply tenant scope for multi-tenancy
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Tenant relationship
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Status labels mapping
     */
    public const STATUS_LABELS = [
        1 => 'Новий',
        2 => 'В обробці',
        3 => 'Доставлено',
        4 => 'Не доставлено',
        6 => 'Доставляється',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get related chat session
     */
    public function chatSession()
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'session_id');
    }

    /**
     * Get status label attribute
     */
    protected function statusLabelComputed(): Attribute
    {
        return Attribute::make(
            get: fn () => self::STATUS_LABELS[$this->status_code] ?? $this->status_label ?? 'Невідомо',
        );
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

    /**
     * Scope for orders with chat attribution
     */
    public function scopeWithChat($query)
    {
        return $query->where('had_chat', true);
    }

    /**
     * Scope for recent orders
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('ordered_at', '>=', now()->subDays($days));
    }

    /**
     * Calculate total revenue for a period
     */
    public static function totalRevenue(int $days = 30): float
    {
        return static::recent($days)->notCancelled()->sum('total_sum');
    }

    /**
     * Calculate chat-attributed revenue for a period
     */
    public static function chatAttributedRevenue(int $days = 30): float
    {
        return static::recent($days)->withChat()->notCancelled()->sum('total_sum');
    }
}
