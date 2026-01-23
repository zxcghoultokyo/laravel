<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\OnboardTenantJob;
use App\Models\Tenant;
use App\Models\TenantOnboardingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingProgressController extends Controller
{
    /**
     * GET /api/onboarding/progress/{tenantId}
     * Get onboarding progress for a tenant
     */
    public function show(Request $request, int $tenantId): JsonResponse
    {
        // Verify user has access to this tenant
        $user = $request->user();
        if ($user->tenant_id !== $tenantId && !$user->is_super_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $progress = TenantOnboardingProgress::where('tenant_id', $tenantId)->first();
        
        if (!$progress) {
            // No progress record = onboarding not started
            return response()->json([
                'status' => 'not_started',
                'status_label' => 'Не розпочато',
                'overall_percent' => 0,
                'current_step' => null,
                'current_step_detail' => null,
                'steps' => collect(TenantOnboardingProgress::STEPS)->map(function ($step, $key) {
                    return [
                        'key' => $key,
                        'name' => $step['name'],
                        'status' => 'pending',
                        'percent' => 0,
                        'detail' => null,
                        'stats' => [],
                    ];
                })->values()->toArray(),
                'started_at' => null,
                'completed_at' => null,
                'error_message' => null,
            ]);
        }

        return response()->json($progress->toProgressArray());
    }

    /**
     * POST /api/onboarding/start/{tenantId}
     * Start onboarding process for a tenant
     */
    public function start(Request $request, int $tenantId): JsonResponse
    {
        // Verify user has access to this tenant
        $user = $request->user();
        if ($user->tenant_id !== $tenantId && !$user->is_super_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check if onboarding is already in progress
        $progress = TenantOnboardingProgress::where('tenant_id', $tenantId)->first();
        if ($progress && $progress->status === 'in_progress') {
            return response()->json([
                'success' => false,
                'error' => 'Onboarding already in progress',
                'progress' => $progress->toProgressArray(),
            ], 400);
        }

        // Initialize progress record
        $progress = TenantOnboardingProgress::forTenant($tenantId);
        
        // Dispatch onboarding job
        OnboardTenantJob::dispatch($tenantId)->onQueue('default');

        return response()->json([
            'success' => true,
            'message' => 'Onboarding started',
            'progress' => $progress->fresh()->toProgressArray(),
        ]);
    }
}
