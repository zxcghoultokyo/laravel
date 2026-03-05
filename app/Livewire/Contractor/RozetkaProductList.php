<?php

namespace App\Livewire\Contractor;

use App\Models\Product;
use App\Models\RozetkaCategory;
use App\Models\RozetkaCategoryAttribute;
use App\Models\RozetkaProduct;
use App\Services\Rozetka\RozetkaAttributeService;
use App\Services\Rozetka\RozetkaProductService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('contractor.layout')]
class RozetkaProductList extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';

    public string $stockFilter = '';

    public string $uploadStatusFilter = '';

    public string $matchFilter = '';

    public string $exportStatusFilter = '';

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

    // Push to Rozetka feedback
    public string $pushMessage = '';

    public bool $pushSuccess = false;

    protected int $tenantId = 2;

    protected $queryString = [
        'search' => ['except' => ''],
        'stockFilter' => ['except' => ''],
    ];

    public function mount(): void
    {
        $count = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('price_offer_id')
            ->where('is_duplicate', false)
            ->count();

        if ($count === 0) {
            $this->syncMessage = 'Товари ще не завантажені. Натисніть "Синхронізувати" для завантаження з Розетки.';
        }
    }

    // ── Sync ──

    public function syncProducts(): void
    {
        $this->syncing = true;
        $this->syncMessage = 'Завантаження товарів з Розетки запущено у фоні...';

        \App\Jobs\SyncRozetkaProductsJob::dispatch($this->tenantId);

        Cache::put("rozetka_sync_status_{$this->tenantId}", [
            'status' => 'running',
            'message' => 'Завантаження товарів з Розетки...',
            'synced' => 0,
            'percent' => 0,
        ], 600);
    }

    public function checkSyncStatus(): void
    {
        $status = Cache::get("rozetka_sync_status_{$this->tenantId}");

        if (! $status) {
            return;
        }

        $this->syncMessage = $status['message'];
        $this->syncPercent = $status['percent'] ?? 0;
        $this->syncedCount = $status['synced'] ?? 0;

        if (in_array($status['status'], ['done', 'error'])) {
            $this->syncing = false;
            Cache::forget("rozetka_sync_status_{$this->tenantId}");
        }
    }

    // ── Product card ──

    public function toggleProduct(int $productId): void
    {
        if ($this->expandedProductId === $productId) {
            $this->expandedProductId = null;
            $this->categoryAttributes = [];
            $this->productAttributes = [];
            $this->editingCategoryProductId = null;
            $this->pushMessage = '';

            return;
        }

        $this->expandedProductId = $productId;
        $this->editingCategoryProductId = null;
        $this->categorySearch = '';
        $this->categorySearchResults = [];
        $this->pushMessage = '';

        $this->loadProductAttributes($productId);
    }

    // ── Category editing ──

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

        $this->loadProductAttributes($productId);
    }

    // ── Attributes ──

    public function saveProductField(int $productId, string $field, ?string $value): void
    {
        $allowedFields = ['title', 'description', 'description_ua', 'price', 'price_old', 'quantity', 'producer_name'];
        if (! in_array($field, $allowedFields)) {
            return;
        }

        $product = RozetkaProduct::withoutGlobalScopes()->find($productId);
        if (! $product) {
            return;
        }

        $edited = $product->edited_fields ?? [];
        $edited[$field] = $value;

        $product->update([
            $field => $value,
            'edited_fields' => $edited,
            'has_local_changes' => true,
        ]);
    }

    public function discardChanges(int $productId): void
    {
        $product = RozetkaProduct::withoutGlobalScopes()->find($productId);
        if (! $product || ! $product->raw) {
            return;
        }

        $raw = $product->raw;
        $category = $raw['rz_category'] ?? [];
        $producer = $raw['rz_producer'] ?? [];

        $product->update([
            'title' => $raw['name_ua'] ?? $raw['name'] ?? $product->title,
            'description' => $raw['description_ua'] ?? $raw['description'] ?? null,
            'description_ua' => $raw['description_ua'] ?? null,
            'price' => $raw['price'] ?? $product->price,
            'price_old' => ($raw['price_old'] ?? '0.00') !== '0.00' ? $raw['price_old'] : null,
            'quantity' => $raw['stock_quantity'] ?? 0,
            'producer_name' => $producer['title'] ?? null,
            'edited_fields' => null,
            'has_local_changes' => false,
        ]);
    }

    public function pushToRozetka(int $productId, bool $autoApprove = false): void
    {
        $product = RozetkaProduct::withoutGlobalScopes()->find($productId);
        if (! $product) {
            $this->pushMessage = 'Товар не знайдено';
            $this->pushSuccess = false;

            return;
        }

        $service = app(RozetkaProductService::class);
        $result = $service->pushToRozetka($product, $autoApprove);

        $this->pushMessage = $result['message'];
        $this->pushSuccess = $result['success'];
    }

    public function refreshAttributes(int $productId): void
    {
        $product = RozetkaProduct::withoutGlobalScopes()->find($productId);
        if (! $product || ! $product->rozetka_category_id) {
            return;
        }

        // Force re-sync attributes from API
        $attrService = app(RozetkaAttributeService::class);
        RozetkaCategoryAttribute::where('rozetka_category_id', $product->rozetka_category_id)->delete();
        $attrService->syncCategoryAttributes($product->rozetka_category_id);

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

        $savedValues = $product->attributeValues->keyBy('attribute_id');
        $this->productAttributes = [];
        foreach ($savedValues as $attrId => $val) {
            $this->productAttributes[$attrId] = [
                'value_id' => $val->value_id,
                'value_text' => $val->value_text,
            ];
        }
    }

    // ── Export tab: prepare products ──

    public function prepareForExport(int $localProductId): void
    {
        $localProduct = Product::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->find($localProductId);

        if (! $localProduct) {
            return;
        }

        // Check if already prepared
        $exists = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('local_product_id', $localProductId)
            ->exists();

        if ($exists) {
            return;
        }

        RozetkaProduct::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'article' => $localProduct->article,
            'parent_article' => $localProduct->parent_article,
            'title' => $localProduct->title,
            'price' => $localProduct->price,
            'price_old' => $localProduct->price_old,
            'in_stock' => $localProduct->in_stock,
            'quantity' => $localProduct->quantity ?? 0,
            'export_status' => 'draft',
            'local_product_id' => $localProductId,
            'photos' => $localProduct->images ?? [],
        ]);
    }

    public function markReady(int $rozetkaProductId): void
    {
        RozetkaProduct::withoutGlobalScopes()
            ->where('id', $rozetkaProductId)
            ->where('export_status', 'draft')
            ->update(['export_status' => 'ready']);
    }

    public function markDraft(int $rozetkaProductId): void
    {
        RozetkaProduct::withoutGlobalScopes()
            ->where('id', $rozetkaProductId)
            ->where('export_status', 'ready')
            ->update(['export_status' => 'draft']);
    }

    public function removeFromExport(int $rozetkaProductId): void
    {
        RozetkaProduct::withoutGlobalScopes()
            ->where('id', $rozetkaProductId)
            ->whereIn('export_status', ['draft', 'ready'])
            ->whereNull('rozetka_item_id')
            ->delete();

        if ($this->expandedProductId === $rozetkaProductId) {
            $this->expandedProductId = null;
        }
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

    public function updatedUploadStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMatchFilter(): void
    {
        $this->resetPage();
    }

    public function updatedExportStatusFilter(): void
    {
        $this->resetPage();
    }

    // ── Render ──

    public function render()
    {
        return $this->renderRozetkaTab();
    }

    protected function renderRozetkaTab()
    {
        // Show all products synced from Rozetka (excluding duplicates)
        $query = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('price_offer_id')
            ->where('is_duplicate', false)
            ->with(['localProduct', 'horoshopProduct']);

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

        if ($this->uploadStatusFilter !== '') {
            $query->where('upload_status', (int) $this->uploadStatusFilter);
        }

        if ($this->matchFilter === 'matched') {
            $query->whereNotNull('local_product_id');
        } elseif ($this->matchFilter === 'unmatched') {
            $query->whereNull('local_product_id');
        }

        $query->orderByDesc('in_stock')->orderBy('title');

        $products = $query->paginate(25);

        $base = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('price_offer_id')
            ->where('is_duplicate', false);

        $duplicateCount = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('is_duplicate', true)
            ->count();

        $totalProducts = (clone $base)->count();
        $inStockCount = (clone $base)->where('in_stock', true)->count();

        // Dynamic upload_status counts — no hidden products
        $statusCounts = (clone $base)
            ->selectRaw('upload_status, upload_status_title, count(*) as cnt')
            ->groupBy('upload_status', 'upload_status_title')
            ->orderBy('upload_status')
            ->get();

        $blockedCount = (clone $base)
            ->whereNotNull('blocked_reasons')
            ->whereRaw("blocked_reasons != '[]'")
            ->whereRaw("blocked_reasons != 'null'")
            ->whereRaw('LENGTH(blocked_reasons) > 2')
            ->count();
        $matchedCount = (clone $base)->whereNotNull('local_product_id')->count();
        $unmatchedCount = $totalProducts - $matchedCount;

        return view('livewire.contractor.rozetka-product-list', [
            'products' => $products,
            'totalProducts' => $totalProducts,
            'inStockCount' => $inStockCount,
            'statusCounts' => $statusCounts,
            'blockedCount' => $blockedCount,
            'matchedCount' => $matchedCount,
            'unmatchedCount' => $unmatchedCount,
            'localProducts' => collect(),
            'draftCount' => 0,
            'exportReadyCount' => 0,
            'notOnRozetkaCount' => $this->getNotOnRozetkaCount(),
            'duplicateCount' => $duplicateCount,
        ]);
    }

    protected function renderExportTab()
    {
        // Products in export pipeline (draft/ready in rozetka_products)
        $query = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('export_status', ['draft', 'ready'])
            ->with('localProduct');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('article', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->exportStatusFilter === 'draft') {
            $query->where('export_status', 'draft');
        } elseif ($this->exportStatusFilter === 'ready') {
            $query->where('export_status', 'ready');
        }

        $query->orderByRaw("CASE WHEN export_status = 'ready' THEN 0 ELSE 1 END")->orderBy('title');

        $products = $query->paginate(25);

        // Local products NOT on Rozetka yet (for "add" button)
        $rozetkaArticles = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->pluck('article')
            ->toArray();

        $localQuery = Product::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNotIn('article', $rozetkaArticles);

        if ($this->search) {
            $localQuery->where(function ($q) {
                $q->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('article', 'like', '%'.$this->search.'%');
            });
        }

        $localProducts = $localQuery->orderBy('title')->limit(50)->get();

        $draftCount = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->where('export_status', 'draft')->count();
        $exportReadyCount = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->where('export_status', 'ready')->count();

        return view('livewire.contractor.rozetka-product-list', [
            'products' => $products,
            'totalProducts' => $draftCount + $exportReadyCount,
            'inStockCount' => 0,
            'readyCount' => $exportReadyCount,
            'localProducts' => $localProducts,
            'draftCount' => $draftCount,
            'exportReadyCount' => $exportReadyCount,
            'notOnRozetkaCount' => $this->getNotOnRozetkaCount(),
        ]);
    }

    protected function getNotOnRozetkaCount(): int
    {
        $rozetkaArticles = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->pluck('article')
            ->toArray();

        return Product::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereNotIn('article', $rozetkaArticles)
            ->count();
    }
}
