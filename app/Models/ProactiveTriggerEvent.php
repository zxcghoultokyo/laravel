<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProactiveTriggerEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'session_id',
        'event_type',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    // Event types
    public const EVENT_SHOWN = 'shown';
    public const EVENT_CLICKED = 'clicked';
    public const EVENT_DISMISSED = 'dismissed';
    public const EVENT_CONVERTED = 'converted'; // Added to cart
    public const EVENT_PURCHASED = 'purchased';

    /**
     * Get the rule for this event.
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ProactiveTriggerRule::class, 'rule_id');
    }

    /**
     * Create a shown event.
     */
    public static function recordShown(int $ruleId, string $sessionId, array $context = []): self
    {
        $event = self::create([
            'rule_id' => $ruleId,
            'session_id' => $sessionId,
            'event_type' => self::EVENT_SHOWN,
            'context' => $context,
        ]);

        // Update rule counter
        ProactiveTriggerRule::where('id', $ruleId)->increment('shown_count');

        return $event;
    }

    /**
     * Create a clicked event.
     */
    public static function recordClicked(int $ruleId, string $sessionId, array $context = []): self
    {
        $event = self::create([
            'rule_id' => $ruleId,
            'session_id' => $sessionId,
            'event_type' => self::EVENT_CLICKED,
            'context' => $context,
        ]);

        // Update rule counter
        ProactiveTriggerRule::where('id', $ruleId)->increment('clicked_count');

        return $event;
    }

    /**
     * Create a dismissed event.
     */
    public static function recordDismissed(int $ruleId, string $sessionId, array $context = []): self
    {
        return self::create([
            'rule_id' => $ruleId,
            'session_id' => $sessionId,
            'event_type' => self::EVENT_DISMISSED,
            'context' => $context,
        ]);
    }

    /**
     * Create a converted event (added to cart).
     */
    public static function recordConverted(int $ruleId, string $sessionId, array $context = []): self
    {
        $event = self::create([
            'rule_id' => $ruleId,
            'session_id' => $sessionId,
            'event_type' => self::EVENT_CONVERTED,
            'context' => $context,
        ]);

        // Update rule counter
        ProactiveTriggerRule::where('id', $ruleId)->increment('converted_count');

        return $event;
    }

    /**
     * Create a purchased event.
     */
    public static function recordPurchased(int $ruleId, string $sessionId, array $context = []): self
    {
        $event = self::create([
            'rule_id' => $ruleId,
            'session_id' => $sessionId,
            'event_type' => self::EVENT_PURCHASED,
            'context' => $context,
        ]);

        // Update rule counter
        ProactiveTriggerRule::where('id', $ruleId)->increment('purchased_count');

        return $event;
    }

    /**
     * Scope for specific session.
     */
    public function scopeForSession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope for today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}
