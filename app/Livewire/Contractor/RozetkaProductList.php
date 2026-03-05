<?php

namespace App\Livewire\Contractor;

use App\Models\RozetkaCategory;
use App\Models\RozetkaProduct;
use App\Services\Rozetka\RozetkaAttributeService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('contractor.layout')]
class RozetkaProductList extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public string $statusFilter = '';

    public string $stockFilter = '';

    public string $categoryFilter = '';

    // Sync state
    public bool $syncing = false;

    public string $syncMessage = '';

    public int $syncPercent = 0;

    public int $syncedCount = 0;

    // Expanded product card
    public ?int $expandedProductId = null;

    // Category editing
    public string $categorySearch = '';

    public array $categorySearchResults = [];

    public ?int $editingCategoryProductId = null;

    // Attribute editing
    public array $productAttributes = [];

    public array $categoryAttributes = [];

    protected int $tenantId = 2;

    protected $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'stockFilter' => ['except' => ''],
    ];

    public function mount(): void
    {
        // Check if we have any products synced
        $count = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();

        if ($count === 0) {
            $this->syncMessage = 'Товари ще не завантажені. Натисніть "Синхронізувати" для завантаження з Розетки.';
        }
    }

    public function syncProducts(): void
    {
        $this->syncing = true;
        $this->syncMessage = 'Завантаження товарів з Розетки запущено у фоні...';

        \App\Jobs\SyncRozetkaProductsJob::dispatch($this->tenantId);

        \Illuminate\Support\Facades\Cache::put("rozetka_sync_status_{$this->tenantId}", [
            'status' => 'running',
            'message' => 'Завантаження товарів з Розетки...',
        ], 600);
    }

    public function checkSyncStatus(): void
    {
        $status = \Illuminate\Support\Facades\Cache::get("rozetka_sync_status_{$this->tenantId}");

        if (! $status) {
            return;
        }

        $this->syncMessage = $status['message'];
        $this->syncPercent = $status['percent'] ?? 0;
        $this->syncedCount = $status['synced'] ?? 0;

        if (in_array($status['status'], ['done', 'error'])) {
            $this->syncing = false;
            \Illuminate\Support\Facades\Cache::forget("rozetka_sync_status_{$this->tenantId}");
        }
    }

    public function toggleProduct(int $productId): void
    {
        if ($this->expandedProductId === $productId) {
            $this->expandedProductId = null;
            $this->categoryAttributes = [];
            $this->productAttributes = [];
            $this->editingCategoryProductId = null;

            return;
        }

        $this->expandedProductId = $productId;
        $this->editingCategoryProductId = null;
        $this->categorySearch = '';
        $this->categorySearchResults = [];

        $this->loadProductAttributes($productId);
    }

    public function startCategoryEdit(int $productId): void
    {
        $this->editingCategoryProductId = $productId;
        $this->categorySearch = '';
        $this->categorySearchResults = [];
    }

    public function updatedCategorySearch(): void
    {
        if (mb_strlen($this->categorySearch) < 2) {
            $this->categorySearchResults = [];

            return;
        }

        $this->categorySearchResults = RozetkaCategory::query()
            ->where(function ($q) {
                $q->where('title_ua', 'like', '%'.$this->categorySearch.'%')
                    ->orWhere('full_path', 'like', '%'.$this->categorySearch.'%');
            })
            ->limit(15)
            ->get(['rozetka_id', 'title_ua', 'full_path'])
            ->toArray();
    }

    public function assignCategory(int $productId, int $rozetkaCategoryId, string $categoryName): void
    {
        RozetkaProduct::withoutGlobalScopes()
            ->where('id', $productId)
            ->update([
                'rozetka_category_id' => $rozetkaCategoryId,
                'rozetka_category_name' => $categoryName,
            ]);

        $this->editingCategoryProductId = null;
        $this->categorySearch = '';
        $this->categorySearchResults = [];

        // Reload attributes for new category
        $this->loadProductAttributes($productId);
    }

    public function saveAttribute(int $productId, int $attributeId, string $attributeName, ?string $valueId, ?string $valueText): void
    {
        $product = RozetkaProduct::withoutGlobalScopes()->find($productId);
        if (! $product) {
            return;
        }

        $product->attributeValues()->updateOrCreate(
            ['attribute_id' => $attributeId],
            [
                'attribute_name' => $attributeName,
                'value_id' => $valueId ?: null,
                'value_text' => $valueText ?: null,
            ]
        );

        $this->loadProductAttributes($productId);
    }

    protected function loadProductAttributes(int $productId): void
    {
        $product = RozetkaProduct::withoutGlobalScopes()
            ->with('attributeValues')
            ->find($productId);

        if (! $product || ! $product->rozetka_category_id) {
            $this->categoryAttributes = [];
            $this->productAttributes = [];

            return;
        }

        // Fetch category attributes (from cache or API)
        $attrService = app(RozetkaAttributeService::class);
        $attrs = $attrService->getAttributesForCategory($product->rozetka_category_id);

        $this->categoryAttributes = $attrs->map(function ($attr) {
            return [
                'id' => $attr->attribute_id,
                'name' => $attr->name,
                'attr_type' => $attr->attr_type,
                'filter_type' => $attr->filter_type,
                'unit' => $attr->unit,
                'is_global' => $attr->is_global,
                'values' => $attr->values ?? [],
            ];
        })->toArray();

        // Load saved values
        $savedValues = $product->attributeValues->keyBy('attribute_id');
        $this->productAttributes = [];
        foreach ($savedValues as $attrId => $val) {
            $this->productAttributes[$attrId] = [
                'value_id' => $val->value_id,
                'value_text' => $val->value_text,
            ];
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStockFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId);

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

        // Sort: in stock first, then by title
        $query->orderByDesc('in_stock')->orderBy('title');

        $products = $query->paginate(25);

        // Stats
        $totalProducts = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->count();
        $inStockCount = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->where('in_stock', true)->count();
        $withCategoryCount = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->whereNotNull('rozetka_category_id')->count();

        return view('livewire.contractor.rozetka-product-list', [
            'products' => $products,
            'totalProducts' => $totalProducts,
            'inStockCount' => $inStockCount,
            'withCategoryCount' => $withCategoryCount,
        ]);
    }
}
