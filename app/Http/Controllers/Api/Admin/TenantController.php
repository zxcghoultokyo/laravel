<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeStoreContextJob;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Admin controller for tenant management.
 */
class TenantController extends Controller
{
    /**
     * List all tenants.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query();

        // Filters
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($plan = $request->query('plan')) {
            $query->where('plan', $plan);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $tenants = $query->orderBy('created_at', 'desc')
                         ->paginate($request->query('per_page', 20));

        return response()->json([
            'tenants' => $tenants->items(),
            'meta' => [
                'total' => $tenants->total(),
                'page' => $tenants->currentPage(),
                'per_page' => $tenants->perPage(),
                'last_page' => $tenants->lastPage(),
            ],
            'stats' => [
                'total' => Tenant::count(),
                'active' => Tenant::where('status', Tenant::STATUS_ACTIVE)->count(),
                'trial' => Tenant::where('plan', Tenant::PLAN_TRIAL)->count(),
                'paid' => Tenant::whereIn('plan', [Tenant::PLAN_STARTER, Tenant::PLAN_PRO, Tenant::PLAN_ENTERPRISE])->count(),
            ],
        ]);
    }

    /**
     * Get a single tenant.
     */
    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::with(['widgetSettings', 'storeContext', 'users'])
                        ->findOrFail($id);

        return response()->json([
            'tenant' => $tenant,
            'usage' => [
                'messages_used' => $tenant->messages_used,
                'messages_limit' => $tenant->messages_limit,
                'percentage' => $tenant->getUsagePercentage(),
                'remaining' => $tenant->getRemainingMessages(),
            ],
            'embed_code' => $tenant->getEmbedCode(),
        ]);
    }

    /**
     * Create a new tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:tenants,slug',
            'email' => 'required|email|unique:tenants,email',
            'domain' => 'nullable|string|max:255',
            'plan' => 'nullable|string|in:trial,starter,pro,enterprise',
            'platform' => 'nullable|string|in:horoshop,shopify,manual',
            'platform_credentials' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        
        // Auto-generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
            // Ensure uniqueness
            $base = $data['slug'];
            $counter = 1;
            while (Tenant::where('slug', $data['slug'])->exists()) {
                $data['slug'] = $base . '-' . $counter++;
            }
        }

        // Set defaults
        $data['plan'] = $data['plan'] ?? Tenant::PLAN_TRIAL;
        $data['status'] = Tenant::STATUS_ACTIVE;
        $data['messages_limit'] = Tenant::PLAN_LIMITS[$data['plan']] ?? 100;
        $data['trial_ends_at'] = $data['plan'] === Tenant::PLAN_TRIAL 
            ? now()->addDays(14) 
            : null;

        $tenant = Tenant::create($data);

        // Create default widget settings
        $tenant->widgetSettings()->create([
            'primary_color' => '#007bff',
            'header_text' => 'Чат-помічник',
            'welcome_message' => 'Привіт! Чим можу допомогти?',
            'position' => 'bottom-right',
            'show_on_mobile' => true,
        ]);

        // If platform credentials provided, schedule store analysis
        if (!empty($data['platform_credentials'])) {
            AnalyzeStoreContextJob::dispatch($tenant->id)->delay(now()->addSeconds(5));
        }

        return response()->json([
            'message' => 'Tenant created successfully',
            'tenant' => $tenant->fresh(['widgetSettings']),
            'embed_code' => $tenant->getEmbedCode(),
        ], 201);
    }

    /**
     * Update a tenant.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:tenants,email,' . $id,
            'domain' => 'nullable|string|max:255',
            'plan' => 'sometimes|string|in:trial,starter,pro,enterprise',
            'status' => 'sometimes|string|in:active,suspended,cancelled',
            'platform' => 'sometimes|string|in:horoshop,shopify,manual',
            'platform_credentials' => 'sometimes|array',
            'messages_limit' => 'sometimes|integer|min:0',
            'settings' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // If plan changed, update limit
        if (isset($data['plan']) && $data['plan'] !== $tenant->plan) {
            $data['messages_limit'] = Tenant::PLAN_LIMITS[$data['plan']] ?? $tenant->messages_limit;
        }

        $tenant->update($data);

        return response()->json([
            'message' => 'Tenant updated successfully',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Delete a tenant.
     */
    public function destroy(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        
        // Soft delete: set status to cancelled instead of hard delete
        $tenant->update([
            'status' => Tenant::STATUS_CANCELLED,
        ]);

        return response()->json([
            'message' => 'Tenant cancelled successfully',
        ]);
    }

    /**
     * Suspend a tenant.
     */
    public function suspend(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        
        $tenant->suspend($request->input('reason'));

        return response()->json([
            'message' => 'Tenant suspended',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Reactivate a tenant.
     */
    public function reactivate(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        
        $tenant->reactivate();

        return response()->json([
            'message' => 'Tenant reactivated',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Reset monthly usage for a tenant.
     */
    public function resetUsage(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        
        $tenant->resetUsage();

        return response()->json([
            'message' => 'Usage reset successfully',
            'tenant' => $tenant->fresh(),
        ]);
    }

    /**
     * Get tenant usage stats.
     */
    public function usage(int $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        // Get daily message counts for last 30 days
        $dailyUsage = \App\Models\ChatMessage::where('tenant_id', $tenant->id)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        return response()->json([
            'tenant_id' => $tenant->id,
            'current_period' => [
                'used' => $tenant->messages_used,
                'limit' => $tenant->messages_limit,
                'percentage' => $tenant->getUsagePercentage(),
                'remaining' => $tenant->getRemainingMessages(),
                'reset_at' => $tenant->usage_reset_at,
            ],
            'daily_usage' => $dailyUsage,
            'total_sessions' => \App\Models\ChatSession::where('tenant_id', $tenant->id)->count(),
            'total_messages' => \App\Models\ChatMessage::where('tenant_id', $tenant->id)->count(),
        ]);
    }
}
