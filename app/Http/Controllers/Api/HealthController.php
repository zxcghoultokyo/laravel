<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\CircuitBreaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Health check endpoint for monitoring.
 * 
 * Usage:
 * - GET /api/health — full health check (for monitoring systems)
 * - GET /api/health?quick=1 — quick check (just returns 200 if app is up)
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $quick = request()->boolean('quick');
        
        if ($quick) {
            return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
        }

        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'meilisearch' => $this->checkMeilisearch(),
            'openai' => $this->checkOpenAI(),
        ];

        $allHealthy = collect($checks)->every(fn($check) => $check['status'] === 'ok');
        $status = $allHealthy ? 'healthy' : 'degraded';

        // Log if degraded
        if (!$allHealthy) {
            Log::warning('Health check degraded', $checks);
        }

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
            'version' => config('app.version', '1.0.0'),
        ], $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'ok',
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . time();
            $start = microtime(true);
            
            Cache::put($key, 'test', 10);
            $value = Cache::get($key);
            Cache::forget($key);
            
            $latency = round((microtime(true) - $start) * 1000, 2);

            if ($value !== 'test') {
                return ['status' => 'error', 'error' => 'Cache read/write mismatch'];
            }

            return [
                'status' => 'ok',
                'driver' => config('cache.default'),
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkMeilisearch(): array
    {
        if (!config('meilisearch.enabled', false)) {
            return [
                'status' => 'ok',
                'enabled' => false,
                'message' => 'Meilisearch disabled',
            ];
        }

        try {
            $host = config('meilisearch.host');
            $start = microtime(true);
            
            $response = Http::timeout(3)
                ->withHeaders(['Authorization' => 'Bearer ' . config('meilisearch.key')])
                ->get($host . '/health');
            
            $latency = round((microtime(true) - $start) * 1000, 2);

            if ($response->successful() && ($response->json('status') === 'available')) {
                return [
                    'status' => 'ok',
                    'enabled' => true,
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status' => 'error',
                'enabled' => true,
                'error' => 'Meilisearch not available',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'enabled' => true,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkOpenAI(): array
    {
        // Check circuit breaker status
        $circuitBreaker = app(CircuitBreaker::class);
        $isOpen = $circuitBreaker->isOpen('openai');

        if ($isOpen) {
            return [
                'status' => 'degraded',
                'circuit_breaker' => 'open',
                'message' => 'Circuit breaker active, using fallback',
                'failures' => $circuitBreaker->getFailureCount('openai'),
                'retry_after' => $circuitBreaker->getRetryAfter('openai'),
            ];
        }

        // Don't actually call OpenAI in health check (costs money)
        // Just check if key is configured
        $hasKey = !empty(config('services.openai.key'));

        return [
            'status' => $hasKey ? 'ok' : 'error',
            'configured' => $hasKey,
            'circuit_breaker' => 'closed',
            'failures' => $circuitBreaker->getFailureCount('openai'),
        ];
    }
}
