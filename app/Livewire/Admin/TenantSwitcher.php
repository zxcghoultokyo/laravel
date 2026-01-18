<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Tenant Switcher for Super Admin
 * Allows switching context to view/manage data for a specific tenant.
 */
class TenantSwitcher extends Component
{
    public ?int $selectedTenantId = null;
    public bool $showDropdown = false;

    public function mount()
    {
        // Load current selection from session
        $this->selectedTenantId = session('admin_active_tenant_id');
    }

    public function getTenantsProperty()
    {
        if (!Auth::user()?->isSuperAdmin()) {
            return collect();
        }

        return Tenant::orderBy('name')->get(['id', 'name', 'domain', 'plan']);
    }

    public function getCurrentTenantProperty()
    {
        if ($this->selectedTenantId) {
            return Tenant::find($this->selectedTenantId);
        }
        return null;
    }

    public function selectTenant(?int $tenantId)
    {
        $this->selectedTenantId = $tenantId;
        
        if ($tenantId) {
            session(['admin_active_tenant_id' => $tenantId]);
        } else {
            session()->forget('admin_active_tenant_id');
        }
        
        $this->showDropdown = false;
        
        // Refresh the page to apply new tenant context
        return redirect(request()->header('Referer', route('admin.dashboard')));
    }

    public function toggleDropdown()
    {
        $this->showDropdown = !$this->showDropdown;
    }

    public function render()
    {
        // Only render for super admin
        if (!Auth::user()?->isSuperAdmin()) {
            return '';
        }

        return view('livewire.admin.tenant-switcher');
    }
}
