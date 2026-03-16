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
 * 5. Generate product synonyms for search
 * 6. Reindex in Meilisearch
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

        if (! $tenant) {
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

            // 5. Generate product synonyms for search
            $this->generateSynonyms();

            // 6. Reindex in Meilisearch
            $this->reindexMeili();

            // 7. Generate default prompt for this tenant
            $this->generatePrompt();

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

        // For large catalogs, use async processing with progress polling
        // For small catalogs (< 200), use sync processing for faster completion
        if ($productsWithoutAi > 200) {
            $this->runAiEnrichmentAsync($productsWithoutAi);
        } else {
            $this->runAiEnrichmentSync($productsWithoutAi);
        }
    }

    /**
     * Run AI enrichment synchronously (for small catalogs < 200 products)
     */
    protected function runAiEnrichmentSync(int $productsWithoutAi): void
    {
        $batchSize = 50;
        $processed = 0;

        while (true) {
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

            // Run enrichment synchronously (singleBatchOnly=true)
            try {
                AnalyzeProductsWithAiJob::dispatchSync(
                    batchSize: $products->count(),
                    offset: 0,
                    forceReanalyze: false,
                    tenantId: $this->tenantId,
                    singleBatchOnly: true
                );
            } catch (\Throwable $e) {
                Log::warning('OnboardTenantJob: AI batch failed, continuing', [
                    'error' => $e->getMessage(),
                ]);
            }

            $processed += $products->count();
            $percent = min(95, (int) round($processed / $productsWithoutAi * 100));

            $detail = $this->getAiEnrichmentDetail($processed, $productsWithoutAi);
            $this->progress->updateStep('ai_enrichment', 'in_progress', $percent, $detail, [
                'total' => $productsWithoutAi,
                'processed' => $processed,
            ]);

            usleep(500000); // 0.5 second delay
        }

        $this->finalizeAiEnrichment($productsWithoutAi);
    }

    /**
     * Run AI enrichment asynchronously (for large catalogs 200+ products)
     * Dispatches batches to queue and polls for completion (with reasonable timeout)
     */
    protected function runAiEnrichmentAsync(int $productsWithoutAi): void
    {
        Log::info('OnboardTenantJob: Using async AI enrichment for large catalog', [
            'tenant_id' => $this->tenantId,
            'products_count' => $productsWithoutAi,
        ]);

        // Dispatch first batch to DIFFERENT queue (meili) so it runs in parallel
        // OnboardTenantJob runs on 'default', AnalyzeProductsWithAiJob on 'meili'
        // Worker processes both: --queue=default,meili
        AnalyzeProductsWithAiJob::dispatch(
            batchSize: 50,
            offset: 0,
            forceReanalyze: false,
            tenantId: $this->tenantId,
            singleBatchOnly: false  // Allow auto-dispatch of next batches
        )->onQueue('meili');  // Use different queue to avoid blocking!

        // Poll for completion with progress updates
        // Calculate reasonable timeout: ~6 seconds per product (with rate limiting)
        // 400 products = ~40 minutes, add buffer = 60 minutes max
        $estimatedMinutes = max(30, (int) ceil($productsWithoutAi * 6 / 60));
        $maxWaitSeconds = min(3600, $estimatedMinutes * 60); // Max 60 min

        Log::info('OnboardTenantJob: Waiting for AI enrichment', [
            'tenant_id' => $this->tenantId,
            'products' => $productsWithoutAi,
            'max_wait_minutes' => $maxWaitSeconds / 60,
        ]);

        $startTime = time();
        $lastProcessed = 0;
        $stuckCounter = 0;

        while ((time() - $startTime) < $maxWaitSeconds) {
            sleep(10); // Check every 10 seconds (AI batches take ~15-20s)

            // Count current progress
            $enrichedCount = \App\Models\ProductAiIndex::whereHas('product', function ($q) {
                $q->withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $this->tenantId);
            })->count();

            $percent = min(95, (int) round($enrichedCount / $productsWithoutAi * 100));
            $detail = $this->getAiEnrichmentDetail($enrichedCount, $productsWithoutAi);

            $this->progress->updateStep('ai_enrichment', 'in_progress', $percent, $detail, [
                'total' => $productsWithoutAi,
                'processed' => $enrichedCount,
            ]);

            // Check if done (95%+ coverage is good enough)
            $remaining = $productsWithoutAi - $enrichedCount;
            if ($remaining <= 0 || $percent >= 95) {
                Log::info('OnboardTenantJob: AI enrichment completed', [
                    'tenant_id' => $this->tenantId,
                    'enriched' => $enrichedCount,
                    'total' => $productsWithoutAi,
                ]);
                break;
            }

            // Check if stuck (no progress for 3 minutes - AI batches can have delays)
            if ($enrichedCount === $lastProcessed) {
                $stuckCounter++;
                if ($stuckCounter >= 18) { // 18 * 10 sec = 180 seconds (3 minutes)
                    Log::warning('OnboardTenantJob: AI enrichment appears stuck, continuing with other steps', [
                        'tenant_id' => $this->tenantId,
                        'enriched' => $enrichedCount,
                        'total' => $productsWithoutAi,
                    ]);
                    break;
                }
            } else {
                $stuckCounter = 0;
                $lastProcessed = $enrichedCount;
            }
        }

        // Log if we timed out but AI is still running
        $finalEnrichedCount = \App\Models\ProductAiIndex::whereHas('product', function ($q) {
            $q->withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('tenant_id', $this->tenantId);
        })->count();

        if ($finalEnrichedCount < $productsWithoutAi) {
            Log::info('OnboardTenantJob: AI enrichment still in progress, continuing with other steps', [
                'tenant_id' => $this->tenantId,
                'enriched' => $finalEnrichedCount,
                'total' => $productsWithoutAi,
                'percent' => round($finalEnrichedCount / $productsWithoutAi * 100),
            ]);
        }

        $this->finalizeAiEnrichment($productsWithoutAi);
    }

    /**
     * Finalize AI enrichment step
     * Note: If not all products enriched, keeps status as 'in_progress' - AI job will continue in background
     */
    protected function finalizeAiEnrichment(int $originalCount): void
    {
        $enrichedCount = \App\Models\ProductAiIndex::whereHas('product', function ($q) {
            $q->withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('tenant_id', $this->tenantId);
        })->count();

        $percent = $originalCount > 0
            ? (int) round($enrichedCount / $originalCount * 100)
            : 100;

        // If less than 95% enriched, keep as in_progress - AI job continues in background
        if ($percent < 95) {
            $this->progress->updateStep('ai_enrichment', 'in_progress', $percent,
                "AI аналіз: {$enrichedCount} з {$originalCount} товарів (продовжується у фоні)",
                ['total' => $originalCount, 'processed' => $enrichedCount, 'enriched' => $enrichedCount]
            );

            Log::info('OnboardTenantJob: AI enrichment continuing in background', [
                'tenant_id' => $this->tenantId,
                'enriched' => $enrichedCount,
                'total' => $originalCount,
                'percent' => $percent,
            ]);
        } else {
            $this->progress->updateStep('ai_enrichment', 'completed', 100,
                "AI аналіз завершено: {$enrichedCount} товарів оброблено",
                ['total' => $originalCount, 'processed' => $enrichedCount, 'enriched' => $enrichedCount]
            );
        }
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

    /**
     * Generate product synonyms for improved search
     * Uses AI to create synonyms from category paths
     */
    protected function generateSynonyms(): void
    {
        $this->progress->updateStep('synonyms_generation', 'in_progress', 0, 'Витягування типів товарів...');

        // Get unique category paths count for this tenant
        $categoryCount = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('category_path')
            ->where('category_path', '!=', '')
            ->distinct('category_path')
            ->count('category_path');

        if ($categoryCount === 0) {
            $this->progress->updateStep('synonyms_generation', 'completed', 100,
                'Категорії не знайдені, пропускаємо генерацію синонімів',
                ['categories_count' => 0]
            );

            return;
        }

        Log::info('OnboardTenantJob: Generating synonyms', [
            'tenant_id' => $this->tenantId,
            'category_count' => $categoryCount,
        ]);

        $this->progress->updateStep('synonyms_generation', 'in_progress', 30,
            "Генерація синонімів для {$categoryCount} категорій через AI...");

        try {
            // Run synonyms generation via artisan command
            \Illuminate\Support\Facades\Artisan::call('synonyms:products', [
                '--tenant' => $this->tenantId,
                '--force' => false,
            ]);

            $this->progress->updateStep('synonyms_generation', 'in_progress', 80, 'Збереження синонімів...');

            // Count generated synonyms
            $synonymsCount = \App\Models\ProductSynonym::where('tenant_id', $this->tenantId)->count();

            $this->progress->updateStep('synonyms_generation', 'completed', 100,
                "Згенеровано {$synonymsCount} синонімів для {$categoryCount} категорій",
                ['synonyms_count' => $synonymsCount, 'categories_count' => $categoryCount]
            );

            Log::info('OnboardTenantJob: Synonyms generated', [
                'tenant_id' => $this->tenantId,
                'synonyms_count' => $synonymsCount,
            ]);

        } catch (\Throwable $e) {
            Log::warning('OnboardTenantJob: Synonyms generation failed, continuing', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            // Don't fail the whole onboarding, just mark as completed with warning
            $this->progress->updateStep('synonyms_generation', 'completed', 100,
                'Генерація синонімів пропущена (помилка AI)',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Generate default system prompt for this tenant based on catalog analysis.
     */
    protected function generatePrompt(): void
    {
        $this->progress->updateStep('prompt_generation', 'in_progress', 0, 'Аналіз каталогу...');

        try {
            $generator = app(\App\Services\Ai\TenantPromptGenerator::class);

            $this->progress->updateStep('prompt_generation', 'in_progress', 30, 'Генерація промпту...');

            $result = $generator->generate($this->tenantId);

            $this->progress->updateStep('prompt_generation', 'completed', 100,
                "Промпт згенеровано ({$result['prompt_length']} символів)",
                ['preset_id' => $result['preset_id'], 'prompt_length' => $result['prompt_length']]
            );

            Log::info('OnboardTenantJob: Prompt generated', [
                'tenant_id' => $this->tenantId,
                'preset_id' => $result['preset_id'],
                'prompt_length' => $result['prompt_length'],
            ]);

        } catch (\Throwable $e) {
            Log::warning('OnboardTenantJob: Prompt generation failed, continuing', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            $this->progress->updateStep('prompt_generation', 'completed', 100,
                'Генерація промпту пропущена (помилка)',
                ['error' => $e->getMessage()]
            );
        }
    }
}
