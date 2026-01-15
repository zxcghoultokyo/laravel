<?php

namespace App\Models\Traits;

use App\Models\Tenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * Get the current tenant from app context.
     */
    protected static function getCurrentTenant(): ?Tenant
    {
        return app()->bound('current_tenant') ? app('current_tenant') : null;
    }
}
