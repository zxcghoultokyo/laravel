<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\TenantOnboardingProgress;
use App\Services\Catalog\CategoryIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Automatic tenant onboarding job with progress tracking
 * 
 * Runs all necessary setup tasks when a new tenant is created:
 * 1. Sync products from Horoshop
 * 2. Rebuild categories
 * 3. Sync brands
 * 4. Start AI enrichment (ALL products)
 * 5. Reindex in Meilisearch
 * 
 * @see docs/TENANT_ONBOARDING.md
 */
class OnboardTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200; // 2 hours max for large catalogs

    protected ?TenantOnboardingProgress $progress = null;

    public function __construct(
        public int $tenantId
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        
        if (!$tenant) {
            Log::warning('OnboardTenantJob: Tenant not found', ['tenant_id' => $this->tenantId]);
            return;
        }

        // Initialize progress tracking
        $this->progress = TenantOnboardingProgress::forTenant($this->tenantId);
        $this->progress->start();

        Log::info('OnboardTenantJob: Starting onboarding', [
            'tenant_id' => $this->tenantId,
            'tenant_name' => $tenant->name,
        ]);

        try {
            // 1. Sync products from Horoshop (if configured)
            $this->syncProducts($tenant);
            
            // 2. Rebuild categories for this tenant
            $this->rebuildCategories();
            
            // 3. Sync brands
            $this->syncBrands();
            
            // 4. Start AI enrichment for ALL products
            $this->runAiEnrichment();
            
            // 5. Reindex in Meilisearch
            $this->reindexMeili();
            
            // Mark onboarding as completed
            $this->progress->complete();
            $tenant->update(['onboarding_completed_at' => now()]);
            
            Log::info('OnboardTenantJob: Onboarding completed', [
                'tenant_id' => $this->tenantId,
            ]);
            
        } catch (\Throwable $e) {
            Log::error('OnboardTenantJob: Failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->progress->fail($e->getMessage());
            throw $e;
        }
    }

    /**
     * Sync products from Horoshop
     */
    protected function syncProducts(Tenant $tenant): void
    {
        // Check if Horoshop is configured (credentials are stored on Tenant, not WidgetSettings)
        if ($tenant->platform !== 'horoshop' || empty($tenant->platform_credentials)) {
            $this->progress->updateStep('horoshop_sync', 'completed', 100, 'Horoshop не налаштований, пропускаємо');
            Log::info('OnboardTenantJob: Horoshop not configured, skipping sync', [
                'tenant_id' => $this->tenantId,
                'platform' => $tenant->platform,
            ]);
            return;
        }

        $this->progress->updateStep('horoshop_sync', 'in_progress', 0, 'Підключення до Horoshop API...');

        Log::info('OnboardTenantJob: Syncing products from Horoshop', [
            'tenant_id' => $this->tenantId,
        ]);

        $this->progress->updateStep('horoshop_sync', 'in_progress', 10, 'Завантаження товарів з Horoshop...');

        // Dispatch sync job synchronously to ensure we have products before continuing
        SyncHoroshopProductsJob::dispatchSync($this->tenantId);
        
        // Get product count after sync
        $productCount = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->progress->updateStep('horoshop_sync', 'completed', 100, 
            "Синхронізовано {$productCount} товарів",
            ['products_count' => $productCount]
        );
    }

    /**
     * Rebuild categories for this tenant
     */
    protected function rebuildCategories(): void
    {
        $this->progress->updateStep('categories_rebuild', 'in_progress', 0, 'Витягування категорій з товарів...');

        Log::info('OnboardTenantJob: Rebuilding categories', [
            'tenant_id' => $this->tenantId,
        ]);

        $this->progress->updateStep('categories_rebuild', 'in_progress', 50, 'Побудова дерева категорій...');

        $service = app(CategoryIndexService::class);
        $service->rebuildForTenant($this->tenantId);
        
        // Get category count
        $categoryCount = \App\Models\Category::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->progress->updateStep('categories_rebuild', 'completed', 100,
            "Створено {$categoryCount} категорій",
            ['categories_count' => $categoryCount]
        );
    }

    /**
     * Sync brands for this tenant
     */
    protected function syncBrands(): void
    {
        $this->progress->updateStep('brands_sync', 'in_progress', 0, 'Витягування брендів з товарів...');

        Log::info('OnboardTenantJob: Syncing brands', [
            'tenant_id' => $this->tenantId,
        ]);

        // Extract unique brands from products
        $brands = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->pluck('brand');

        $this->progress->updateStep('brands_sync', 'in_progress', 50, "Знайдено {$brands->count()} брендів...");

        $created = 0;
        foreach ($brands as $brandName) {
            \App\Models\Brand::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->firstOrCreate([
                    'tenant_id' => $this->tenantId,
                    'name' => $brandName,
                ], [
                    'is_active' => true,
                    'product_count' => \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                        ->where('tenant_id', $this->tenantId)
                        ->where('brand', $brandName)
                        ->count(),
                ]);
            $created++;
        }

        $this->progress->updateStep('brands_sync', 'completed', 100,
            "Синхронізовано {$created} брендів",
            ['brands_count' => $created]
        );
    }

    /**
     * Run AI enrichment for ALL products
     */
    protected function runAiEnrichment(): void
    {
        $this->progress->updateStep('ai_enrichment', 'in_progress', 0, 'Підготовка до AI аналізу...');

        // Count products that need enrichment
        $productsCount = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->where('in_stock', true)
            ->count();

        $productsWithoutAi = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->where('in_stock', true)
            ->whereNotIn('id', function ($q) {
                $q->select('product_id')->from('product_ai_index')->whereNotNull('keywords');
            })
            ->count();

        if ($productsWithoutAi === 0) {
            $this->progress->updateStep('ai_enrichment', 'completed', 100, 
                "Всі {$productsCount} товарів вже мають AI індекс",
                ['products_count' => $productsCount, 'enriched' => $productsCount]
            );
            return;
        }

        Log::info('OnboardTenantJob: Starting AI enrichment', [
            'tenant_id' => $this->tenantId,
            'products_without_ai' => $productsWithoutAi,
            'total_products' => $productsCount,
        ]);

        $this->progress->updateStep('ai_enrichment', 'in_progress', 5, 
            "Запуск AI аналізу для {$productsWithoutAi} товарів...",
            ['total' => $productsWithoutAi, 'processed' => 0]
        );

        // Process ALL products in batches of 50
        $batchSize = 50;
        $processed = 0;
        $offset = 0;

        while ($processed < $productsWithoutAi) {
            // Get batch of products without AI index
            $products = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('tenant_id', $this->tenantId)
                ->where('in_stock', true)
                ->whereNotIn('id', function ($q) {
                    $q->select('product_id')->from('product_ai_index')->whereNotNull('keywords');
                })
                ->limit($batchSize)
                ->get();

            if ($products->isEmpty()) {
                break;
            }

            // Run enrichment synchronously for each batch
            try {
                AnalyzeProductsWithAiJob::dispatchSync(
                    batchSize: $products->count(),
                    offset: 0,
                    forceReanalyze: false,
                    tenantId: $this->tenantId
                );
            } catch (\Throwable $e) {
                Log::warning('OnboardTenantJob: AI batch failed, continuing', [
                    'error' => $e->getMessage(),
                    'batch' => $offset,
                ]);
            }

            $processed += $products->count();
            $percent = min(95, (int) round($processed / $productsWithoutAi * 100));
            
            // Update progress with details
            $detail = $this->getAiEnrichmentDetail($processed, $productsWithoutAi);
            $this->progress->updateStep('ai_enrichment', 'in_progress', $percent, $detail, [
                'total' => $productsWithoutAi,
                'processed' => $processed,
            ]);

            $offset += $batchSize;
            
            // Small delay between batches to avoid rate limits
            usleep(500000); // 0.5 second
        }

        // Final count
        $enrichedCount = \App\Models\ProductAiIndex::whereHas('product', function ($q) {
            $q->withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('tenant_id', $this->tenantId);
        })->count();

        $this->progress->updateStep('ai_enrichment', 'completed', 100,
            "AI аналіз завершено: {$enrichedCount} товарів оброблено",
            ['total' => $productsWithoutAi, 'processed' => $processed, 'enriched' => $enrichedCount]
        );
    }

    /**
     * Get detailed AI enrichment progress message
     */
    protected function getAiEnrichmentDetail(int $processed, int $total): string
    {
        $percent = $total > 0 ? round($processed / $total * 100) : 0;
        
        // Vary the message based on progress
        if ($percent < 25) {
            return "Аналіз товарів: {$processed}/{$total} — генерація ключових слів...";
        } elseif ($percent < 50) {
            return "Аналіз товарів: {$processed}/{$total} — створення сленгових синонімів...";
        } elseif ($percent < 75) {
            return "Аналіз товарів: {$processed}/{$total} — AI категоризація...";
        } else {
            return "Фінальна обробка: {$processed}/{$total} товарів...";
        }
    }

    /**
     * Reindex products in Meilisearch
     */
    protected function reindexMeili(): void
    {
        $this->progress->updateStep('meili_indexing', 'in_progress', 0, 'Підготовка документів для індексації...');

        $productCount = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->where('in_stock', true)
            ->count();

        Log::info('OnboardTenantJob: Reindexing in Meilisearch', [
            'tenant_id' => $this->tenantId,
            'products_count' => $productCount,
        ]);

        $this->progress->updateStep('meili_indexing', 'in_progress', 20, "Індексація {$productCount} товарів...");

        // Run Meili indexing synchronously
        try {
            IndexProductsToMeiliJob::dispatchSync($this->tenantId);
            
            $this->progress->updateStep('meili_indexing', 'in_progress', 80, 'Налаштування пошукових фільтрів...');
            
            $this->progress->updateStep('meili_indexing', 'completed', 100,
                "Проіндексовано {$productCount} товарів",
                ['indexed_count' => $productCount]
            );
        } catch (\Throwable $e) {
            Log::warning('OnboardTenantJob: Meili indexing failed', [
                'error' => $e->getMessage(),
            ]);
            
            $this->progress->updateStep('meili_indexing', 'completed', 100,
                'Індексація пропущена (Meilisearch недоступний)',
                ['error' => $e->getMessage()]
            );
        }
    }
}
