<?php

namespace App\Livewire\Admin;

use App\Models\RozetkaCategory;
use App\Models\RozetkaCategoryMapping;
use App\Services\Rozetka\RozetkaCategoryService;
use App\Services\Tenant\TenantContext;
use Livewire\Component;

class RozetkaCategoryManager extends Component
{
    public int $rozetkaCategoriesCount = 0;

    public int $mappingsCount = 0;

    public int $confirmedMappingsCount = 0;

    public bool $syncing = false;

    public string $syncMessage = '';

    // Category search
    public string $rozetkaSearch = '';

    public array $rozetkaSearchResults = [];

    // Mapping form
    public string $localCategoryName = '';

    public ?int $localCategoryId = null;

    public string $localCategorySource = 'hprofit';

    public ?int $selectedRozetkaCategoryId = null;

    public string $selectedRozetkaCategoryName = '';

    public string $selectedRozetkaCategoryPath = '';

    // Existing mappings list
    public array $mappings = [];

    public string $mappingFilter = '';

    // HugeProfit categories
    public array $hprofitCategories = [];

    public string $hprofitSearch = '';

    public function mount(): void
    {
        $this->loadStats();
        $this->loadMappings();
    }

    public function loadStats(): void
    {
        $this->rozetkaCategoriesCount = RozetkaCategory::count();
        $tenantId = $this->getCurrentTenantId();
        $this->mappingsCount = RozetkaCategoryMapping::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $this->confirmedMappingsCount = RozetkaCategoryMapping::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_confirmed', true)->count();
    }

    public function syncRozetkaCategories(): void
    {
        $this->syncing = true;
        $this->syncMessage = '';

        try {
            $service = app(RozetkaCategoryService::class);
            $count = $service->syncCategories();
            $this->syncMessage = "✅ Синхронізовано {$count} категорій з Rozetka";
            $this->loadStats();
        } catch (\Throwable $e) {
            $this->syncMessage = "❌ Помилка: {$e->getMessage()}";
        }

        $this->syncing = false;
    }

    public function searchRozetkaCategories(): void
    {
        if (strlen($this->rozetkaSearch) < 2) {
            $this->rozetkaSearchResults = [];

            return;
        }

        $this->rozetkaSearchResults = RozetkaCategory::where('title_ua', 'LIKE', "%{$this->rozetkaSearch}%")
            ->orWhere('title_ru', 'LIKE', "%{$this->rozetkaSearch}%")
            ->orWhere('full_path', 'LIKE', "%{$this->rozetkaSearch}%")
            ->orderBy('level')
            ->orderBy('title_ua')
            ->limit(30)
            ->get()
            ->toArray();
    }

    public function selectRozetkaCategory(int $rozetkaId): void
    {
        $cat = RozetkaCategory::where('rozetka_id', $rozetkaId)->first();

        if ($cat) {
            $this->selectedRozetkaCategoryId = $cat->rozetka_id;
            $this->selectedRozetkaCategoryName = $cat->title_ua;
            $this->selectedRozetkaCategoryPath = $cat->full_path ?? $cat->title_ua;
            $this->rozetkaSearchResults = [];
            $this->rozetkaSearch = '';
        }
    }

    public function saveMapping(): void
    {
        if (empty($this->localCategoryName) || ! $this->selectedRozetkaCategoryId) {
            session()->flash('error', 'Заповніть локальну категорію та оберіть категорію Rozetka');

            return;
        }

        $tenantId = $this->getCurrentTenantId();

        RozetkaCategoryMapping::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'local_category_id' => $this->localCategoryId,
                'local_category_source' => $this->localCategorySource,
            ],
            [
                'local_category_name' => $this->localCategoryName,
                'rozetka_category_id' => $this->selectedRozetkaCategoryId,
                'rozetka_category_name' => $this->selectedRozetkaCategoryName,
                'rozetka_category_path' => $this->selectedRozetkaCategoryPath,
                'is_confirmed' => true,
                'matched_by' => 'manual',
            ],
        );

        $this->resetMappingForm();
        $this->loadMappings();
        $this->loadStats();
        session()->flash('message', '✅ Мепінг збережено');
    }

    public function deleteMapping(int $id): void
    {
        RozetkaCategoryMapping::withoutGlobalScopes()->where('id', $id)->delete();
        $this->loadMappings();
        $this->loadStats();
        session()->flash('message', 'Мепінг видалено');
    }

    public function confirmMapping(int $id): void
    {
        RozetkaCategoryMapping::withoutGlobalScopes()->where('id', $id)->update(['is_confirmed' => true]);
        $this->loadMappings();
        $this->loadStats();
    }

    public function loadMappings(): void
    {
        $tenantId = $this->getCurrentTenantId();
        $query = RozetkaCategoryMapping::withoutGlobalScopes()->where('tenant_id', $tenantId);

        if ($this->mappingFilter) {
            $filter = $this->mappingFilter;
            $query->where(function ($q) use ($filter) {
                $q->where('local_category_name', 'LIKE', "%{$filter}%")
                    ->orWhere('rozetka_category_name', 'LIKE', "%{$filter}%");
            });
        }

        $this->mappings = $query->orderBy('local_category_name')->limit(100)->get()->toArray();
    }

    public function updatedMappingFilter(): void
    {
        $this->loadMappings();
    }

    protected function resetMappingForm(): void
    {
        $this->localCategoryName = '';
        $this->localCategoryId = null;
        $this->localCategorySource = 'hprofit';
        $this->selectedRozetkaCategoryId = null;
        $this->selectedRozetkaCategoryName = '';
        $this->selectedRozetkaCategoryPath = '';
        $this->rozetkaSearch = '';
        $this->rozetkaSearchResults = [];
    }

    protected function getCurrentTenantId(): int
    {
        $ctx = app(TenantContext::class);

        return $ctx->getTenant()?->id ?? 1;
    }

    public function render()
    {
        return view('livewire.admin.rozetka-category-manager')
            ->layout('layouts.admin', ['title' => 'Rozetka Категорії']);
    }
}
