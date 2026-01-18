<?php

namespace App\Models\Traits;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Trait for models that belong to a tenant.
 * Automatically applies tenant filtering when a tenant is set in context.
 */
trait BelongsToTenant
{
    /**
     * Boot the trait.
     */
    public static function bootBelongsToTenant(): void
    {
        // Automatically set tenant_id when creating
        static::creating(function ($model) {
            if (!$model->tenant_id && $tenant = static::getCurrentTenant()) {
                $model->tenant_id = $tenant->id;
            }
        });

        // Apply global scope for tenant filtering
        static::addGlobalScope(new TenantScope());
    }

    /**
     * Initialize the trait.
     */
    public function initializeBelongsToTenant(): void
    {
        // Ensure tenant_id is fillable
        if (!in_array('tenant_id', $this->fillable)) {
            $this->fillable[] = 'tenant_id';
        }
    }

    /**
     * Get the tenant that owns this model.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scope to a specific tenant.
     */
    public function scopeForTenant($query, Tenant|int $tenant): mixed
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to exclude tenant filtering.
     */
    public function scopeWithoutTenant($query): mixed
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Get the current tenant from auth/session context.
     */
    protected static function getCurrentTenant(): ?Tenant
    {
        // 1. Authenticated user's tenant
        if (Auth::check()) {
            $user = Auth::user();
            
            // Super admin with active tenant context
            if ($user->role === 'super_admin' && session()->has('admin_active_tenant_id')) {
                return Tenant::find(session()->get('admin_active_tenant_id'));
            }
            
            // Regular user's tenant
            if ($user->tenant_id) {
                return $user->tenant;
            }
        }
        
        // 2. App binding (for jobs/commands)
        if (app()->bound('current_tenant')) {
            return app('current_tenant');
        }
        
        return null;
    }
}
