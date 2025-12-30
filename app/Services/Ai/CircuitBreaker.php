<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker pattern for external services.
 * 
 * When a service fails too many times, the circuit "opens" and
 * we skip calling it for a cooldown period, returning fallback instead.
 * 
 * States:
 * - CLOSED: Normal operation, requests go through
 * - OPEN: Service is down, skip requests and use fallback
 * - HALF_OPEN: After cooldown, try one request to see if service recovered
 * 
 * Usage:
 * ```php
 * $breaker = app(CircuitBreaker::class);
 * 
 * if ($breaker->isOpen('openai')) {
 *     return $this->fallbackResponse();
 * }
 * 
 * try {
 *     $result = $this->callOpenAI();
 *     $breaker->recordSuccess('openai');
 *     return $result;
 * } catch (\Exception $e) {
 *     $breaker->recordFailure('openai');
 *     return $this->fallbackResponse();
 * }
 * ```
 */
class CircuitBreaker
{
    // How many failures before opening circuit
    private const FAILURE_THRESHOLD = 5;
    
    // How long to wait before trying again (seconds)
    private const COOLDOWN_SECONDS = 300; // 5 minutes
    
    // How long to track failures (seconds)
    private const FAILURE_WINDOW = 60;
    
    // Cache key prefix
    private const CACHE_PREFIX = 'circuit_breaker_';

    /**
     * Check if circuit is open (should skip the service).
     */
    public function isOpen(string $service): bool
    {
        $state = $this->getState($service);
        
        if ($state['status'] === 'closed') {
            return false;
        }
        
        if ($state['status'] === 'open') {
            // Check if cooldown period has passed
            if (time() >= $state['retry_at']) {
                // Move to half-open: allow one request through
                $this->setState($service, 'half_open', $state['failures']);
                Log::info("CircuitBreaker: {$service} moving to half-open state");
                return false;
            }
            return true;
        }
        
        // half_open: allow request through
        return false;
    }

    /**
     * Record a successful call (close the circuit).
     */
    public function recordSuccess(string $service): void
    {
        $state = $this->getState($service);
        
        if ($state['status'] === 'half_open') {
            // Service recovered, close the circuit
            Log::info("CircuitBreaker: {$service} recovered, closing circuit");
        }
        
        // Reset to closed state
        $this->setState($service, 'closed', 0);
    }

    /**
     * Record a failure (may open the circuit).
     */
    public function recordFailure(string $service): void
    {
        $state = $this->getState($service);
        $failures = $state['failures'] + 1;
        
        Log::warning("CircuitBreaker: {$service} failure recorded", [
            'failures' => $failures,
            'threshold' => self::FAILURE_THRESHOLD,
        ]);
        
        if ($state['status'] === 'half_open') {
            // Failed during half-open, reopen circuit
            $this->openCircuit($service, $failures);
            return;
        }
        
        if ($failures >= self::FAILURE_THRESHOLD) {
            $this->openCircuit($service, $failures);
            return;
        }
        
        // Increment failure count
        $this->setState($service, 'closed', $failures);
    }

    /**
     * Get current failure count.
     */
    public function getFailureCount(string $service): int
    {
        return $this->getState($service)['failures'];
    }

    /**
     * Get seconds until retry (if circuit is open).
     */
    public function getRetryAfter(string $service): ?int
    {
        $state = $this->getState($service);
        
        if ($state['status'] !== 'open') {
            return null;
        }
        
        $remaining = $state['retry_at'] - time();
        return max(0, $remaining);
    }

    /**
     * Manually reset the circuit (for admin).
     */
    public function reset(string $service): void
    {
        Cache::forget(self::CACHE_PREFIX . $service);
        Log::info("CircuitBreaker: {$service} manually reset");
    }

    /**
     * Get all circuit states (for admin dashboard).
     */
    public function getAllStates(): array
    {
        $services = ['openai', 'meilisearch', 'horoshop'];
        $states = [];
        
        foreach ($services as $service) {
            $state = $this->getState($service);
            $states[$service] = [
                'status' => $state['status'],
                'failures' => $state['failures'],
                'retry_after' => $this->getRetryAfter($service),
            ];
        }
        
        return $states;
    }

    private function openCircuit(string $service, int $failures): void
    {
        $retryAt = time() + self::COOLDOWN_SECONDS;
        $this->setState($service, 'open', $failures, $retryAt);
        
        Log::error("CircuitBreaker: {$service} circuit OPENED", [
            'failures' => $failures,
            'retry_at' => date('Y-m-d H:i:s', $retryAt),
        ]);
    }

    public function getState(string $service): array
    {
        $default = [
            'status' => 'closed',
            'failures' => 0,
            'retry_at' => null,
            'updated_at' => time(),
        ];
        
        return Cache::get(self::CACHE_PREFIX . $service, $default);
    }

    private function setState(string $service, string $status, int $failures, ?int $retryAt = null): void
    {
        $state = [
            'status' => $status,
            'failures' => $failures,
            'retry_at' => $retryAt,
            'updated_at' => time(),
        ];
        
        // Keep state for longer than cooldown
        $ttl = max(self::COOLDOWN_SECONDS * 2, self::FAILURE_WINDOW * 10);
        Cache::put(self::CACHE_PREFIX . $service, $state, $ttl);
    }
}
