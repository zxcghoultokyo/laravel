<?php

namespace App\Models\Scopes;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that automatically filters queries by the current tenant.
 */
class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Skip if no tenant is set in context
        if (!app()->bound('current_tenant')) {
            return;
        }

        $tenant = app('current_tenant');
        
        if ($tenant instanceof Tenant) {
            $builder->where($model->getTable() . '.tenant_id', $tenant->id);
        }
    }
}
