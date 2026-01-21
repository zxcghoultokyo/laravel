<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * TenantContext - Single source of truth for current tenant context.
 * 
 * Use this service instead of directly accessing tenant from:
 * - request()->input('tenant_id')
 * - session()->get('tenant_id')
 * - auth()->user()->tenant_id
 * - app('current_tenant')
 * 
 * Note: For Livewire components, prefer getting tenant directly from auth()->user()
 * as the singleton may hold stale data between requests.
 * 
 * Usage:
 *   $context = app(TenantContext::class);
 *   $tenantId = $context->getTenantId();      // int|null
 *   $tenant = $context->getTenant();           // Tenant|null
 *   $merchantId = $context->getMerchantId();   // string|null (slug for legacy tables)
 */
class TenantContext
{
    private ?Tenant $tenant = null;
    private ?int $tenantId = null;
    private bool $resolved = false;
    private ?string $lastRequestId = null;

    /**
     * Get current tenant ID (integer).
     * Returns null if no tenant context (super admin or public).
     */
    public function getTenantId(): ?int
    {
        $this->resolveIfNeeded();
        return $this->tenantId;
    }

    /**
     * Get current Tenant model.
     */
    public function getTenant(): ?Tenant
    {
        $this->resolveIfNeeded();
        return $this->tenant;
    }

    /**
     * Get merchant_id (tenant slug) for legacy analytics tables.
     * Legacy tables like chat_events, chat_conversions use string merchant_id.
     */
    public function getMerchantId(): ?string
    {
        $this->resolveIfNeeded();
        return $this->tenant?->slug;
    }

    /**
     * Check if we have a tenant context.
     */
    public function hasTenant(): bool
    {
        $this->resolveIfNeeded();
        return $this->tenant !== null;
    }

    /**
     * Check if current user is super admin (no tenant restriction).
     */
    public function isSuperAdmin(): bool
    {
        $user = Auth::user();
        return $user && $user->isSuperAdmin();
    }

    /**
     * Set tenant explicitly (useful for jobs, testing).
     */
    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;
        $this->tenantId = $tenant?->id;
        $this->resolved = true;
        return $this;
    }

    /**
     * Set tenant by ID.
     */
    public function setTenantId(?int $tenantId): self
    {
        if ($tenantId === null) {
            $this->tenant = null;
            $this->tenantId = null;
        } else {
            $this->tenant = Tenant::find($tenantId);
            $this->tenantId = $this->tenant?->id;
        }
        $this->resolved = true;
        return $this;
    }

    /**
     * Clear resolved tenant (for testing).
     */
    public function clear(): self
    {
        $this->tenant = null;
        $this->tenantId = null;
        $this->resolved = false;
        $this->lastRequestId = null;
        return $this;
    }

    /**
     * Check if we need to re-resolve (new request).
     */
    private function resolveIfNeeded(): void
    {
        // Get current request ID to detect new requests
        $currentRequestId = null;
        try {
            $currentRequestId = request()->fingerprint() ?? spl_object_id(request());
        } catch (\Throwable $e) {
            // In console or testing, just use resolved flag
        }
        
        // If this is a new request, clear the cache
        if ($currentRequestId !== null && $this->lastRequestId !== $currentRequestId) {
            $this->resolved = false;
            $this->lastRequestId = $currentRequestId;
        }
        
        $this->resolve();
    }

    /**
     * Resolve tenant from various sources (lazy, cached per request).
     */
    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        // Priority 1: App binding from ResolveTenantMiddleware (API/widget calls)
        if (app()->bound('current_tenant')) {
            $tenant = app('current_tenant');
            if ($tenant instanceof Tenant) {
                $this->tenant = $tenant;
                $this->tenantId = $tenant->id;
                return;
            }
        }

        // Priority 2: Authenticated user's tenant
        $user = Auth::user();
        if ($user) {
            // Super admin can switch tenants via session
            if ($user->isSuperAdmin() && session()->has('admin_active_tenant_id')) {
                $tenantId = (int) session()->get('admin_active_tenant_id');
                $this->tenant = Tenant::find($tenantId);
                $this->tenantId = $this->tenant?->id;
                return;
            }

            // Regular user - use their tenant
            if ($user->tenant_id) {
                $this->tenant = $user->tenant;
                $this->tenantId = $user->tenant_id;
                return;
            }

            // Super admin without active tenant - no restriction
            if ($user->isSuperAdmin()) {
                return;
            }
        }

        // Priority 3: Request parameter (for API calls)
        if (request()->has('tenant_id')) {
            $tenantId = (int) request()->input('tenant_id');
            $this->tenant = Tenant::find($tenantId);
            $this->tenantId = $this->tenant?->id;
            return;
        }

        // Priority 4: Session
        if (session()->has('tenant_id')) {
            $tenantId = (int) session()->get('tenant_id');
            $this->tenant = Tenant::find($tenantId);
            $this->tenantId = $this->tenant?->id;
            return;
        }

        // No tenant context found
    }
}
