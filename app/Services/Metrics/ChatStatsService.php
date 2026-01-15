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
     */
    public function getBasicStats(Carbon $startDate, ?Carbon $endDate = null): array
    {
        $endDate = $endDate ?? now();
        
        // PRIMARY SOURCE: chat_messages table (always most accurate)
        $messagesStats = $this->getMessagesStats($startDate, $endDate);
        
        // SECONDARY: chat_sessions for session count confirmation
        $sessionsCount = $this->getSessionsCount($startDate, $endDate);
        
        // TERTIARY: chat_metrics for response time
        $metricsStats = $this->getMetricsStats($startDate, $endDate);
        
        // QUATERNARY: chat_events for funnel data (page views, widget opens)
        $eventsStats = $this->getEventsStats($startDate, $endDate);
        
        // CONVERSIONS: chat_conversions
        $conversions = $this->getConversions($startDate, $endDate);
        
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
            'unique_users' => $messagesStats['unique_sessions'], // session_id as proxy for user
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
    private function getMessagesStats(Carbon $startDate, Carbon $endDate): array
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
            
            $stats = DB::table('chat_messages')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw("
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
                $productsShown = $this->countProductsFromMeta($startDate, $endDate);
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
    private function countProductsFromMeta(Carbon $startDate, Carbon $endDate): int
    {
        $count = 0;
        
        try {
            $messages = DB::table('chat_messages')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('role', 'assistant')
                ->whereNotNull('meta')
                ->get(['meta']);
            
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
    private function getSessionsCount(Carbon $startDate, Carbon $endDate): int
    {
        try {
            if (!Schema::hasTable('chat_sessions')) {
                return 0;
            }
            
            return DB::table('chat_sessions')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();
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
    private function getEventsStats(Carbon $startDate, Carbon $endDate): array
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
            
            // Page views
            $pageViews = DB::table('chat_events')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('event_type', 'page_view')
                ->count();
            
            // Unique page visitors
            $pageVisitors = DB::table('chat_events')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('event_type', 'page_view')
                ->whereNotNull('client_id')
                ->distinct('client_id')
                ->count('client_id');
            
            // Chat opened
            $chatOpened = DB::table('chat_events')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('event_type', 'chat_opened')
                ->count();
            
            // Unique users who opened chat
            $chatOpenedUsers = DB::table('chat_events')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('event_type', 'chat_opened')
                ->whereNotNull('client_id')
                ->distinct('client_id')
                ->count('client_id');
            
            // Widget open rate
            $widgetOpenRate = $pageVisitors > 0 ? round(($chatOpenedUsers / $pageVisitors) * 100, 1) : 0;
            
            // Products shown (from events)
            $productsShown = DB::table('chat_events')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('event_type', 'product_shown')
                ->count();
            
            // Products clicked
            $productsClicked = DB::table('chat_events')
                ->whereBetween('created_at', [$startDate, $endDate])
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
    private function getConversions(Carbon $startDate, Carbon $endDate): array
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
            
            $conversions = DB::table('chat_conversions')
                ->whereBetween('created_at', [$startDate, $endDate])
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
    public function getDailyChart(Carbon $startDate, ?Carbon $endDate = null): array
    {
        $endDate = $endDate ?? now();
        
        // Determine session column name
        $sessionColumn = Schema::hasColumn('chat_messages', 'session_id') 
            ? 'session_id' 
            : 'chat_session_id';
        
        // Primary source: chat_messages grouped by day
        $daily = DB::table('chat_messages')
            ->whereBetween('created_at', [$startDate, $endDate])
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
     */
    public function getRecentChats(int $limit = 10): array
    {
        // Determine session column name
        $sessionColumn = Schema::hasColumn('chat_messages', 'session_id') 
            ? 'session_id' 
            : 'chat_session_id';
        
        // Get from chat_messages grouped by session
        $sessions = DB::table('chat_messages')
            ->select($sessionColumn, DB::raw('MIN(created_at) as created_at'), DB::raw('MAX(created_at) as updated_at'), DB::raw('COUNT(*) as message_count'))
            ->groupBy($sessionColumn)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
        
        $result = [];
        foreach ($sessions as $s) {
            $sessionId = $s->$sessionColumn;
            
            // Get first user message as preview
            $firstMessage = DB::table('chat_messages')
                ->where($sessionColumn, $sessionId)
                ->where('role', 'user')
                ->orderBy('created_at')
                ->first(['content']);
            
            $result[] = [
                'session_id' => $sessionId,
                'preview' => $firstMessage ? mb_substr($firstMessage->content, 0, 50) . (mb_strlen($firstMessage->content) > 50 ? '...' : '') : 'Новий чат',
                'messages_count' => $s->message_count,
                'time_ago' => Carbon::parse($s->updated_at)->diffForHumans(),
                'created_at' => $s->updated_at,
            ];
        }
        
        return $result;
    }
}
