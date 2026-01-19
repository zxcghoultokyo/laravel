<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that resolves the current tenant from request.
 * 
 * Supports multiple resolution strategies:
 * 1. Header: X-Tenant-Slug
 * 2. Query parameter: tenant
 * 3. Subdomain: {tenant}.ailure.ai
 * 4. Route parameter: {tenant}
 * 5. Authenticated user's tenant
 */
class ResolveTenantMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if ($tenant) {
            // Bind tenant to container
            app()->instance('current_tenant', $tenant);
            
            // Check if tenant is active
            if (!$tenant->isActive()) {
                return response()->json([
                    'error' => 'Tenant is not active',
                    'status' => $tenant->status,
                    'message' => $tenant->isTrialExpired() 
                        ? 'Trial period has expired. Please upgrade your plan.'
                        : 'Your account has been suspended.',
                ], 403);
            }
        }

        return $next($request);
    }

    /**
     * Resolve tenant from various sources.
     */
    protected function resolveTenant(Request $request): ?Tenant
    {
        // 1. Widget token (most common for API calls)
        // Token can be api_token from WidgetSettings or tenant slug
        if ($token = $request->header('X-Widget-Token')) {
            // First try api_token in WidgetSettings (preferred - secure random token)
            $widgetSettings = \App\Models\WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('api_token', $token)
                ->first();
            
            if ($widgetSettings && $widgetSettings->tenant_id) {
                $tenant = Tenant::where('id', $widgetSettings->tenant_id)
                    ->where('status', '!=', Tenant::STATUS_CANCELLED)
                    ->first();
                if ($tenant) {
                    return $tenant;
                }
            }
            
            // Fallback: token might be tenant slug (legacy)
            return Tenant::where('slug', $token)
                         ->where('status', '!=', Tenant::STATUS_CANCELLED)
                         ->first();
        }

        // 2. Header-based resolution (for API calls)
        if ($slug = $request->header('X-Tenant-Slug')) {
            return Tenant::where('slug', $slug)
                         ->where('status', '!=', Tenant::STATUS_CANCELLED)
                         ->first();
        }

        // 3. Query parameter (for widget JS)
        if ($slug = $request->query('tenant')) {
            return Tenant::where('slug', $slug)
                         ->where('status', '!=', Tenant::STATUS_CANCELLED)
                         ->first();
        }

        // 4. Query parameter - token (alternative)
        if ($token = $request->query('token')) {
            // Try api_token first
            $widgetSettings = \App\Models\WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('api_token', $token)
                ->first();
            
            if ($widgetSettings && $widgetSettings->tenant_id) {
                return Tenant::where('id', $widgetSettings->tenant_id)
                    ->where('status', '!=', Tenant::STATUS_CANCELLED)
                    ->first();
            }
            
            // Fallback to slug
            return Tenant::where('slug', $token)
                         ->where('status', '!=', Tenant::STATUS_CANCELLED)
                         ->first();
        }

        // 5. Route parameter
        if ($slug = $request->route('tenant')) {
            return Tenant::where('slug', $slug)
                         ->where('status', '!=', Tenant::STATUS_CANCELLED)
                         ->first();
        }

        // 6. Subdomain-based resolution
        $host = $request->getHost();
        if (preg_match('/^([a-z0-9-]+)\.(ailure\.ai|app\.ailure\.ai)$/', $host, $matches)) {
            $slug = $matches[1];
            if (!in_array($slug, ['www', 'api', 'app', 'chat', 'admin'])) {
                return Tenant::where('slug', $slug)
                             ->where('status', '!=', Tenant::STATUS_CANCELLED)
                             ->first();
            }
        }

        // 7. Authenticated user's tenant
        if ($user = $request->user()) {
            if ($user->tenant_id) {
                return Tenant::find($user->tenant_id);
            }
        }

        return null;
    }
}
