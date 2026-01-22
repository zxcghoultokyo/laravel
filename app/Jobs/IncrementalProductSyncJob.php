<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Models\SyncLog;
use App\Services\Horoshop\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Інкрементальна синхронізація товарів.
 * 
 * Замість повної синхронізації всіх товарів (~2300+), перевіряємо тільки:
 * 1. Нові товари (яких немає в БД)
 * 2. Змінені товари (price, quantity, in_stock)
 * 3. Видалені товари (є в БД, але немає в Horoshop)
 * 
 * Використовує хеш для швидкого порівняння.
 */
class IncrementalProductSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 хвилин
    public int $tries = 2;

    protected bool $triggerEnrichment;
    protected bool $triggerMeiliReindex;
    protected ?int $tenantId;

    public function __construct(
        bool $triggerEnrichment = true,
        bool $triggerMeiliReindex = true,
        ?int $tenantId = null
    ) {
        $this->triggerEnrichment = $triggerEnrichment;
        $this->triggerMeiliReindex = $triggerMeiliReindex;
        $this->tenantId = $tenantId;
    }

    public function handle(ProductService $productService): void
    {
        // If tenant specified, use tenant-specific sync
        if ($this->tenantId) {
            $this->handleTenantSync($productService);
            return;
        }
        
        $startTime = microtime(true);
        Log::info('[IncrementalSync] Starting incremental product sync (legacy global)');
        
        // Start sync log
        $syncLog = SyncLog::start(SyncLog::TYPE_HOROSHOP_PRODUCTS, 'Incremental sync (legacy)');

        try {
            // Отримуємо всі товари з Horoshop
            $horoshopProducts = $this->fetchAllHoroshopProducts($productService);
            
            if (empty($horoshopProducts)) {
                Log::warning('[IncrementalSync] No products from Horoshop, aborting');
                $syncLog->fail('No products received from Horoshop API');
                return;
            }

            Log::info('[IncrementalSync] Fetched products from Horoshop', [
                'count' => count($horoshopProducts),
            ]);

            // Отримуємо існуючі товари з БД
            $existingProducts = Product::pluck('id', 'article')->toArray();
            $existingHashes = $this->getExistingHashes();

            $stats = [
                'new' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'deleted' => 0,
                'errors' => 0,
            ];

            $horoshopArticles = [];
            $changedProductIds = [];

            foreach ($horoshopProducts as $item) {
                $article = $item['article'] ?? null;
                if (!$article) continue;

                $horoshopArticles[] = $article;
                $newHash = $this->computeProductHash($item);

                try {
                    if (!isset($existingProducts[$article])) {
                        // Новий товар
                        $product = $productService->upsertProductFromHoroshopPublic($item);
                        $changedProductIds[] = $product->id;
                        $this->saveProductHash($article, $newHash);
                        $stats['new']++;
                    } elseif (!isset($existingHashes[$article]) || $existingHashes[$article] !== $newHash) {
                        // Змінений товар
                        $product = $productService->upsertProductFromHoroshopPublic($item);
                        $changedProductIds[] = $product->id;
                        $this->saveProductHash($article, $newHash);
                        $stats['updated']++;
                    } else {
                        // Без змін
                        $stats['unchanged']++;
                    }
                } catch (\Throwable $e) {
                    Log::error('[IncrementalSync] Error processing product', [
                        'article' => $article,
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;
                }
            }

            // Позначаємо видалені товари
            $deletedArticles = array_diff(array_keys($existingProducts), $horoshopArticles);
            if (!empty($deletedArticles)) {
                // Ensure all articles are strings to avoid SQL type confusion
                $deletedArticles = array_map('strval', array_values($deletedArticles));
                
                // Process in chunks to avoid huge SQL queries
                $deletedCount = 0;
                foreach (array_chunk($deletedArticles, 100) as $chunk) {
                    $deletedCount += Product::whereIn('article', $chunk)
                        ->update(['in_stock' => false, 'quantity' => 0]);
                }
                $stats['deleted'] = $deletedCount;
                
                // Видаляємо хеші для видалених товарів
                foreach ($deletedArticles as $article) {
                    Cache::forget("product_hash:{$article}");
                }
                
                Log::info('[IncrementalSync] Marked products as deleted', [
                    'count' => $deletedCount,
                    'sample_articles' => array_slice($deletedArticles, 0, 10),
                ]);
            }

            $elapsed = round(microtime(true) - $startTime, 2);

            Log::info('[IncrementalSync] Completed', [
                'stats' => $stats,
                'changed_count' => count($changedProductIds),
                'elapsed_seconds' => $elapsed,
            ]);

            // Complete sync log
            $syncLog->complete([
                'total_processed' => count($horoshopProducts),
                'created' => $stats['new'],
                'updated' => $stats['updated'],
                'skipped' => $stats['unchanged'],
                'failed' => $stats['errors'],
            ], [
                'deleted' => $stats['deleted'],
                'changed_ids_count' => count($changedProductIds),
            ]);

            // Тригеримо AI enrichment тільки для нових/змінених
            if ($this->triggerEnrichment && !empty($changedProductIds)) {
                $this->triggerEnrichmentForChanged($changedProductIds);
            }

            // Тригеримо Meili реіндексацію тільки якщо були зміни
            if ($this->triggerMeiliReindex && ($stats['new'] + $stats['updated'] + $stats['deleted']) > 0) {
                $this->triggerMeiliReindex();
            }

            // Зберігаємо статистику останнього sync
            Cache::put('incremental_sync_stats', [
                'stats' => $stats,
                'elapsed_seconds' => $elapsed,
                'completed_at' => now()->toIso8601String(),
            ], now()->addDays(7));
            
        } catch (\Throwable $e) {
            Log::error('[IncrementalSync] Fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $syncLog->fail($e->getMessage());
            throw $e;
        }
    }

    /**
     * Tenant-specific sync using SyncHoroshopProductsJob logic.
     */
    protected function handleTenantSync(ProductService $productService): void
    {
        $tenant = \App\Models\Tenant::find($this->tenantId);
        
        if (!$tenant) {
            Log::error('[IncrementalSync] Tenant not found', ['tenant_id' => $this->tenantId]);
            return;
        }
        
        $startTime = microtime(true);
        Log::info('[IncrementalSync] Starting tenant sync', ['tenant_id' => $this->tenantId]);
        
        $syncLog = SyncLog::start(SyncLog::TYPE_HOROSHOP_PRODUCTS, "Tenant sync: {$tenant->name}");
        
        try {
            $result = $productService->syncFromHoroshopForTenant($tenant, 200);
            $tenant->update(['last_sync_at' => now()]);
            
            $elapsed = round(microtime(true) - $startTime, 2);
            
            $syncLog->complete([
                'tenant_id' => $this->tenantId,
                'tenant_name' => $tenant->name,
                'result' => $result,
            ], [
                'elapsed_seconds' => $elapsed,
            ]);
            
            Log::info('[IncrementalSync] Tenant sync completed', [
                'tenant_id' => $this->tenantId,
                'result' => $result,
                'elapsed_seconds' => $elapsed,
            ]);
            
            // Trigger Meili reindex for tenant if products changed
            if ($this->triggerMeiliReindex && ($result['created'] ?? 0) + ($result['updated'] ?? 0) > 0) {
                IndexProductsToMeiliJob::dispatch($this->tenantId)
                    ->delay(now()->addMinutes(1));
            }
            
        } catch (\Throwable $e) {
            Log::error('[IncrementalSync] Tenant sync failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
            $syncLog->fail($e->getMessage());
            throw $e;
        }
    }

    /**
     * Отримує всі товари з Horoshop (пагіновано).
     */
    protected function fetchAllHoroshopProducts(ProductService $productService): array
    {
        // Використовуємо reflection для доступу до protected client
        $reflection = new \ReflectionClass($productService);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $client = $clientProperty->getValue($productService);

        $allProducts = [];
        $offset = 0;
        $limit = 200;

        do {
            $payload = [
                'expr' => ['display_in_showcase' => 1],
                'limit' => $limit,
                'offset' => $offset,
                'includedParams' => [
                    'title', 'article', 'parent_article', 'price', 'price_old',
                    'parent', 'images', 'slug', 'link', 'presence', 'quantity',
                    'display_in_showcase', 'popularity', 'color', 'brand',
                    'description', 'characteristics', 'short_description',
                    'select', 'params', 'mod_title', 'Rozmir', 'Kolir', 'Dovzhina',
                    'seo_title', 'seo_keywords', 'seo_description',
                    'we_recommended', 'icons',
                ],
            ];

            $response = $client->request('catalog/export', $payload);
            $products = $response['products'] ?? [];

            if (empty($products)) {
                break;
            }

            $allProducts = array_merge($allProducts, $products);
            $offset += $limit;

            // Safety limit
            if ($offset > 10000) {
                Log::warning('[IncrementalSync] Safety limit reached at offset 10000');
                break;
            }
        } while (true);

        return $allProducts;
    }

    /**
     * Обчислює хеш товару для порівняння змін.
     * Включає тільки поля які впливають на пошук/відображення.
     */
    protected function computeProductHash(array $item): string
    {
        $hashData = [
            'title' => $item['title']['ua'] ?? $item['title']['ru'] ?? '',
            'price' => $item['price'] ?? 0,
            'price_old' => $item['price_old'] ?? 0,
            'quantity' => $item['quantity'] ?? 0,
            'presence' => $item['presence']['value']['ua'] ?? $item['presence']['value']['ru'] ?? '',
            'display_in_showcase' => $item['display_in_showcase'] ?? 0,
            'we_recommended' => $item['we_recommended'] ?? 0,
            'parent' => $item['parent']['value'] ?? '',
            'images_count' => count($item['images'] ?? []),
        ];

        return md5(json_encode($hashData));
    }

    /**
     * Отримує збережені хеші з кешу.
     */
    protected function getExistingHashes(): array
    {
        return Cache::get('product_hashes', []);
    }

    /**
     * Зберігає хеш товару.
     */
    protected function saveProductHash(string $article, string $hash): void
    {
        $hashes = Cache::get('product_hashes', []);
        $hashes[$article] = $hash;
        Cache::put('product_hashes', $hashes, now()->addDays(30));
    }

    /**
     * Запускає AI enrichment для змінених товарів.
     */
    protected function triggerEnrichmentForChanged(array $productIds): void
    {
        // Фільтруємо тільки ті що не мають AI індексу
        $needsEnrichment = Product::whereIn('id', $productIds)
            ->whereDoesntHave('aiIndex')
            ->pluck('id')
            ->toArray();

        if (empty($needsEnrichment)) {
            Log::info('[IncrementalSync] All changed products already have AI index');
            return;
        }

        Log::info('[IncrementalSync] Dispatching AI enrichment for new products', [
            'count' => count($needsEnrichment),
        ]);

        // Запускаємо ParentBasedEnrichmentJob для нових товарів
        ParentBasedEnrichmentJob::dispatch(20, 0, true)
            ->delay(now()->addMinutes(2));
    }

    /**
     * Запускає Meili реіндексацію.
     */
    protected function triggerMeiliReindex(): void
    {
        Log::info('[IncrementalSync] Dispatching Meili reindex');
        
        IndexProductsToMeiliJob::dispatch()
            ->delay(now()->addMinutes(5));
    }
}
