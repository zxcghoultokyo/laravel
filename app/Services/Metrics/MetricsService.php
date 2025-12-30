<?php

namespace App\Services\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Service for tracking and reporting chat metrics.
 */
class MetricsService
{
    /**
     * Record a chat request metric.
     */
    public function recordRequest(array $data): void
    {
        try {
            DB::table('chat_metrics')->insert([
                'request_id' => $data['request_id'] ?? null,
                'session_id' => $data['session_id'] ?? 'unknown',
                'intent' => $data['intent'] ?? null,
                'response_time_ms' => $data['response_time_ms'] ?? 0,
                'ai_time_ms' => $data['ai_time_ms'] ?? null,
                'search_time_ms' => $data['search_time_ms'] ?? null,
                'products_count' => $data['products_count'] ?? 0,
                'cache_hit' => $data['cache_hit'] ?? false,
                'ai_used' => $data['ai_used'] ?? true,
                'is_fallback' => $data['is_fallback'] ?? false,
                'error' => $data['error'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Don't fail the request if metrics fail
            Log::warning('MetricsService: failed to record metric', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Update active chat session.
     */
    public function updateActiveSession(string $sessionId, array $data): void
    {
        try {
            DB::table('active_chat_sessions')->updateOrInsert(
                ['session_id' => $sessionId],
                array_merge($data, [
                    'last_message_at' => now(),
                    'updated_at' => now(),
                ])
            );
        } catch (\Throwable $e) {
            Log::warning('MetricsService: failed to update session', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Mark session as taken over by operator.
     */
    public function markOperatorTakeover(string $sessionId, int $operatorId): void
    {
        DB::table('active_chat_sessions')->updateOrInsert(
            ['session_id' => $sessionId],
            [
                'status' => 'operator',
                'operator_id' => $operatorId,
                'operator_took_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Increment daily takeovers
        $this->incrementDailyStat('operator_takeovers');
    }

    /**
     * Release session back to AI.
     */
    public function releaseSession(string $sessionId): void
    {
        DB::table('active_chat_sessions')
            ->where('session_id', $sessionId)
            ->update([
                'status' => 'ai',
                'operator_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Get active sessions for admin dashboard.
     */
    public function getActiveSessions(int $limit = 50): array
    {
        return DB::table('active_chat_sessions')
            ->where('last_message_at', '>', now()->subMinutes(30))
            ->orderBy('last_message_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get session details.
     */
    public function getSession(string $sessionId): ?object
    {
        return DB::table('active_chat_sessions')
            ->where('session_id', $sessionId)
            ->first();
    }

    /**
     * Get dashboard metrics (cached for 1 minute).
     */
    public function getDashboardMetrics(): array
    {
        return Cache::remember('dashboard_metrics', 60, function () {
            $today = now()->toDateString();
            $todayStats = DB::table('chat_daily_stats')
                ->where('date', $today)
                ->first();

            $recentMetrics = DB::table('chat_metrics')
                ->where('created_at', '>', now()->subHour())
                ->selectRaw('
                    COUNT(*) as total_requests,
                    AVG(response_time_ms) as avg_response_time,
                    SUM(CASE WHEN cache_hit THEN 1 ELSE 0 END) as cache_hits,
                    SUM(CASE WHEN is_fallback THEN 1 ELSE 0 END) as fallbacks,
                    SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) as errors
                ')
                ->first();

            $activeNow = DB::table('active_chat_sessions')
                ->where('last_message_at', '>', now()->subMinutes(5))
                ->count();

            $operatorSessions = DB::table('active_chat_sessions')
                ->where('status', 'operator')
                ->count();

            return [
                'today' => $todayStats ? (array) $todayStats : [],
                'last_hour' => [
                    'requests' => $recentMetrics->total_requests ?? 0,
                    'avg_response_ms' => round($recentMetrics->avg_response_time ?? 0, 2),
                    'cache_hit_rate' => $recentMetrics->total_requests > 0
                        ? round(($recentMetrics->cache_hits / $recentMetrics->total_requests) * 100, 1)
                        : 0,
                    'fallback_rate' => $recentMetrics->total_requests > 0
                        ? round(($recentMetrics->fallbacks / $recentMetrics->total_requests) * 100, 1)
                        : 0,
                    'error_rate' => $recentMetrics->total_requests > 0
                        ? round(($recentMetrics->errors / $recentMetrics->total_requests) * 100, 1)
                        : 0,
                ],
                'live' => [
                    'active_sessions' => $activeNow,
                    'operator_sessions' => $operatorSessions,
                ],
                'circuit_breakers' => app(\App\Services\Ai\CircuitBreaker::class)->getAllStates(),
            ];
        });
    }

    /**
     * Aggregate daily stats (run via scheduled job).
     */
    public function aggregateDailyStats(?string $date = null): void
    {
        $date = $date ?? now()->subDay()->toDateString();

        $stats = DB::table('chat_metrics')
            ->whereDate('created_at', $date)
            ->selectRaw('
                COUNT(*) as total_requests,
                COUNT(DISTINCT session_id) as unique_sessions,
                SUM(CASE WHEN intent = "product_search" THEN 1 ELSE 0 END) as product_searches,
                SUM(CASE WHEN ai_used THEN 1 ELSE 0 END) as ai_calls,
                SUM(CASE WHEN ai_used AND error IS NOT NULL THEN 1 ELSE 0 END) as ai_failures,
                SUM(CASE WHEN cache_hit THEN 1 ELSE 0 END) as cache_hits,
                SUM(CASE WHEN is_fallback THEN 1 ELSE 0 END) as fallbacks,
                AVG(response_time_ms) as avg_response_time_ms
            ')
            ->first();

        // Calculate p95
        $p95 = DB::table('chat_metrics')
            ->whereDate('created_at', $date)
            ->orderBy('response_time_ms', 'desc')
            ->skip((int) (($stats->total_requests ?? 0) * 0.05))
            ->take(1)
            ->value('response_time_ms') ?? 0;

        DB::table('chat_daily_stats')->updateOrInsert(
            ['date' => $date],
            [
                'total_requests' => $stats->total_requests ?? 0,
                'unique_sessions' => $stats->unique_sessions ?? 0,
                'product_searches' => $stats->product_searches ?? 0,
                'ai_calls' => $stats->ai_calls ?? 0,
                'ai_failures' => $stats->ai_failures ?? 0,
                'cache_hits' => $stats->cache_hits ?? 0,
                'fallbacks' => $stats->fallbacks ?? 0,
                'avg_response_time_ms' => round($stats->avg_response_time_ms ?? 0, 2),
                'p95_response_time_ms' => $p95,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Increment a daily stat counter.
     */
    private function incrementDailyStat(string $field): void
    {
        $today = now()->toDateString();
        
        DB::table('chat_daily_stats')->updateOrInsert(
            ['date' => $today],
            ['created_at' => now()]
        );
        
        DB::table('chat_daily_stats')
            ->where('date', $today)
            ->increment($field);
    }
}
