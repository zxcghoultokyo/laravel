<?php

namespace App\Services\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Unified Chat Statistics Service.
 * 
 * Single source of truth for chat metrics used by Dashboard and Analytics.
 * Priority order for data sources:
 * 1. chat_messages + chat_sessions (primary - always has most accurate data)
 * 2. chat_metrics (legacy, for response time data)
 * 3. chat_events (for funnel/conversion tracking)
 */
class ChatStatsService
{
    /**
     * Get basic chat statistics for a period.
     * This is the SINGLE source of truth for sessions/messages counts.
     * 
     * @param Carbon $startDate Start date
     * @param Carbon|null $endDate End date (defaults to now)
     * @param int|null $tenantId Filter by tenant ID (null = all tenants for superadmin)
     */
    public function getBasicStats(Carbon $startDate, ?Carbon $endDate = null, ?int $tenantId = null): array
    {
        $endDate = $endDate ?? now();
        
        // PRIMARY SOURCE: chat_messages table (always most accurate)
        $messagesStats = $this->getMessagesStats($startDate, $endDate, $tenantId);
        
        // SECONDARY: chat_sessions for session count confirmation
        $sessionsCount = $this->getSessionsCount($startDate, $endDate, $tenantId);
        
        // TERTIARY: chat_metrics for response time
        $metricsStats = $this->getMetricsStats($startDate, $endDate);
        
        // QUATERNARY: chat_events for funnel data (page views, widget opens)
        $eventsStats = $this->getEventsStats($startDate, $endDate, $tenantId);
        
        // CONVERSIONS: chat_conversions
        $conversions = $this->getConversions($startDate, $endDate, $tenantId);
        
        // Build unified response
        // Sessions: prefer chat_sessions count, fallback to unique session_ids from messages
        $sessions = $sessionsCount > 0 ? $sessionsCount : $messagesStats['unique_sessions'];
        
        // Messages: always from chat_messages (most accurate)
        $messages = $messagesStats['total_messages'];
        $userMessages = $messagesStats['user_messages'];
        
        // Products: from messages meta or events
        $productsShown = $messagesStats['products_shown'] ?: $eventsStats['products_shown'];
        $productsClicked = $eventsStats['products_clicked'] ?: 0;
        
        // CTR calculation
        $ctr = $productsShown > 0 ? round(($productsClicked / $productsShown) * 100, 1) : 0;
        
        // Avg messages per session
        $avgMessages = $sessions > 0 ? round($messages / $sessions, 1) : 0;
        
        return [
            // Core counts (from chat_messages/chat_sessions)
            'sessions' => $sessions,
            'messages' => $messages,
            'user_messages' => $userMessages,
            'unique_users' => $sessions, // Same as sessions (1 session = 1 user visit)
            'avg_messages' => $avgMessages,
            
            // Product engagement
            'products_shown' => $productsShown,
            'products_clicked' => $productsClicked,
            'ctr' => $ctr,
            
            // Funnel data (from events, may be 0 if not tracked)
            'page_views' => $eventsStats['page_views'],
            'page_visitors' => $eventsStats['page_visitors'],
            'chat_opened' => $eventsStats['chat_opened'],
            'chat_opened_users' => $eventsStats['chat_opened_users'],
            'widget_open_rate' => $eventsStats['widget_open_rate'],
            
            // Response time (from metrics)
            'avg_response_ms' => $metricsStats['avg_response_ms'],
            
            // Conversions
            'add_to_cart' => $conversions['add_to_cart'],
            'purchases' => $conversions['purchases'],
            'revenue' => $conversions['revenue'],
            'leads' => $conversions['leads'],
            
            // Meta
            'data_sources' => [
                'messages' => $messagesStats['has_data'],
                'sessions' => $sessionsCount > 0,
                'metrics' => $metricsStats['has_data'],
                'events' => $eventsStats['has_data'],
            ],
        ];
    }
    
    /**
     * Get statistics from chat_messages (PRIMARY SOURCE).
     */
    private function getMessagesStats(Carbon $startDate, Carbon $endDate, ?int $tenantId = null): array
    {
        $hasData = false;
        $totalMessages = 0;
        $userMessages = 0;
        $uniqueSessions = 0;
        $productsShown = 0;
        
        try {
            if (!Schema::hasTable('chat_messages')) {
                return $this->emptyMessagesStats();
            }
            
            // Determine session column name (chat_session_id or session_id)
            $sessionColumn = Schema::hasColumn('chat_messages', 'session_id') 
                ? 'session_id' 
                : 'chat_session_id';
            
            $query = DB::table('chat_messages')
                ->whereBetween('created_at', [$startDate, $endDate]);
            
            // Filter by tenant if specified
            if ($tenantId !== null && Schema::hasColumn('chat_messages', 'tenant_id')) {
                $query->where('tenant_id', $tenantId);
            }
            
            $stats = $query->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) as user_count,
                    COUNT(DISTINCT {$sessionColumn}) as unique_sessions
                ")
                ->first();
            
            $totalMessages = $stats->total ?? 0;
            $userMessages = $stats->user_count ?? 0;
            $uniqueSessions = $stats->unique_sessions ?? 0;
            $hasData = $totalMessages > 0;
            
            // Count products shown from assistant messages meta
            if ($hasData) {
                $productsShown = $this->countProductsFromMeta($startDate, $endDate, $tenantId);
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }
        
        return [
            'has_data' => $hasData,
            'total_messages' => $totalMessages,
            'user_messages' => $userMessages,
            'unique_sessions' => $uniqueSessions,
            'products_shown' => $productsShown,
        ];
    }
    
    /**
     * Count products shown from message meta JSON.
     */
    private function countProductsFromMeta(Carbon $startDate, Carbon $endDate, ?int $tenantId = null): int
    {
        $count = 0;
        
        try {
            $query = DB::table('chat_messages')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('role', 'assistant')
                ->whereNotNull('meta');
            
            if ($tenantId !== null && Schema::hasColumn('chat_messages', 'tenant_id')) {
                $query->where('tenant_id', $tenantId);
            }
            
            $messages = $query->get(['meta']);
            
            foreach ($messages as $msg) {
                $meta = json_decode($msg->meta, true);
                if (isset($meta['products']) && is_array($meta['products'])) {
                    $count += count($meta['products']);
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        
        return $count;
    }
    
    /**
     * Get session count from chat_sessions table.
     */
    private function getSessionsCount(Carbon $startDate, Carbon $endDate, ?int $tenantId = null): int
    {
        try {
            if (!Schema::hasTable('chat_sessions')) {
                return 0;
            }
            
            $query = DB::table('chat_sessions')
                ->whereBetween('created_at', [$startDate, $endDate]);
            
            if ($tenantId !== null && Schema::hasColumn('chat_sessions', 'tenant_id')) {
                $query->where('tenant_id', $tenantId);
            }
            
            return $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * Get statistics from chat_metrics (for response time).
     */
    private function getMetricsStats(Carbon $startDate, Carbon $endDate): array
    {
        try {
            if (!Schema::hasTable('chat_metrics')) {
                return ['has_data' => false, 'avg_response_ms' => 0];
            }
            
            $stats = DB::table('chat_metrics')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('
                    COUNT(*) as count,
                    AVG(response_time_ms) as avg_response_ms
                ')
                ->first();
            
            return [
                'has_data' => ($stats->count ?? 0) > 0,
                'avg_response_ms' => round($stats->avg_response_ms ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['has_data' => false, 'avg_response_ms' => 0];
        }
    }
    
    /**
     * Get funnel statistics from chat_events.
     */
    private function getEventsStats(Carbon $startDate, Carbon $endDate, ?int $tenantId = null): array
    {
        $default = [
            'has_data' => false,
            'page_views' => 0,
            'page_visitors' => 0,
            'chat_opened' => 0,
            'chat_opened_users' => 0,
            'widget_open_rate' => 0,
            'products_shown' => 0,
            'products_clicked' => 0,
        ];
        
        try {
            if (!Schema::hasTable('chat_events')) {
                return $default;
            }
            
            // Get merchant_id for filtering (tenant slug)
            $merchantId = null;
            if ($tenantId !== null) {
                $merchantId = DB::table('tenants')->where('id', $tenantId)->value('slug');
            }
            
            // Base query builder helper
            $baseQuery = function() use ($startDate, $endDate, $merchantId) {
                $q = DB::table('chat_events')
                    ->whereBetween('created_at', [$startDate, $endDate]);
                if ($merchantId) {
                    $q->where('merchant_id', $merchantId);
                }
                return $q;
            };
            
            // Page views
            $pageViews = $baseQuery()
                ->where('event_type', 'page_view')
                ->count();
            
            // Unique page visitors
            $pageVisitors = $baseQuery()
                ->where('event_type', 'page_view')
                ->whereNotNull('client_id')
                ->distinct('client_id')
                ->count('client_id');
            
            // Chat opened
            $chatOpened = $baseQuery()
                ->where('event_type', 'chat_opened')
                ->count();
            
            // Unique users who opened chat
            $chatOpenedUsers = $baseQuery()
                ->where('event_type', 'chat_opened')
                ->whereNotNull('client_id')
                ->distinct('client_id')
                ->count('client_id');
            
            // Widget open rate
            $widgetOpenRate = $pageVisitors > 0 ? round(($chatOpenedUsers / $pageVisitors) * 100, 1) : 0;
            
            // Products shown (from events)
            $productsShown = $baseQuery()
                ->where('event_type', 'product_shown')
                ->count();
            
            // Products clicked
            $productsClicked = $baseQuery()
                ->where('event_type', 'product_click')
                ->count();
            
            $hasData = $pageViews > 0 || $chatOpened > 0;
            
            return [
                'has_data' => $hasData,
                'page_views' => $pageViews,
                'page_visitors' => $pageVisitors,
                'chat_opened' => $chatOpened,
                'chat_opened_users' => $chatOpenedUsers,
                'widget_open_rate' => $widgetOpenRate,
                'products_shown' => $productsShown,
                'products_clicked' => $productsClicked,
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }
    
    /**
     * Get conversion statistics.
     */
    private function getConversions(Carbon $startDate, Carbon $endDate, ?int $tenantId = null): array
    {
        $default = [
            'add_to_cart' => 0,
            'purchases' => 0,
            'revenue' => 0,
            'leads' => 0,
        ];
        
        try {
            if (!Schema::hasTable('chat_conversions')) {
                return $default;
            }
            
            // Get merchant_id for filtering (tenant slug)
            $merchantId = null;
            if ($tenantId !== null) {
                $merchantId = DB::table('tenants')->where('id', $tenantId)->value('slug');
            }
            
            $query = DB::table('chat_conversions')
                ->whereBetween('created_at', [$startDate, $endDate]);
            
            if ($merchantId) {
                $query->where('merchant_id', $merchantId);
            }
            
            $conversions = $query
                ->selectRaw('conversion_type, COUNT(*) as count, SUM(order_total) as total')
                ->groupBy('conversion_type')
                ->get()
                ->keyBy('conversion_type');
            
            return [
                'add_to_cart' => $conversions->get('add_to_cart')->count ?? 0,
                'purchases' => $conversions->get('purchase')->count ?? 0,
                'revenue' => (float) ($conversions->get('purchase')->total ?? 0),
                'leads' => $conversions->get('lead')->count ?? 0,
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }
    
    /**
     * Empty messages stats structure.
     */
    private function emptyMessagesStats(): array
    {
        return [
            'has_data' => false,
            'total_messages' => 0,
            'user_messages' => 0,
            'unique_sessions' => 0,
            'products_shown' => 0,
        ];
    }
    
    /**
     * Get daily chart data.
     */
    public function getDailyChart(Carbon $startDate, ?Carbon $endDate = null, ?int $tenantId = null): array
    {
        $endDate = $endDate ?? now();
        
        // Determine session column name
        $sessionColumn = Schema::hasColumn('chat_messages', 'session_id') 
            ? 'session_id' 
            : 'chat_session_id';
        
        // Primary source: chat_messages grouped by day
        $query = DB::table('chat_messages')
            ->whereBetween('created_at', [$startDate, $endDate]);
        
        if ($tenantId !== null && Schema::hasColumn('chat_messages', 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }
        
        $daily = $query
            ->selectRaw("DATE(created_at) as date, COUNT(DISTINCT {$sessionColumn}) as sessions, COUNT(*) as messages")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
        
        return $daily->map(fn($row) => [
            'date' => $row->date,
            'sessions' => $row->sessions,
            'messages' => $row->messages,
        ])->toArray();
    }
    
    /**
     * Get recent chat activity for display.
     * 
     * Uses chat_sessions table (which has TenantScope via Eloquent),
     * joined with chat_messages for preview.
     */
    public function getRecentChats(int $limit = 10): array
    {
        // Use Eloquent to properly apply TenantScope from ChatSession
        $sessions = \App\Models\ChatSession::query()
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
        
        $result = [];
        foreach ($sessions as $session) {
            // Get first user message as preview
            $firstMessage = $session->messages()
                ->where('role', 'user')
                ->orderBy('created_at')
                ->first(['content']);
            
            $result[] = [
                'session_id' => $session->session_id,
                'preview' => $firstMessage 
                    ? mb_substr($firstMessage->content, 0, 50) . (mb_strlen($firstMessage->content) > 50 ? '...' : '') 
                    : ($session->last_user_query 
                        ? mb_substr($session->last_user_query, 0, 50) . (mb_strlen($session->last_user_query) > 50 ? '...' : '') 
                        : 'Новий чат'),
                'messages_count' => $session->messages_count,
                'time_ago' => $session->updated_at?->diffForHumans() ?? 'невідомо',
                'created_at' => $session->updated_at ?? $session->created_at,
            ];
        }
        
        return $result;
    }
}
