<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use App\Services\Tenant\TenantContext;

/**
 * TenantScope automatically filters queries by tenant_id.
 * 
 * This ensures data isolation between tenants without manual WHERE clauses.
 * Uses TenantContext as single source of truth for current tenant.
 * 
 * Usage:
 *   - Applied automatically to models with TenantScope
 *   - To bypass: Model::withoutGlobalScope(TenantScope::class)->get()
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
        // Use TenantContext as single source of truth
        $context = app(TenantContext::class);
        $tenantId = $context->getTenantId();
        
        if ($tenantId !== null) {
            $builder->where($model->getTable() . '.tenant_id', $tenantId);
        }
        
        // If no tenant context, don't filter (for super admin or system jobs)
        // This allows artisan commands and queue workers to work with all data
    }
}
