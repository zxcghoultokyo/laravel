<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProactiveTriggerRule extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'trigger_type',
        'is_enabled',
        'priority',
        'conditions',
        'message',
        'button_text',
        'icon',
        'action_type',
        'action_config',
        'max_per_session',
        'max_per_day',
        'cooldown_minutes',
        'shown_count',
        'clicked_count',
        'converted_count',
        'purchased_count',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'conditions' => 'array',
        'action_config' => 'array',
        'priority' => 'integer',
        'max_per_session' => 'integer',
        'max_per_day' => 'integer',
        'cooldown_minutes' => 'integer',
        'shown_count' => 'integer',
        'clicked_count' => 'integer',
        'converted_count' => 'integer',
        'purchased_count' => 'integer',
    ];

    // Trigger types
    public const TYPE_EXIT_INTENT = 'exit_intent';
    public const TYPE_TIME_ON_PAGE = 'time_on_page';
    public const TYPE_UTM_CAMPAIGN = 'utm_campaign';
    public const TYPE_RETURNING_VISITOR = 'returning_visitor';
    public const TYPE_PDP_NO_VARIANT = 'pdp_no_variant';

    // Action types
    public const ACTION_OPEN_CHAT = 'open_chat';
    public const ACTION_OPEN_CHAT_WITH_CONTEXT = 'open_chat_with_context';
    public const ACTION_SHOW_PRODUCTS = 'show_products';

    /**
     * Get events for this rule.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProactiveTriggerEvent::class, 'rule_id');
    }

    /**
     * Scope for enabled rules.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope for specific trigger type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('trigger_type', $type);
    }

    /**
     * Order by priority (lower = higher priority).
     */
    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    /**
     * Calculate CTR (Click-Through Rate).
     */
    public function getCtrAttribute(): float
    {
        if ($this->shown_count === 0) {
            return 0;
        }
        return round(($this->clicked_count / $this->shown_count) * 100, 1);
    }

    /**
     * Calculate conversion rate (from clicks to cart).
     */
    public function getConversionRateAttribute(): float
    {
        if ($this->clicked_count === 0) {
            return 0;
        }
        return round(($this->converted_count / $this->clicked_count) * 100, 1);
    }

    /**
     * Increment shown counter.
     */
    public function incrementShown(): void
    {
        $this->increment('shown_count');
    }

    /**
     * Increment clicked counter.
     */
    public function incrementClicked(): void
    {
        $this->increment('clicked_count');
    }

    /**
     * Increment converted counter.
     */
    public function incrementConverted(): void
    {
        $this->increment('converted_count');
    }

    /**
     * Increment purchased counter.
     */
    public function incrementPurchased(): void
    {
        $this->increment('purchased_count');
    }

    /**
     * Check if this rule matches UTM parameters.
     */
    public function matchesUtm(array $utm): bool
    {
        if ($this->trigger_type !== self::TYPE_UTM_CAMPAIGN) {
            return false;
        }

        $conditions = $this->conditions ?? [];
        
        // Check utm_source
        if (!empty($conditions['utm_source'])) {
            $source = strtolower($utm['utm_source'] ?? '');
            $conditionSource = strtolower($conditions['utm_source']);
            if (!str_contains($source, $conditionSource) && $source !== $conditionSource) {
                return false;
            }
        }

        // Check utm_medium
        if (!empty($conditions['utm_medium'])) {
            $medium = strtolower($utm['utm_medium'] ?? '');
            $conditionMedium = strtolower($conditions['utm_medium']);
            if (!str_contains($medium, $conditionMedium) && $medium !== $conditionMedium) {
                return false;
            }
        }

        // Check utm_campaign
        if (!empty($conditions['utm_campaign'])) {
            $campaign = strtolower($utm['utm_campaign'] ?? '');
            $conditionCampaign = strtolower($conditions['utm_campaign']);
            if (!str_contains($campaign, $conditionCampaign)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the message with variables replaced.
     */
    public function getRenderedMessage(array $context = []): string
    {
        $message = $this->message;
        
        // Replace common variables
        $variables = [
            '{{category}}' => $context['category'] ?? '',
            '{{product}}' => $context['product'] ?? '',
            '{{discount}}' => $context['discount'] ?? '',
            '{{price}}' => $context['price'] ?? '',
        ];

        return str_replace(array_keys($variables), array_values($variables), $message);
    }

    /**
     * Convert to API format for widget.
     */
    public function toWidgetFormat(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->trigger_type,
            'priority' => $this->priority,
            'conditions' => $this->conditions,
            'message' => $this->message,
            'button_text' => $this->button_text,
            'icon' => $this->icon,
            'action_type' => $this->action_type,
            'action_config' => $this->action_config,
            'limits' => [
                'max_per_session' => $this->max_per_session,
                'max_per_day' => $this->max_per_day,
                'cooldown_minutes' => $this->cooldown_minutes,
            ],
        ];
    }
}
