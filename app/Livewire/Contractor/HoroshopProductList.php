<?php

namespace App\Livewire\Contractor;

use App\Models\HoroshopProduct;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('contractor.layout')]
class HoroshopProductList extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public string $stockFilter = '';

    public string $matchFilter = '';

    // Sync state
    public bool $syncing = false;

    public string $syncMessage = '';

    public int $syncTotal = 0;

    // Expanded product
    public ?int $expandedProductId = null;

    protected int $tenantId = 2;

    public function mount(): void
    {
        $count = HoroshopProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();

        if ($count === 0) {
            $this->syncMessage = 'Товари ще не завантажені. Натисніть "Синхронізувати Хорошоп" для завантаження.';
        }

        // Check if sync is already running
        $status = Cache::get("horoshop_catalog_sync_status_{$this->tenantId}");
        if ($status && $status['status'] === 'running') {
            $this->syncing = true;
            $this->syncMessage = 'Синхронізація вже запущена...';
        }
    }

    // ── Sync ──

    public function syncCatalog(): void
    {
        $this->syncing = true;
        $this->syncMessage = 'Завантаження каталогу Хорошоп запущено у фоні...';

        \App\Jobs\SyncHoroshopCatalogJob::dispatch($this->tenantId);
    }

    public function checkSyncStatus(): void
    {
        $status = Cache::get("horoshop_catalog_sync_status_{$this->tenantId}");

        if (! $status) {
            return;
        }

        if ($status['status'] === 'running') {
            $progress = Cache::get("horoshop_catalog_sync_progress_{$this->tenantId}");
            if ($progress) {
                $this->syncTotal = $progress['total'] ?? 0;
                $this->syncMessage = "Синхронізовано {$this->syncTotal} товарів...";
            }
        } elseif ($status['status'] === 'completed') {
            $result = $status['result'] ?? [];
            $this->syncing = false;
            $this->syncMessage = sprintf(
                'Готово! Всього: %d, нових: %d, оновлено: %d, зв\'язано з Розеткою: %d',
                $result['total'] ?? 0,
                $result['created'] ?? 0,
                $result['updated'] ?? 0,
                $result['matched'] ?? 0
            );
            Cache::forget("horoshop_catalog_sync_status_{$this->tenantId}");
            Cache::forget("horoshop_catalog_sync_progress_{$this->tenantId}");
        } elseif ($status['status'] === 'failed') {
            $this->syncing = false;
            $this->syncMessage = 'Помилка синхронізації: '.($status['error'] ?? 'Невідома помилка');
            Cache::forget("horoshop_catalog_sync_status_{$this->tenantId}");
        }
    }

    // ── Product card ──

    public function toggleProduct(int $productId): void
    {
        $this->expandedProductId = $this->expandedProductId === $productId ? null : $productId;
    }

    // ── Pagination resets ──

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStockFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMatchFilter(): void
    {
        $this->resetPage();
    }

    // ── Render ──

    public function render()
    {
        $query = HoroshopProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->with('rozetkaProduct');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('article', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->stockFilter === 'in_stock') {
            $query->where('in_stock', true);
        } elseif ($this->stockFilter === 'out_of_stock') {
            $query->where('in_stock', false);
        }

        if ($this->matchFilter === 'matched') {
            $query->whereNotNull('rozetka_product_id');
        } elseif ($this->matchFilter === 'unmatched') {
            $query->whereNull('rozetka_product_id');
        }

        $query->orderByDesc('in_stock')->orderBy('title');

        $products = $query->paginate(25);

        $base = HoroshopProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId);

        $totalProducts = (clone $base)->count();
        $inStockCount = (clone $base)->where('in_stock', true)->count();
        $matchedCount = (clone $base)->whereNotNull('rozetka_product_id')->count();
        $unmatchedCount = $totalProducts - $matchedCount;

        return view('livewire.contractor.horoshop-product-list', [
            'products' => $products,
            'totalProducts' => $totalProducts,
            'inStockCount' => $inStockCount,
            'matchedCount' => $matchedCount,
            'unmatchedCount' => $unmatchedCount,
        ]);
    }
}
