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
        self::PLAN_TRIAL => 5000,    // Pro limits during trial!
        self::PLAN_STARTER => 1000,
        self::PLAN_PRO => 5000,
        self::PLAN_ENTERPRISE => PHP_INT_MAX,
    ];

    /**
     * Features available per plan.
     * Trial gets Pro features to hook users!
     */
    public const PLAN_FEATURES = [
        self::PLAN_TRIAL => [
            'chat_widget',
            'widget_customization',
            'custom_greetings',
            'custom_prompts',
            'proactive_triggers',
            'advanced_analytics',
            'priority_support',
        ],
        self::PLAN_STARTER => [
            'chat_widget',
            'widget_customization',
            'custom_greetings',
            'custom_prompts',
            'basic_analytics',
        ],
        self::PLAN_PRO => [
            'chat_widget',
            'widget_customization',
            'custom_greetings',
            'custom_prompts',
            'proactive_triggers',
            'advanced_analytics',
            'priority_support',
        ],
        self::PLAN_ENTERPRISE => [
            'chat_widget',
            'widget_customization',
            'custom_greetings',
            'custom_prompts',
            'proactive_triggers',
            'advanced_analytics',
            'priority_support',
            'api_access',
            'white_label',
            'dedicated_support',
        ],
    ];

    /**
     * Feature metadata for UI (labels, descriptions, icons).
     */
    public const FEATURE_META = [
        'chat_widget' => [
            'label' => 'AI Чат-віджет',
            'description' => 'Розумний асистент на вашому сайті',
            'icon' => '💬',
            'min_plan' => 'starter',
        ],
        'widget_customization' => [
            'label' => 'Кастомізація віджета',
            'description' => 'Налаштування кольорів, аватара та зовнішнього вигляду',
            'icon' => '🎨',
            'min_plan' => 'starter',
        ],
        'basic_analytics' => [
            'label' => 'Базова аналітика',
            'description' => 'Перегляд кількості повідомлень та сесій',
            'icon' => '📊',
            'min_plan' => 'starter',
        ],
        'custom_greetings' => [
            'label' => 'Кастомні привітання',
            'description' => 'Налаштування привітань для різних ситуацій',
            'icon' => '👋',
            'min_plan' => 'starter',
        ],
        'advanced_analytics' => [
            'label' => 'Розширена аналітика',
            'description' => 'Детальні звіти, популярні товари, конверсії',
            'icon' => '📈',
            'min_plan' => 'pro',
        ],
        'custom_prompts' => [
            'label' => 'Кастомні промпти',
            'description' => 'Налаштуйте тон та стиль відповідей бота',
            'icon' => '✨',
            'min_plan' => 'starter',
        ],
        'proactive_triggers' => [
            'label' => 'Проактивні тригери',
            'description' => 'Автоматичні повідомлення на основі поведінки',
            'icon' => '🎯',
            'min_plan' => 'pro',
        ],
        'priority_support' => [
            'label' => 'Пріоритетна підтримка',
            'description' => 'Відповідь протягом 4 годин у робочий час',
            'icon' => '🚀',
            'min_plan' => 'pro',
        ],
        'api_access' => [
            'label' => 'API доступ',
            'description' => 'Інтеграція з вашими системами через REST API',
            'icon' => '🔌',
            'min_plan' => 'enterprise',
        ],
        'white_label' => [
            'label' => 'White Label',
            'description' => 'Повністю ваш бренд, без згадки AIntento',
            'icon' => '🏷️',
            'min_plan' => 'enterprise',
        ],
        'dedicated_support' => [
            'label' => 'Виділений менеджер',
            'description' => 'Персональний менеджер для вашого акаунту',
            'icon' => '👤',
            'min_plan' => 'enterprise',
        ],
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
     * Owner of this tenant (first user with owner role, or any first user as fallback).
     */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->oldest();
    }

    /**
     * Active subscription for this tenant.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latest();
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
     * Proactive trigger rules for this tenant.
     */
    public function proactiveTriggerRules(): HasMany
    {
        return $this->hasMany(ProactiveTriggerRule::class);
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
     * Check if tenant has access to a specific feature.
     */
    public function hasFeature(string $feature): bool
    {
        $features = self::PLAN_FEATURES[$this->plan] ?? [];
        return in_array($feature, $features);
    }

    /**
     * Get all features with their availability status.
     * Returns array with 'available', 'locked', 'upgrade_to' info.
     * 
     * For Pro/Trial plans - only shows Pro+ features (not basic ones)
     * For Starter - shows starter features + locked pro features
     */
    public function getFeaturesStatus(): array
    {
        $result = [];
        $currentFeatures = self::PLAN_FEATURES[$this->plan] ?? [];
        $effectivePlan = $this->plan === self::PLAN_TRIAL ? self::PLAN_PRO : $this->plan;
        
        // Plan hierarchy for filtering
        $planHierarchy = [
            self::PLAN_STARTER => 1,
            self::PLAN_PRO => 2,
            self::PLAN_TRIAL => 2, // Trial = Pro level
            self::PLAN_ENTERPRISE => 3,
        ];
        
        $currentLevel = $planHierarchy[$effectivePlan] ?? 1;
        
        foreach (self::FEATURE_META as $feature => $meta) {
            $featureMinPlan = $meta['min_plan'];
            $featureLevel = $planHierarchy[$featureMinPlan] ?? 1;
            
            // For Pro/Trial: skip features below Pro level (don't show basic_analytics)
            // User has them implicitly through advanced_analytics
            if ($currentLevel >= 2 && $featureLevel < $currentLevel) {
                // Skip basic features for Pro users (they have better versions)
                if ($feature === 'basic_analytics') {
                    continue;
                }
            }
            
            $result[$feature] = array_merge($meta, [
                'available' => in_array($feature, $currentFeatures),
                'upgrade_to' => in_array($feature, $currentFeatures) ? null : $meta['min_plan'],
            ]);
        }
        
        return $result;
    }

    /**
     * Get features that require upgrade (locked for current plan).
     */
    public function getLockedFeatures(): array
    {
        $features = $this->getFeaturesStatus();
        return array_filter($features, fn($f) => !$f['available']);
    }

    /**
     * Get minimum plan required for a feature.
     */
    public function getMinPlanForFeature(string $feature): ?string
    {
        return self::FEATURE_META[$feature]['min_plan'] ?? null;
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
    public function suspend(?string $reason = null): void
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
     * Returns the standardized format with token and tenant_id for proper tenant isolation.
     */
    public function getEmbedCode(): string
    {
        $baseUrl = config('app.url', 'https://aimbot.laravel.cloud');
        $token = $this->widgetSettings?->api_token ?? '';
        $tenantId = $this->id;
        
        return <<<HTML
<!-- AIntento Chat Widget -->
<div id="aintento-chat" data-token="{$token}" data-tenant-id="{$tenantId}"></div>
<script>
(function(){
  var s = document.createElement('script');
  s.src = '{$baseUrl}/widget.js?v=' + Date.now();
  document.body.appendChild(s);
})();
</script>
HTML;
    }
}
