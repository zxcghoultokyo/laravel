<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProactiveTriggerRule;
use App\Models\ProactiveTriggerEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProactiveTriggersController extends Controller
{
    /**
     * Get active trigger rules for widget.
     * Cached for 5 minutes.
     */
    public function getRules(Request $request)
    {
        $rules = Cache::remember('proactive_triggers_rules', 300, function () {
            return ProactiveTriggerRule::enabled()
                ->byPriority()
                ->get()
                ->map(fn($rule) => $rule->toWidgetFormat())
                ->values()
                ->all();
        });

        return response()->json([
            'rules' => $rules,
            'global_limits' => [
                'max_triggers_per_session' => 1,
                'cooldown_after_chat_open' => 300, // 5 minutes
            ],
        ]);
    }

    /**
     * Track trigger event (shown, clicked, dismissed).
     */
    public function trackEvent(Request $request)
    {
        $validated = $request->validate([
            'rule_id' => 'required|integer|exists:proactive_trigger_rules,id',
            'session_id' => 'required|string|max:64',
            'event_type' => 'required|string|in:shown,clicked,dismissed,converted,purchased',
            'context' => 'nullable|array',
        ]);

        try {
            $event = match ($validated['event_type']) {
                'shown' => ProactiveTriggerEvent::recordShown(
                    $validated['rule_id'],
                    $validated['session_id'],
                    $validated['context'] ?? []
                ),
                'clicked' => ProactiveTriggerEvent::recordClicked(
                    $validated['rule_id'],
                    $validated['session_id'],
                    $validated['context'] ?? []
                ),
                'dismissed' => ProactiveTriggerEvent::recordDismissed(
                    $validated['rule_id'],
                    $validated['session_id'],
                    $validated['context'] ?? []
                ),
                'converted' => ProactiveTriggerEvent::recordConverted(
                    $validated['rule_id'],
                    $validated['session_id'],
                    $validated['context'] ?? []
                ),
                'purchased' => ProactiveTriggerEvent::recordPurchased(
                    $validated['rule_id'],
                    $validated['session_id'],
                    $validated['context'] ?? []
                ),
            };

            Log::info('ProactiveTrigger event tracked', [
                'rule_id' => $validated['rule_id'],
                'event_type' => $validated['event_type'],
                'session_id' => substr($validated['session_id'], 0, 8) . '...',
            ]);

            return response()->json(['success' => true, 'event_id' => $event->id]);
        } catch (\Throwable $e) {
            Log::error('ProactiveTrigger event tracking failed', [
                'error' => $e->getMessage(),
                'rule_id' => $validated['rule_id'],
            ]);
            
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if trigger can be shown for this session.
     */
    public function checkTrigger(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:64',
            'trigger_type' => 'nullable|string',
            'utm' => 'nullable|array',
            'page_type' => 'nullable|string',
        ]);

        $sessionId = $validated['session_id'];

        // Get today's events for this session
        $todayEvents = ProactiveTriggerEvent::forSession($sessionId)
            ->today()
            ->where('event_type', 'shown')
            ->get();

        // Check session limit (any trigger shown today)
        $sessionTriggerCount = $todayEvents->count();
        if ($sessionTriggerCount >= 1) {
            return response()->json([
                'can_show' => false,
                'reason' => 'session_limit_reached',
            ]);
        }

        // Find matching rule
        $rules = ProactiveTriggerRule::enabled()->byPriority()->get();
        
        $matchedRule = null;
        foreach ($rules as $rule) {
            // If specific trigger type requested, filter by it
            if (!empty($validated['trigger_type']) && $rule->trigger_type !== $validated['trigger_type']) {
                continue;
            }

            // Check UTM matching
            if ($rule->trigger_type === ProactiveTriggerRule::TYPE_UTM_CAMPAIGN) {
                if (!empty($validated['utm']) && $rule->matchesUtm($validated['utm'])) {
                    $matchedRule = $rule;
                    break;
                }
                continue;
            }

            // Check page type for time/exit triggers
            if (in_array($rule->trigger_type, [ProactiveTriggerRule::TYPE_EXIT_INTENT, ProactiveTriggerRule::TYPE_TIME_ON_PAGE])) {
                $pageTypes = $rule->conditions['page_types'] ?? [];
                if (!empty($validated['page_type']) && !empty($pageTypes) && !in_array($validated['page_type'], $pageTypes)) {
                    continue;
                }
                $matchedRule = $rule;
                break;
            }

            // Other trigger types
            $matchedRule = $rule;
            break;
        }

        if (!$matchedRule) {
            return response()->json([
                'can_show' => false,
                'reason' => 'no_matching_rule',
            ]);
        }

        return response()->json([
            'can_show' => true,
            'rule' => $matchedRule->toWidgetFormat(),
        ]);
    }

    /**
     * Get trigger statistics (for admin).
     */
    public function getStats(Request $request)
    {
        $period = $request->get('period', '7d');
        $startDate = match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };

        $rules = ProactiveTriggerRule::withCount([
            'events as shown_period' => function ($q) use ($startDate) {
                $q->where('event_type', 'shown')->where('created_at', '>=', $startDate);
            },
            'events as clicked_period' => function ($q) use ($startDate) {
                $q->where('event_type', 'clicked')->where('created_at', '>=', $startDate);
            },
            'events as converted_period' => function ($q) use ($startDate) {
                $q->where('event_type', 'converted')->where('created_at', '>=', $startDate);
            },
            'events as purchased_period' => function ($q) use ($startDate) {
                $q->where('event_type', 'purchased')->where('created_at', '>=', $startDate);
            },
        ])->get();

        $totals = [
            'shown' => $rules->sum('shown_period'),
            'clicked' => $rules->sum('clicked_period'),
            'converted' => $rules->sum('converted_period'),
            'purchased' => $rules->sum('purchased_period'),
        ];

        $totals['ctr'] = $totals['shown'] > 0 
            ? round(($totals['clicked'] / $totals['shown']) * 100, 1) 
            : 0;
        $totals['conversion_rate'] = $totals['clicked'] > 0 
            ? round(($totals['converted'] / $totals['clicked']) * 100, 1) 
            : 0;

        return response()->json([
            'period' => $period,
            'totals' => $totals,
            'rules' => $rules->map(function ($rule) {
                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'trigger_type' => $rule->trigger_type,
                    'is_enabled' => $rule->is_enabled,
                    'shown' => $rule->shown_period,
                    'clicked' => $rule->clicked_period,
                    'converted' => $rule->converted_period,
                    'purchased' => $rule->purchased_period,
                    'ctr' => $rule->shown_period > 0 
                        ? round(($rule->clicked_period / $rule->shown_period) * 100, 1) 
                        : 0,
                    'all_time' => [
                        'shown' => $rule->shown_count,
                        'clicked' => $rule->clicked_count,
                        'converted' => $rule->converted_count,
                        'purchased' => $rule->purchased_count,
                    ],
                ];
            }),
        ]);
    }
}
