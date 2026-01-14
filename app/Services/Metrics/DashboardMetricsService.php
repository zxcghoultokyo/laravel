<?php

namespace App\Services\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Business-focused metrics for the admin dashboard.
 * Provides KPIs, trends, and chart data.
 */
class DashboardMetricsService
{
    /**
     * Get KPI cards data with trends.
     */
    public function getKPIs(string $period = '7d'): array
    {
        $cacheKey = "dashboard_kpis:{$period}";
        
        return Cache::remember($cacheKey, 60, function () use ($period) {
            [$startDate, $endDate, $prevStartDate, $prevEndDate] = $this->getPeriodDates($period);
            
            // Current period stats
            $current = $this->getPeriodStats($startDate, $endDate);
            
            // Previous period stats for comparison
            $previous = $this->getPeriodStats($prevStartDate, $prevEndDate);
            
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
                    'value' => $current['conversions'],
                    'change' => $this->calculateChange($current['conversions'], $previous['conversions']),
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
                        ? round(($current['conversions'] / $current['sessions']) * 100, 1) 
                        : 0,
                    'change' => $this->calculateRateChange(
                        $current['conversions'], $current['sessions'],
                        $previous['conversions'], $previous['sessions']
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
        $cacheKey = "dashboard_chart:{$period}";
        
        return Cache::remember($cacheKey, 120, function () use ($period) {
            [$startDate, $endDate] = $this->getPeriodDates($period);
            
            // Get daily data
            $dailyStats = DB::table('chat_daily_stats')
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->orderBy('date')
                ->get();
            
            // If no stats in chat_daily_stats, try chat_metrics
            if ($dailyStats->isEmpty()) {
                $dailyStats = $this->aggregateFromChatMetrics($startDate, $endDate);
            }
            
            $labels = [];
            $conversations = [];
            $conversions = [];
            $revenue = [];
            
            $currentDate = $startDate->copy();
            while ($currentDate <= $endDate) {
                $dateStr = $currentDate->toDateString();
                $labels[] = $currentDate->format('d.m');
                
                $dayStats = $dailyStats->firstWhere('date', $dateStr);
                
                $conversations[] = $dayStats->sessions_count ?? $dayStats->unique_sessions ?? 0;
                $conversions[] = $dayStats->purchase_sessions ?? $dayStats->add_to_cart_sessions ?? 0;
                $revenue[] = (float) ($dayStats->total_attributed_revenue ?? 0);
                
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
        $cacheKey = "dashboard_top_products:{$period}:{$limit}";
        
        return Cache::remember($cacheKey, 300, function () use ($limit, $period) {
            [$startDate, $endDate] = $this->getPeriodDates($period);
            
            // Get products from chat events
            $products = DB::table('chat_events')
                ->select('product_id', 'product_article', DB::raw('COUNT(*) as shows'), DB::raw('SUM(CASE WHEN event_type = "product_click" THEN 1 ELSE 0 END) as clicks'))
                ->whereIn('event_type', ['product_view', 'product_click'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('product_id')
                ->groupBy('product_id', 'product_article')
                ->orderByDesc('shows')
                ->limit($limit)
                ->get();
            
            // If no events, try to get from products table by popularity
            if ($products->isEmpty()) {
                $products = DB::table('products')
                    ->select('id as product_id', 'article as product_article', 'title', 'price', DB::raw('COALESCE(views_count, 0) as shows'), DB::raw('COALESCE(orders_count, 0) as clicks'))
                    ->where('in_stock', true)
                    ->orderByDesc('orders_count')
                    ->limit($limit)
                    ->get();
            }
            
            // Enrich with product titles
            $result = [];
            foreach ($products as $p) {
                $product = DB::table('products')
                    ->where('id', $p->product_id)
                    ->orWhere('article', $p->product_article)
                    ->first(['id', 'title', 'article', 'price']);
                
                $result[] = [
                    'id' => $product->id ?? $p->product_id,
                    'title' => $product->title ?? "Товар #{$p->product_id}",
                    'article' => $product->article ?? $p->product_article,
                    'price' => $product->price ?? null,
                    'shows' => $p->shows ?? 0,
                    'clicks' => $p->clicks ?? 0,
                    'ctr' => $p->shows > 0 ? round(($p->clicks / $p->shows) * 100, 1) : 0,
                ];
            }
            
            return $result;
        });
    }

    /**
     * Get recent chat sessions with outcome.
     */
    public function getRecentChats(int $limit = 10): array
    {
        return Cache::remember("dashboard_recent_chats:{$limit}", 60, function () use ($limit) {
            // Try chat_sessions first (main storage)
            $sessions = DB::table('chat_sessions')
                ->select('session_id', 'created_at', 'updated_at')
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get();
            
            // If empty, try active_chat_sessions
            if ($sessions->isEmpty()) {
                $sessions = DB::table('active_chat_sessions')
                    ->select('session_id', 'created_at', 'last_message_at as updated_at', 'status')
                    ->orderByDesc('last_message_at')
                    ->limit($limit)
                    ->get();
            }
            
            $result = [];
            foreach ($sessions as $s) {
                // Get first user message as preview
                $firstMessage = DB::table('chat_messages')
                    ->where('session_id', $s->session_id)
                    ->where('role', 'user')
                    ->orderBy('created_at')
                    ->first(['content']);
                
                // Get message count
                $messageCount = DB::table('chat_messages')
                    ->where('session_id', $s->session_id)
                    ->count();
                
                // Get outcome if exists
                $outcome = DB::table('chat_session_outcomes')
                    ->where('session_id', $s->session_id)
                    ->first(['outcome', 'outcome_category']);
                
                $result[] = [
                    'session_id' => $s->session_id,
                    'preview' => $firstMessage ? mb_substr($firstMessage->content, 0, 50) . (mb_strlen($firstMessage->content) > 50 ? '...' : '') : 'Новий чат',
                    'messages_count' => $messageCount,
                    'status' => $s->status ?? 'ai',
                    'outcome' => $outcome->outcome ?? null,
                    'outcome_category' => $outcome->outcome_category ?? null,
                    'time_ago' => Carbon::parse($s->updated_at)->diffForHumans(),
                    'created_at' => $s->updated_at,
                ];
            }
            
            return $result;
        });
    }

    /**
     * Get live stats (not cached).
     */
    public function getLiveStats(): array
    {
        $activeNow = DB::table('active_chat_sessions')
            ->where('last_message_at', '>', now()->subMinutes(5))
            ->count();
        
        $operatorSessions = DB::table('active_chat_sessions')
            ->where('status', 'operator')
            ->where('last_message_at', '>', now()->subMinutes(30))
            ->count();
        
        $todayRequests = DB::table('chat_metrics')
            ->whereDate('created_at', today())
            ->count();
        
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
     * Get aggregated stats for a period.
     */
    private function getPeriodStats(Carbon $startDate, Carbon $endDate): array
    {
        // Try chat_daily_stats first
        $dailyStats = DB::table('chat_daily_stats')
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('
                COALESCE(SUM(sessions_count), 0) as sessions,
                COALESCE(SUM(messages_count), 0) as messages,
                COALESCE(SUM(purchase_sessions), 0) as conversions,
                COALESCE(SUM(total_attributed_revenue), 0) as revenue
            ')
            ->first();
        
        // Try chat_metrics for response time and session count if daily_stats is empty
        $metricsStats = DB::table('chat_metrics')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(DISTINCT session_id) as sessions,
                COUNT(*) as messages,
                AVG(response_time_ms) as avg_response_ms
            ')
            ->first();
        
        // Try chat_conversions for conversions
        $conversions = DB::table('chat_conversions')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('conversion_type', 'purchase')
            ->selectRaw('
                COUNT(*) as count,
                COALESCE(SUM(order_total), 0) as revenue
            ')
            ->first();
        
        return [
            'sessions' => max($dailyStats->sessions ?? 0, $metricsStats->sessions ?? 0),
            'messages' => max($dailyStats->messages ?? 0, $metricsStats->messages ?? 0),
            'conversions' => max($dailyStats->conversions ?? 0, $conversions->count ?? 0),
            'revenue' => max($dailyStats->revenue ?? 0, $conversions->revenue ?? 0),
            'avg_response_ms' => round($metricsStats->avg_response_ms ?? 0),
        ];
    }

    /**
     * Aggregate stats from chat_metrics when daily_stats is empty.
     */
    private function aggregateFromChatMetrics(Carbon $startDate, Carbon $endDate)
    {
        return DB::table('chat_metrics')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(DISTINCT session_id) as unique_sessions,
                COUNT(*) as messages_count,
                0 as add_to_cart_sessions,
                0 as purchase_sessions,
                0 as total_attributed_revenue
            ')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();
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
}
