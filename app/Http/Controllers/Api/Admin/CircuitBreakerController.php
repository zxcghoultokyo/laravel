<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\CircuitBreaker;
use Illuminate\Http\JsonResponse;

class CircuitBreakerController extends Controller
{
    public function __construct(
        protected CircuitBreaker $circuitBreaker
    ) {}

    /**
     * Get all circuit breaker states.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'circuit_breakers' => $this->circuitBreaker->getAllStates(),
        ]);
    }

    /**
     * Reset a specific circuit breaker.
     */
    public function reset(string $service): JsonResponse
    {
        $this->circuitBreaker->reset($service);

        return response()->json([
            'message' => "Circuit breaker for {$service} has been reset",
            'circuit_breakers' => $this->circuitBreaker->getAllStates(),
        ]);
    }
}
