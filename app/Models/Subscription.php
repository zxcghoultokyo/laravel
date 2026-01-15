<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $plan_id
 * @property string $status
 * @property string $provider
 * @property string|null $provider_subscription_id
 * @property string|null $provider_customer_id
 * @property \DateTime|null $trial_ends_at
 * @property \DateTime|null $current_period_start
 * @property \DateTime|null $current_period_end
 * @property \DateTime|null $cancelled_at
 * @property \DateTime|null $ends_at
 * @property array|null $metadata
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Subscription extends Model
{
    use HasFactory;

    /**
     * Subscription statuses.
     */
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'provider',
        'provider_subscription_id',
        'provider_customer_id',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
        'ends_at',
        'metadata',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the tenant that owns the subscription.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the payments for this subscription.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Check if subscription is active.
     */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIALING]);
    }

    /**
     * Check if subscription is on trial.
     */
    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING && 
               $this->trial_ends_at && 
               $this->trial_ends_at->isFuture();
    }

    /**
     * Check if subscription is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Check if subscription is cancelled but still active (grace period).
     */
    public function onGracePeriod(): bool
    {
        return $this->isCancelled() && 
               $this->ends_at && 
               $this->ends_at->isFuture();
    }

    /**
     * Check if subscription has ended.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at && $this->ends_at->isPast();
    }

    /**
     * Check if subscription is past due.
     */
    public function isPastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    /**
     * Get the plan configuration.
     */
    public function getPlan(): array
    {
        return config("billing.plans.{$this->plan_id}", []);
    }

    /**
     * Get plan limits.
     */
    public function getPlanLimits(): array
    {
        $plan = $this->getPlan();
        return $plan['limits'] ?? [];
    }

    /**
     * Get plan price.
     */
    public function getPlanPrice(): int
    {
        $plan = $this->getPlan();
        return $plan['price'] ?? 0;
    }

    /**
     * Cancel the subscription.
     */
    public function cancel(): bool
    {
        $this->cancelled_at = now();
        $this->ends_at = $this->current_period_end;
        $this->status = self::STATUS_CANCELLED;
        
        return $this->save();
    }

    /**
     * Resume a cancelled subscription.
     */
    public function resume(): bool
    {
        if (!$this->onGracePeriod()) {
            return false;
        }

        $this->cancelled_at = null;
        $this->ends_at = null;
        $this->status = self::STATUS_ACTIVE;
        
        return $this->save();
    }

    /**
     * Mark subscription as active with new period.
     */
    public function markAsActive(\DateTime $periodStart, \DateTime $periodEnd): bool
    {
        $this->status = self::STATUS_ACTIVE;
        $this->current_period_start = $periodStart;
        $this->current_period_end = $periodEnd;
        
        return $this->save();
    }

    /**
     * Scope: active subscriptions.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_TRIALING]);
    }

    /**
     * Scope: by provider.
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
