<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Usage\UsageTrackingService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tenants Manager - SuperAdmin panel for managing all tenants.
 */
class TenantsManager extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $planFilter = '';
    public string $sortBy = 'created_at';
    public string $sortDir = 'desc';

    public ?int $editingTenantId = null;
    public array $editForm = [];
    public bool $showCreateModal = false;
    public array $createForm = [];

    protected $queryString = ['search', 'statusFilter', 'planFilter'];

    public function mount()
    {
        $this->createForm = $this->getEmptyForm();
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function sortBy(string $field)
    {
        if ($this->sortBy === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDir = 'asc';
        }
    }

    public function edit(int $id)
    {
        $tenant = Tenant::with('owner', 'subscription')->findOrFail($id);
        
        $this->editingTenantId = $id;
        $this->editForm = [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $tenant->domain,
            'status' => $tenant->status,
            'plan' => $tenant->subscription?->plan ?? 'trial',
            'messages_limit' => $tenant->messages_limit,
            'products_limit' => $tenant->products_limit,
        ];
    }

    public function update()
    {
        $this->validate([
            'editForm.name' => 'required|string|max:255',
            'editForm.slug' => 'required|string|max:100',
            'editForm.status' => 'required|in:active,suspended,trial',
        ]);

        $tenant = Tenant::findOrFail($this->editingTenantId);
        
        $tenant->update([
            'name' => $this->editForm['name'],
            'slug' => $this->editForm['slug'],
            'domain' => $this->editForm['domain'],
            'status' => $this->editForm['status'],
            'messages_limit' => $this->editForm['messages_limit'],
            'products_limit' => $this->editForm['products_limit'],
        ]);

        // Update subscription plan if changed
        if ($tenant->subscription && $tenant->subscription->plan !== $this->editForm['plan']) {
            $tenant->subscription->update(['plan' => $this->editForm['plan']]);
        }

        $this->editingTenantId = null;
        $this->dispatch('tenant-updated');
        session()->flash('success', 'Тенант оновлено!');
    }

    public function cancelEdit()
    {
        $this->editingTenantId = null;
        $this->editForm = [];
    }

    public function suspend(int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => 'suspended']);
        session()->flash('success', "Тенант {$tenant->name} призупинено");
    }

    public function reactivate(int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => 'active']);
        session()->flash('success', "Тенант {$tenant->name} активовано");
    }

    public function resetUsage(int $id)
    {
        $tenant = Tenant::findOrFail($id);
        app(UsageTrackingService::class)->resetMonthlyUsage($tenant);
        session()->flash('success', "Лічильники {$tenant->name} скинуто");
    }

    public function openCreateModal()
    {
        $this->createForm = $this->getEmptyForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal()
    {
        $this->showCreateModal = false;
        $this->createForm = $this->getEmptyForm();
    }

    public function create()
    {
        $this->validate([
            'createForm.name' => 'required|string|max:255',
            'createForm.slug' => 'required|string|max:100|unique:tenants,slug',
            'createForm.owner_email' => 'required|email',
            'createForm.owner_name' => 'required|string|max:255',
        ]);

        // Create tenant
        $tenant = Tenant::create([
            'name' => $this->createForm['name'],
            'slug' => $this->createForm['slug'],
            'domain' => $this->createForm['domain'] ?: null,
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(14),
            'messages_limit' => 100,
            'products_limit' => 100,
            'api_key' => 'tk_' . bin2hex(random_bytes(16)),
        ]);

        // Create owner user
        $user = User::create([
            'name' => $this->createForm['owner_name'],
            'email' => $this->createForm['owner_email'],
            'password' => bcrypt($this->createForm['owner_password'] ?: 'password123'),
            'tenant_id' => $tenant->id,
            'role' => User::ROLE_OWNER,
            'email_verified_at' => now(),
        ]);

        // Create trial subscription
        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => 'trial',
            'status' => 'active',
            'started_at' => now(),
            'ends_at' => now()->addDays(14),
        ]);

        $this->showCreateModal = false;
        session()->flash('success', "Тенант {$tenant->name} створено!");
    }

    private function getEmptyForm(): array
    {
        return [
            'name' => '',
            'slug' => '',
            'domain' => '',
            'owner_email' => '',
            'owner_name' => '',
            'owner_password' => '',
        ];
    }

    public function render()
    {
        $query = Tenant::with(['owner', 'subscription'])
            ->withCount(['chatSessions', 'products']);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('slug', 'like', "%{$this->search}%")
                  ->orWhere('domain', 'like', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->planFilter) {
            $query->whereHas('subscription', fn($q) => $q->where('plan', $this->planFilter));
        }

        $query->orderBy($this->sortBy, $this->sortDir);

        $tenants = $query->paginate(15);

        // Stats
        $stats = [
            'total' => Tenant::count(),
            'active' => Tenant::where('status', 'active')->count(),
            'trial' => Tenant::where('status', 'trial')->count(),
            'suspended' => Tenant::where('status', 'suspended')->count(),
        ];

        $plans = config('billing.plans', []);

        return view('livewire.admin.tenants-manager', [
            'tenants' => $tenants,
            'stats' => $stats,
            'plans' => $plans,
        ])->layout('admin.layout');
    }
}
