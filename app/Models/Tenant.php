<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Tenant model - represents a customer/shop in multi-tenant SaaS.
 */
class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'email',
        'plan',
        'trial_ends_at',
        'plan_expires_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        'messages_limit',
        'messages_used',
        'usage_reset_at',
        'platform',
        'platform_credentials',
        'last_sync_at',
        'status',
        'suspension_reason',
        'settings',
    ];

    protected $casts = [
        'platform_credentials' => 'encrypted:array',
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
        'plan_expires_at' => 'datetime',
        'usage_reset_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    protected $hidden = [
        'platform_credentials',
        'stripe_customer_id',
        'stripe_subscription_id',
    ];

    /**
     * Plan constants
     */
    public const PLAN_TRIAL = 'trial';
    public const PLAN_STARTER = 'starter';
    public const PLAN_PRO = 'pro';
    public const PLAN_ENTERPRISE = 'enterprise';

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Platform constants
     */
    public const PLATFORM_HOROSHOP = 'horoshop';
    public const PLATFORM_SHOPIFY = 'shopify';
    public const PLATFORM_MANUAL = 'manual';

    /**
     * Plan limits
     */
    public const PLAN_LIMITS = [
        self::PLAN_TRIAL => 100,
        self::PLAN_STARTER => 1000,
        self::PLAN_PRO => 5000,
        self::PLAN_ENTERPRISE => PHP_INT_MAX,
    ];

    /**
     * Boot: auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tenant) {
            if (empty($tenant->slug)) {
                $tenant->slug = Str::slug($tenant->name);
            }
            if (empty($tenant->trial_ends_at) && $tenant->plan === self::PLAN_TRIAL) {
                $tenant->trial_ends_at = now()->addDays(14);
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Users belonging to this tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Widget settings for this tenant.
     */
    public function widgetSettings(): HasOne
    {
        return $this->hasOne(WidgetSettings::class);
    }

    /**
     * Products belonging to this tenant.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Chat sessions for this tenant.
     */
    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    /**
     * Greetings for this tenant.
     */
    public function greetings(): HasMany
    {
        return $this->hasMany(Greeting::class);
    }

    /**
     * Prompt presets for this tenant.
     */
    public function promptPresets(): HasMany
    {
        return $this->hasMany(PromptPreset::class);
    }

    /**
     * Subscriptions for this tenant.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Payments for this tenant.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get active subscription.
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()->active()->first();
    }

    /**
     * Store context for this tenant.
     */
    public function storeContext(): HasOne
    {
        return $this->hasOne(StoreContext::class);
    }

    // ==================== HELPERS ====================

    /**
     * Check if tenant is on trial.
     */
    public function isOnTrial(): bool
    {
        return $this->plan === self::PLAN_TRIAL && 
               $this->trial_ends_at && 
               $this->trial_ends_at->isFuture();
    }

    /**
     * Check if trial has expired.
     */
    public function isTrialExpired(): bool
    {
        return $this->plan === self::PLAN_TRIAL && 
               $this->trial_ends_at && 
               $this->trial_ends_at->isPast();
    }

    /**
     * Check if tenant is active.
     */
    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->isTrialExpired()) {
            return false;
        }

        return true;
    }

    /**
     * Check if tenant can send messages.
     */
    public function canSendMessage(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->messages_used < $this->messages_limit;
    }

    /**
     * Increment message usage.
     */
    public function incrementMessageUsage(): void
    {
        $this->increment('messages_used');
    }

    /**
     * Reset monthly usage.
     */
    public function resetUsage(): void
    {
        $this->update([
            'messages_used' => 0,
            'usage_reset_at' => now(),
        ]);
    }

    /**
     * Get usage percentage.
     */
    public function getUsagePercentage(): float
    {
        if ($this->messages_limit === 0) {
            return 100;
        }
        return round(($this->messages_used / $this->messages_limit) * 100, 1);
    }

    /**
     * Get remaining messages.
     */
    public function getRemainingMessages(): int
    {
        return max(0, $this->messages_limit - $this->messages_used);
    }

    /**
     * Upgrade plan.
     */
    public function upgradePlan(string $plan): void
    {
        $this->update([
            'plan' => $plan,
            'messages_limit' => self::PLAN_LIMITS[$plan] ?? 1000,
            'plan_expires_at' => now()->addMonth(),
        ]);
    }

    /**
     * Suspend tenant.
     */
    public function suspend(string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_SUSPENDED,
            'suspension_reason' => $reason,
        ]);
    }

    /**
     * Reactivate tenant.
     */
    public function reactivate(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'suspension_reason' => null,
        ]);
    }

    /**
     * Get plan label.
     */
    public function getPlanLabel(): string
    {
        return match($this->plan) {
            self::PLAN_TRIAL => 'Trial',
            self::PLAN_STARTER => 'Starter',
            self::PLAN_PRO => 'Pro',
            self::PLAN_ENTERPRISE => 'Enterprise',
            default => ucfirst($this->plan),
        };
    }

    /**
     * Get status label.
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'Активний',
            self::STATUS_SUSPENDED => 'Призупинено',
            self::STATUS_CANCELLED => 'Скасовано',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get widget embed code.
     */
    public function getEmbedCode(): string
    {
        $baseUrl = config('app.widget_url', 'https://chat.ailure.ai');
        return "<script src=\"{$baseUrl}/widget/{$this->slug}.js\" async></script>";
    }
}
