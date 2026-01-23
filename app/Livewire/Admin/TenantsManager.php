<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Models\Subscription;
use App\Models\User;
use App\Jobs\SyncHoroshopProductsJob;
use App\Services\Usage\UsageTrackingService;
use Illuminate\Support\Facades\Cache;
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
    
    // Trial management
    public ?int $extendTrialTenantId = null;
    public int $extendTrialDays = 7;

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
            'plan' => $tenant->plan ?? 'trial',
            'messages_limit' => $tenant->messages_limit,
            'products_limit' => $tenant->products_limit,
            // Trial settings
            'trial_ends_at' => $tenant->trial_ends_at?->format('Y-m-d\TH:i'),
            'has_trial' => (bool) $tenant->trial_ends_at,
            // Paid plan settings
            'plan_expires_at' => $tenant->plan_expires_at?->format('Y-m-d\TH:i'),
            'has_paid_subscription' => (bool) $tenant->plan_expires_at,
        ];
    }

    public function update()
    {
        $this->validate([
            'editForm.name' => 'required|string|max:255',
            'editForm.slug' => 'required|string|max:100',
            'editForm.status' => 'required|in:active,suspended,trial',
            'editForm.plan' => 'required|in:trial,starter,pro,enterprise',
            'editForm.messages_limit' => 'nullable|integer|min:0|max:2147483647',
            'editForm.products_limit' => 'nullable|integer|min:0|max:2147483647',
            'editForm.trial_ends_at' => 'nullable|date',
            'editForm.plan_expires_at' => 'nullable|date',
        ]);

        $tenant = Tenant::findOrFail($this->editingTenantId);
        
        // Parse trial_ends_at (for trial plan)
        $trialEndsAt = null;
        if (!empty($this->editForm['has_trial']) && !empty($this->editForm['trial_ends_at'])) {
            $trialEndsAt = \Carbon\Carbon::parse($this->editForm['trial_ends_at']);
        }
        
        // Parse plan_expires_at (for paid plans)
        $planExpiresAt = null;
        if (!empty($this->editForm['has_paid_subscription']) && !empty($this->editForm['plan_expires_at'])) {
            $planExpiresAt = \Carbon\Carbon::parse($this->editForm['plan_expires_at']);
        }
        
        $tenant->update([
            'name' => $this->editForm['name'],
            'slug' => $this->editForm['slug'],
            'domain' => $this->editForm['domain'],
            'status' => $this->editForm['status'],
            'plan' => $this->editForm['plan'],
            'messages_limit' => $this->editForm['messages_limit'],
            'products_limit' => $this->editForm['products_limit'],
            'trial_ends_at' => $trialEndsAt,
            'plan_expires_at' => $planExpiresAt,
        ]);
        
        // Force refresh to verify
        $tenant->refresh();
        
        \Illuminate\Support\Facades\Log::info('Tenant updated', [
            'id' => $tenant->id,
            'plan_saved' => $tenant->plan,
            'status' => $tenant->status,
            'trial_ends_at' => $tenant->trial_ends_at,
            'plan_expires_at' => $tenant->plan_expires_at,
        ]);

        // Also update subscription plan if exists (for consistency)
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

    /**
     * Remove trial from tenant (set trial_ends_at to null).
     */
    public function removeTrial(int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['trial_ends_at' => null]);
        session()->flash('success', "Тріал для {$tenant->name} видалено");
    }

    /**
     * Open extend trial modal.
     */
    public function openExtendTrialModal(int $id)
    {
        $this->extendTrialTenantId = $id;
        $this->extendTrialDays = 7;
    }

    /**
     * Close extend trial modal.
     */
    public function closeExtendTrialModal()
    {
        $this->extendTrialTenantId = null;
        $this->extendTrialDays = 7;
    }

    /**
     * Extend trial by specified days.
     */
    public function extendTrial()
    {
        $tenant = Tenant::findOrFail($this->extendTrialTenantId);
        
        // Calculate new trial end date
        $baseDate = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture() 
            ? $tenant->trial_ends_at 
            : now();
        
        $newTrialEndsAt = $baseDate->copy()->addDays($this->extendTrialDays);
        
        $tenant->update(['trial_ends_at' => $newTrialEndsAt]);
        
        $this->closeExtendTrialModal();
        session()->flash('success', "Тріал для {$tenant->name} продовжено до {$newTrialEndsAt->format('d.m.Y H:i')}");
    }

    /**
     * Quick add trial for specific days.
     */
    public function quickAddTrial(int $id, int $days)
    {
        $tenant = Tenant::findOrFail($id);
        
        // Calculate from now (fresh trial)
        $newTrialEndsAt = now()->addDays($days);
        
        $tenant->update(['trial_ends_at' => $newTrialEndsAt]);
        
        session()->flash('success', "Тріал {$days} днів для {$tenant->name} активовано до {$newTrialEndsAt->format('d.m.Y')}");
    }

    /**
     * Start sync for a tenant.
     */
    public function startSync(int $id)
    {
        $tenant = Tenant::findOrFail($id);
        
        if ($tenant->platform !== 'horoshop') {
            session()->flash('error', "Синхронізація доступна тільки для Horoshop");
            return;
        }
        
        if (empty($tenant->platform_credentials)) {
            session()->flash('error', "API credentials не налаштовані");
            return;
        }
        
        // Check if sync already running
        $cacheKey = "sync_running_{$tenant->id}";
        if (Cache::get($cacheKey)) {
            session()->flash('warning', "Синхронізація вже запущена");
            return;
        }
        
        // Mark sync as running
        Cache::put($cacheKey, true, 3600); // 1 hour max
        
        // Dispatch sync job
        SyncHoroshopProductsJob::dispatch($tenant->id);
        
        session()->flash('success', "Синхронізацію {$tenant->name} запущено");
    }

    /**
     * Cancel running sync for a tenant.
     */
    public function cancelSync(int $id)
    {
        $tenant = Tenant::findOrFail($id);
        
        // Remove sync flag from cache
        $cacheKey = "sync_running_{$tenant->id}";
        Cache::forget($cacheKey);
        
        // Note: The actual job may continue, but next iteration will check this flag
        // and stop gracefully
        
        session()->flash('success', "Синхронізацію {$tenant->name} скасовано");
    }

    /**
     * Check sync status for a tenant.
     */
    public function getSyncStatus(int $id): array
    {
        $tenant = Tenant::findOrFail($id);
        
        $cacheKey = "sync_running_{$tenant->id}";
        $isRunning = Cache::get($cacheKey, false);
        
        return [
            'is_running' => $isRunning,
            'products_count' => $tenant->products()->count(),
            'last_sync' => $tenant->last_sync_at?->diffForHumans(),
            'platform' => $tenant->platform,
            'has_credentials' => !empty($tenant->platform_credentials),
        ];
    }

    /**
     * Clear all products for a tenant (for re-sync).
     */
    public function clearProducts(int $id)
    {
        $tenant = Tenant::findOrFail($id);
        
        $count = $tenant->products()->count();
        $tenant->products()->delete();
        
        session()->flash('success', "Видалено {$count} товарів з {$tenant->name}");
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

        // Dispatch onboarding job (sync products, categories, AI, Meili)
        \App\Jobs\OnboardTenantJob::dispatch($tenant->id)->onQueue('default');

        $this->showCreateModal = false;
        session()->flash('success', "Тенант {$tenant->name} створено! Онбординг запущено в фоні.");
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
