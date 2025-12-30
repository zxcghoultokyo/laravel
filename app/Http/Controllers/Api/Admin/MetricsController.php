<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsService;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    public function __construct(
        protected MetricsService $metricsService
    ) {}

    /**
     * Get dashboard metrics.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->metricsService->getDashboardMetrics());
    }
}
