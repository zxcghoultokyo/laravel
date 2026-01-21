<?php

namespace App\Services\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Business-focused metrics for the admin dashboard.
 * Provides KPIs, trends, and chart data.
 * 
 * Uses ChatStatsService as the single source of truth for chat metrics.
 */
class DashboardMetricsService
{
    private ChatStatsService $chatStats;
    
    public function __construct(ChatStatsService $chatStats)
    {
        $this->chatStats = $chatStats;
    }
    
    /**
     * Get current tenant ID for cache key prefixing.
     */
    private function getTenantCachePrefix(): string
    {
        $tenantId = auth()->user()?->tenant_id ?? 'global';
        return "t{$tenantId}:";
    }
    
    /**
     * Get KPI cards data with trends.
     */
    public function getKPIs(string $period = '7d'): array
    {
        $cacheKey = $this->getTenantCachePrefix() . "dashboard_kpis:{$period}";
        
        return Cache::remember($cacheKey, 60, function () use ($period) {
            [$startDate, $endDate, $prevStartDate, $prevEndDate] = $this->getPeriodDates($period);
            
            // Current period stats from unified ChatStatsService
            $current = $this->chatStats->getBasicStats($startDate, $endDate);
            
            // Previous period stats for comparison
            $previous = $this->chatStats->getBasicStats($prevStartDate, $prevEndDate);
            
            return [
                'conversations' => [
                    'value' => $current['sessions'],
                    'change' => $this->calculateChange($current['sessions'], $previous['sessions']),
                    'label' => 'Діалогів',
                    'icon' => '💬',
                ],
                'messages' => [
                    'value' => $current['messages'],
                    'change' => $this->calculateChange($current['messages'], $previous['messages']),
                    'label' => 'Повідомлень',
                    'icon' => '📨',
                ],
                'conversions' => [
                    'value' => $current['purchases'],
                    'change' => $this->calculateChange($current['purchases'], $previous['purchases']),
                    'label' => 'Конверсій',
                    'icon' => '🛒',
                ],
                'revenue' => [
                    'value' => $current['revenue'],
                    'change' => $this->calculateChange($current['revenue'], $previous['revenue']),
                    'label' => 'Виручка',
                    'icon' => '💰',
                    'format' => 'currency',
                ],
                'conversion_rate' => [
                    'value' => $current['sessions'] > 0 
                        ? round(($current['purchases'] / $current['sessions']) * 100, 1) 
                        : 0,
                    'change' => $this->calculateRateChange(
                        $current['purchases'], $current['sessions'],
                        $previous['purchases'], $previous['sessions']
                    ),
                    'label' => 'Конверсія',
                    'icon' => '📈',
                    'format' => 'percent',
                ],
                'avg_response_time' => [
                    'value' => $current['avg_response_ms'],
                    'change' => $this->calculateChange($previous['avg_response_ms'], $current['avg_response_ms']), // inverted - lower is better
                    'label' => 'Час відповіді',
                    'icon' => '⚡',
                    'format' => 'ms',
                ],
            ];
        });
    }

    /**
     * Get chart data for conversations and conversions.
     */
    public function getChartData(string $period = '7d'): array
    {
        $cacheKey = $this->getTenantCachePrefix() . "dashboard_chart:{$period}";
        
        return Cache::remember($cacheKey, 120, function () use ($period) {
            [$startDate, $endDate] = $this->getPeriodDates($period);
            
            // Use unified ChatStatsService for chart data
            $dailyStats = collect($this->chatStats->getDailyChart($startDate, $endDate));
            
            $labels = [];
            $conversations = [];
            $conversions = [];
            $revenue = [];
            
            $currentDate = $startDate->copy();
            while ($currentDate <= $endDate) {
                $dateStr = $currentDate->toDateString();
                $labels[] = $currentDate->format('d.m');
                
                $dayStats = $dailyStats->firstWhere('date', $dateStr);
                
                $conversations[] = $dayStats['sessions'] ?? 0;
                $conversions[] = 0; // Would need daily conversions tracking
                $revenue[] = 0; // Would need daily revenue tracking
                
                $currentDate->addDay();
            }
            
            return [
                'labels' => $labels,
                'datasets' => [
                    'conversations' => $conversations,
                    'conversions' => $conversions,
                    'revenue' => $revenue,
                ],
            ];
        });
    }

    /**
     * Get top products shown/clicked in chat.
     */
    public function getTopProducts(int $limit = 5, string $period = '7d'): array
    {
        $cacheKey = $this->getTenantCachePrefix() . "dashboard_top_products:{$period}:{$limit}";
        
        return Cache::remember($cacheKey, 300, function () use ($limit, $period) {
            [$startDate, $endDate] = $this->getPeriodDates($period);
            
            $products = collect();
            
            // Try chat_events first
            try {
                $products = DB::table('chat_events')
                    ->select('product_id', 'product_article', DB::raw('COUNT(*) as shows'), DB::raw('SUM(CASE WHEN event_type = "product_click" THEN 1 ELSE 0 END) as clicks'))
                    ->whereIn('event_type', ['product_view', 'product_click'])
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->whereNotNull('product_id')
                    ->groupBy('product_id', 'product_article')
                    ->orderByDesc('shows')
                    ->limit($limit)
                    ->get();
            } catch (\Throwable $e) {
                // Table might not exist
            }
            
            // If no events, try to get from products table by popularity
            if ($products->isEmpty()) {
                try {
                    $products = DB::table('products')
                        ->select('id as product_id', 'article as product_article', 'title', 'price', DB::raw('COALESCE(views_count, 0) as shows'), DB::raw('COALESCE(orders_count, 0) as clicks'))
                        ->where('in_stock', true)
                        ->orderByDesc('orders_count')
                        ->limit($limit)
                        ->get();
                } catch (\Throwable $e) {
                    return [];
                }
            }
            
            // Enrich with product titles
            $result = [];
            foreach ($products as $p) {
                try {
                    $product = DB::table('products')
                        ->where('id', $p->product_id)
                        ->orWhere('article', $p->product_article)
                        ->first(['id', 'title', 'article', 'price']);
                    
                    $result[] = [
                        'id' => $product->id ?? $p->product_id,
                        'title' => $product->title ?? $p->title ?? "Товар #{$p->product_id}",
                        'article' => $product->article ?? $p->product_article,
                        'price' => $product->price ?? $p->price ?? null,
                        'shows' => $p->shows ?? 0,
                        'clicks' => $p->clicks ?? 0,
                        'ctr' => $p->shows > 0 ? round(($p->clicks / $p->shows) * 100, 1) : 0,
                    ];
                } catch (\Throwable $e) {
                    continue;
                }
            }
            
            return $result;
        });
    }

    /**
     * Get recent chat sessions with outcome.
     * Uses ChatStatsService as data source.
     */
    public function getRecentChats(int $limit = 10): array
    {
        return Cache::remember($this->getTenantCachePrefix() . "dashboard_recent_chats:{$limit}", 60, function () use ($limit) {
            $chats = $this->chatStats->getRecentChats($limit);
            
            // Enrich with outcome data if available
            foreach ($chats as &$chat) {
                try {
                    $outcome = DB::table('chat_session_outcomes')
                        ->where('session_id', $chat['session_id'])
                        ->first(['outcome', 'outcome_category']);
                    
                    $chat['outcome'] = $outcome->outcome ?? null;
                    $chat['outcome_category'] = $outcome->outcome_category ?? null;
                    $chat['status'] = 'ai';
                } catch (\Throwable $e) {
                    $chat['outcome'] = null;
                    $chat['outcome_category'] = null;
                    $chat['status'] = 'ai';
                }
            }
            
            return $chats;
        });
    }

    /**
     * Get live stats (not cached).
     */
    public function getLiveStats(): array
    {
        $activeNow = 0;
        $operatorSessions = 0;
        $todayRequests = 0;
        
        try {
            $activeNow = DB::table('active_chat_sessions')
                ->where('last_message_at', '>', now()->subMinutes(5))
                ->count();
        } catch (\Throwable $e) {
            // Table might not exist
        }
        
        try {
            $operatorSessions = DB::table('active_chat_sessions')
                ->where('status', 'operator')
                ->where('last_message_at', '>', now()->subMinutes(30))
                ->count();
        } catch (\Throwable $e) {
            // Table might not exist
        }
        
        try {
            $todayRequests = DB::table('chat_metrics')
                ->whereDate('created_at', today())
                ->count();
        } catch (\Throwable $e) {
            // Fallback to chat_messages
            try {
                $todayRequests = DB::table('chat_messages')
                    ->whereDate('created_at', today())
                    ->where('role', 'user')
                    ->count();
            } catch (\Throwable $e2) {
                // No fallback
            }
        }
        
        return [
            'active_now' => $activeNow,
            'operator_sessions' => $operatorSessions,
            'today_requests' => $todayRequests,
        ];
    }

    /**
     * Get period dates based on period string.
     */
    private function getPeriodDates(string $period): array
    {
        $endDate = now()->endOfDay();
        
        switch ($period) {
            case 'today':
                $startDate = now()->startOfDay();
                $prevStartDate = now()->subDay()->startOfDay();
                $prevEndDate = now()->subDay()->endOfDay();
                break;
            case '7d':
                $startDate = now()->subDays(6)->startOfDay();
                $prevStartDate = now()->subDays(13)->startOfDay();
                $prevEndDate = now()->subDays(7)->endOfDay();
                break;
            case '30d':
                $startDate = now()->subDays(29)->startOfDay();
                $prevStartDate = now()->subDays(59)->startOfDay();
                $prevEndDate = now()->subDays(30)->endOfDay();
                break;
            case '90d':
                $startDate = now()->subDays(89)->startOfDay();
                $prevStartDate = now()->subDays(179)->startOfDay();
                $prevEndDate = now()->subDays(90)->endOfDay();
                break;
            default:
                $startDate = now()->subDays(6)->startOfDay();
                $prevStartDate = now()->subDays(13)->startOfDay();
                $prevEndDate = now()->subDays(7)->endOfDay();
        }
        
        return [$startDate, $endDate, $prevStartDate, $prevEndDate];
    }

    /**
     * Calculate percentage change between two values.
     */
    private function calculateChange($current, $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Calculate rate change.
     */
    private function calculateRateChange($currentNum, $currentDen, $prevNum, $prevDen): float
    {
        $currentRate = $currentDen > 0 ? ($currentNum / $currentDen) * 100 : 0;
        $prevRate = $prevDen > 0 ? ($prevNum / $prevDen) * 100 : 0;
        
        if ($prevRate == 0) {
            return $currentRate > 0 ? 100 : 0;
        }
        
        return round((($currentRate - $prevRate) / $prevRate) * 100, 1);
    }
    
    /**
     * Get funnel data for conversion tracking.
     * Shows drop-off at each stage: page_view → chat_opened → message → product_click → add_to_cart → checkout_success
     */
    public function getFunnelData(string $period = '7d'): array
    {
        $cacheKey = $this->getTenantCachePrefix() . "dashboard_funnel:{$period}";
        
        return Cache::remember($cacheKey, 120, function () use ($period) {
            [$startDate, $endDate] = $this->getPeriodDates($period);
            
            // Define funnel stages with their event types
            $stages = [
                'page_view' => ['label' => 'Відвідувачі', 'icon' => '👁️', 'hint' => 'Відкрили сторінку з віджетом'],
                'chat_opened' => ['label' => 'Відкрили чат', 'icon' => '💬', 'hint' => 'Натиснули на іконку чату'],
                'message' => ['label' => 'Написали', 'icon' => '✍️', 'hint' => 'Надіслали повідомлення'],
                'product_click' => ['label' => 'Клік на товар', 'icon' => '👆', 'hint' => 'Клікнули на картку товару'],
                'add_to_cart' => ['label' => 'До кошика', 'icon' => '🛒', 'hint' => 'Додали товар у кошик'],
                'checkout_success' => ['label' => 'Замовлення', 'icon' => '✅', 'hint' => 'Оформили замовлення'],
            ];
            
            $funnel = [];
            $prevCount = 0;
            
            foreach ($stages as $eventType => $stage) {
                try {
                    // Count unique sessions for this event type
                    $count = DB::table('chat_events')
                        ->where('event_type', $eventType)
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->distinct('session_id')
                        ->count('session_id');
                } catch (\Throwable $e) {
                    $count = 0;
                }
                
                // Calculate conversion rate from previous stage
                $rate = $prevCount > 0 ? round(($count / $prevCount) * 100, 1) : 0;
                $dropoff = $prevCount > 0 ? round((($prevCount - $count) / $prevCount) * 100, 1) : 0;
                
                $funnel[] = [
                    'stage' => $eventType,
                    'label' => $stage['label'],
                    'icon' => $stage['icon'],
                    'hint' => $stage['hint'],
                    'count' => $count,
                    'rate' => $rate,
                    'dropoff' => $dropoff,
                ];
                
                $prevCount = $count ?: $prevCount; // Keep prev for rate calculation
            }
            
            // Calculate overall conversion rate
            $firstStage = $funnel[0]['count'] ?? 0;
            $lastStage = $funnel[count($funnel) - 1]['count'] ?? 0;
            $overallRate = $firstStage > 0 ? round(($lastStage / $firstStage) * 100, 2) : 0;
            
            return [
                'stages' => $funnel,
                'overall_rate' => $overallRate,
            ];
        });
    }
}
