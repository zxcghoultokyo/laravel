<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $subscription_id
 * @property int $amount
 * @property string $currency
 * @property string $status
 * @property string $provider
 * @property string|null $provider_payment_id
 * @property string|null $provider_order_id
 * @property string|null $description
 * @property string|null $card_mask
 * @property string|null $card_type
 * @property string|null $card_bank
 * @property \DateTime|null $paid_at
 * @property \DateTime|null $refunded_at
 * @property int|null $refunded_amount
 * @property array|null $metadata
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Payment extends Model
{
    use HasFactory;

    /**
     * Payment statuses.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'amount',
        'currency',
        'status',
        'provider',
        'provider_payment_id',
        'provider_order_id',
        'description',
        'card_mask',
        'card_type',
        'card_bank',
        'paid_at',
        'refunded_at',
        'refunded_amount',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'refunded_amount' => 'integer',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the tenant that owns the payment.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the subscription for this payment.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Check if payment is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if payment is pending.
     */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    /**
     * Check if payment failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if payment is refunded (fully or partially).
     */
    public function isRefunded(): bool
    {
        return in_array($this->status, [self::STATUS_REFUNDED, self::STATUS_PARTIALLY_REFUNDED]);
    }

    /**
     * Get formatted amount (in UAH, not kopecks).
     */
    public function getFormattedAmountAttribute(): string
    {
        $amount = $this->amount / 100;
        return number_format($amount, 2, '.', ' ') . ' ' . $this->currency;
    }

    /**
     * Mark payment as successful.
     */
    public function markAsSuccessful(array $data = []): bool
    {
        $this->status = self::STATUS_SUCCESS;
        $this->paid_at = now();
        
        if (!empty($data['provider_payment_id'])) {
            $this->provider_payment_id = $data['provider_payment_id'];
        }
        if (!empty($data['card_mask'])) {
            $this->card_mask = $data['card_mask'];
        }
        if (!empty($data['card_type'])) {
            $this->card_type = $data['card_type'];
        }
        if (!empty($data['card_bank'])) {
            $this->card_bank = $data['card_bank'];
        }
        if (!empty($data['metadata'])) {
            $this->metadata = array_merge($this->metadata ?? [], $data['metadata']);
        }
        
        return $this->save();
    }

    /**
     * Mark payment as failed.
     */
    public function markAsFailed(string $reason = null): bool
    {
        $this->status = self::STATUS_FAILED;
        
        if ($reason) {
            $this->metadata = array_merge($this->metadata ?? [], ['failure_reason' => $reason]);
        }
        
        return $this->save();
    }

    /**
     * Mark payment as refunded.
     */
    public function markAsRefunded(int $amount = null): bool
    {
        $refundAmount = $amount ?? $this->amount;
        $this->refunded_amount = ($this->refunded_amount ?? 0) + $refundAmount;
        $this->refunded_at = now();
        
        if ($this->refunded_amount >= $this->amount) {
            $this->status = self::STATUS_REFUNDED;
        } else {
            $this->status = self::STATUS_PARTIALLY_REFUNDED;
        }
        
        return $this->save();
    }

    /**
     * Scope: successful payments.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * Scope: by provider.
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope: by order ID.
     */
    public function scopeByOrderId($query, string $orderId)
    {
        return $query->where('provider_order_id', $orderId);
    }

    /**
     * Scope: recent payments.
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
