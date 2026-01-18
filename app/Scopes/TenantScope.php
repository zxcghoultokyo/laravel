<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * TenantScope automatically filters queries by tenant_id.
 * 
 * This ensures data isolation between tenants without manual WHERE clauses.
 * 
 * Usage:
 *   - Applied automatically to Product model
 *   - To bypass: Product::withoutGlobalScope(TenantScope::class)->get()
 *   - For admin queries: Product::withoutGlobalScope('tenant')->get()
 * 
 * Super Admin Behavior:
 *   - If session has 'admin_active_tenant_id', filters by that tenant
 *   - Otherwise, sees all data (no filter applied)
 */
class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = $this->resolveTenantId();
        
        if ($tenantId !== null) {
            $builder->where($model->getTable() . '.tenant_id', $tenantId);
        }
        
        // If no tenant context, don't filter (for super admin or system jobs)
        // This allows artisan commands and queue workers to work with all data
    }

    /**
     * Resolve the current tenant ID from various sources.
     */
    protected function resolveTenantId(): ?int
    {
        // 1. Check authenticated user
        if (Auth::check()) {
            $user = Auth::user();
            
            // Super admin can work in context of specific tenant
            if ($user->role === 'super_admin') {
                // Check if super admin has selected an active tenant to work with
                if (session()->has('admin_active_tenant_id')) {
                    return (int) session()->get('admin_active_tenant_id');
                }
                // Otherwise super admin sees all data
                return null;
            }
            
            // Regular user scoped to their tenant
            if ($user->tenant_id) {
                return (int) $user->tenant_id;
            }
        }
        
        // 2. Check request context (for widget/API calls)
        if (request()->has('tenant_id')) {
            return (int) request()->input('tenant_id');
        }
        
        // 3. Check session context (for non-auth contexts)
        if (session()->has('tenant_id')) {
            return (int) session()->get('tenant_id');
        }
        
        // 4. Check app binding (for jobs/commands that set tenant context)
        if (app()->bound('current_tenant_id')) {
            return app('current_tenant_id');
        }
        
        // No tenant context - don't apply filter
        // This is important for:
        // - Artisan commands (sync all tenants)
        // - Queue workers
        // - Super admin operations
        return null;
    }
}
