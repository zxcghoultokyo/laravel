<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductSynonym;
use App\Models\SyncLog;
use App\Models\TenantOnboardingProgress;
use App\Services\Search\MeiliClient;
use App\Support\ProductRawExtractor;
use App\Support\ColorNormalizer;
use App\Scopes\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexProductsToMeiliJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Backward-compat: старі job-и могли серіалізувати "chunk".
     */
    public int $chunkSize = 500;

    public ?int $chunk = null;

    /**
     * Optional tenant_id filter - if set, only index products for this tenant.
     * When null, indexes ALL products across all tenants.
     */
    public ?int $tenantId = null;

    /**
     * Constructor accepts optional tenant_id as first param (for tenant-specific indexing)
     * and optional chunkSize as second param.
     * 
     * Usage:
     *   IndexProductsToMeiliJob::dispatch();           // All tenants
     *   IndexProductsToMeiliJob::dispatch(5);          // Only tenant 5
     *   IndexProductsToMeiliJob::dispatch(null, 100);  // All tenants, chunk=100
     *   IndexProductsToMeiliJob::dispatch(5, 100);     // Tenant 5, chunk=100
     */
    public function __construct(?int $tenantId = null, int $chunkSize = 500)
    {
        $this->tenantId = $tenantId;
        $this->chunkSize = max(50, (int) $chunkSize);
        // Use meili queue to avoid blocking OnboardTenantJob on default queue
        $this->onQueue('meili');
    }

    protected function effectiveChunkSize(): int
    {
        // якщо прийшла стара job з "chunk"
        $size = $this->chunk ?? $this->chunkSize;

        return max(50, (int) $size);
    }

    public function handle(MeiliClient $meili): void
    {
        $index = $meili->productsIndex();
        $chunkSize = $this->effectiveChunkSize();
        
        // Build base query - bypass tenant scope for system job
        $baseQuery = Product::withoutGlobalScope(TenantScope::class);
        
        // Filter by tenant if specified
        if ($this->tenantId !== null) {
            $baseQuery->where('tenant_id', $this->tenantId);
        }
        
        $totalCount = (clone $baseQuery)->count();
        $processedCount = 0;
        $startTime = microtime(true);
        
        // Build log message
        $logMessage = $this->tenantId 
            ? "Reindex {$totalCount} products for tenant #{$this->tenantId}"
            : "Reindex {$totalCount} products (all tenants)";
        
        // Start sync log
        $syncLog = SyncLog::start(SyncLog::TYPE_MEILISEARCH, $logMessage);

        echo "🔄 Індексація товарів у Meilisearch...\n";
        if ($this->tenantId !== null) {
            echo "🏪 Тенант: #{$this->tenantId}\n";
        }
        echo "📦 Всього товарів: {$totalCount}\n";
        echo "📊 Розмір чанку: {$chunkSize}\n\n";
        
        try {
            // 🧹 Cleanup: видаляємо з Meili товари, яких немає в БД або мають in_stock=false
            $this->cleanupStaleDocuments($index, $meili);

        // Ensure filterable attributes for AI flags exist (idempotent)
        try {
            $index->updateSettings([
                'filterableAttributes' => array_values(array_unique([
                    'tenant_id', 'has_ai_type', 'has_ai_category', 'ai_product_type', 'brand', 'color', 'color_norm', 'size', 'in_stock', 'quantity', 'display_in_showcase', 'price', 'category_path',
                ])),
                'sortableAttributes' => [
                    'popularity',
                    'orders_count',
                    'price',
                    'updated_at_ts',
                ],
                // Ranking rules: relevance first, then popularity as tiebreaker
                // IMPORTANT: popularity/orders MUST come AFTER attribute/proximity
                // Otherwise popular but irrelevant products rank higher than relevant ones
                'rankingRules' => [
                    'words',              // 1. Basic word matching (MUST be first)
                    'typo',               // 2. Typo tolerance
                    'proximity',          // 3. How close query words are in document
                    'attribute',          // 4. Which field matched (title > description)
                    'exactness',          // 5. Exact vs prefix matches
                    'orders_count:desc',  // 6. Popular products as tiebreaker
                    'popularity:desc',    // 7. Secondary popularity metric
                    'sort',               // 8. Explicit sort parameter
                ],
                'searchableAttributes' => [
                    // Primary fields (highest priority)
                    'title',
                    'ai_product_type',   // AI-detected product type (smartphone, helmet, etc.)
                    'ai_keywords',       // AI-generated keywords
                    'ai_slang',          // Slang/jargon terms
                    'ai_search_queries', // Typical user search queries
                    'ai_synonyms',       // Product name synonyms
                    // Secondary fields
                    'search_index',
                    'description',
                    'attributes_text',
                    'category_path',
                    'brand',
                    'color',
                    'size',
                    'ai_materials',      // Materials
                    'ai_standards',      // Protection standards
                ],
                // Синоніми для покращення пошуку (базові + з ProductSynonym таблиці)
                'synonyms' => $this->buildMeiliSynonyms(),
            ]);
            echo "✅ Налаштування Meili оновлено (включно з синонімами та ранкінгом)\n\n";
        } catch (\Throwable $e) {
            echo "⚠️  Налаштування Meili: {$e->getMessage()}\n\n";
        }

        // Index products (bypass tenant scope - this is a system job)
        $query = Product::withoutGlobalScope(TenantScope::class)
            ->with('aiIndex')
            ->orderBy('id');
        
        // Filter by tenant if specified
        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }
        
        $query->chunk($chunkSize, function ($products) use ($index, $meili, &$processedCount, $totalCount) {
                $docs = [];

                // Підтягнемо raw батьківських товарів для fallback
                $parentArticles = $products->pluck('parent_article')->filter()->unique()->all();
                $parentRawMap = [];
                if ($parentArticles) {
                    Product::withoutGlobalScope(TenantScope::class)
                        ->whereIn('article', $parentArticles)
                        ->get(['article', 'raw'])
                        ->each(function ($parent) use (&$parentRawMap) {
                            $parentRawMap[$parent->article] = is_array($parent->raw ?? null) ? $parent->raw : (array) ($parent->raw ?? []);
                        });
                }

                // Update onboarding progress periodically
                if ($this->tenantId !== null && $processedCount > 0) {
                    $this->updateMeiliProgress($processedCount, $totalCount);
                }

                foreach ($products as $p) {
                    $lang = 'ua';
                    $raw = is_array($p->raw ?? null) ? $p->raw : (array) ($p->raw ?? []);
                    $parentRaw = [];
                    $parentArticle = (string) ($p->parent_article ?? '');
                    if ($parentArticle !== '' && isset($parentRawMap[$parentArticle])) {
                        $parentRaw = $parentRawMap[$parentArticle];
                    }

                    $desc = ProductRawExtractor::description($raw, $lang, $parentRaw);
                    $attrsText = ProductRawExtractor::attributesText($raw, $lang, $parentRaw);
                    $attrsMap = ProductRawExtractor::attributes($raw, $lang, $parentRaw);

                    $docs[] = [
                        'id' => (int) $p->id,
                        'tenant_id' => (int) ($p->tenant_id ?? 1),

                        'article' => (string) ($p->article ?? ''),
                        'parent_article' => (string) ($p->parent_article ?? ''),

                        'title' => (string) ($p->title ?? ''),
                        'category_path' => (string) ($p->category_path ?? ''),
                        'brand' => (string) ($p->brand ?? ''),
                        'color' => (string) ($p->color ?? ''),
                        'size' => (string) ($p->size ?? ''),

                        'search_index' => (string) ($p->search_index ?? ''),

                        'description' => $desc,
                        'attributes_text' => $attrsText,
                        'attrs' => $attrsMap,

                        'in_stock' => (bool) $p->in_stock,
                        'display_in_showcase' => (bool) $p->display_in_showcase,
                        'quantity' => (int) ($p->quantity ?? 0),
                        'presence_raw' => (string) ($p->presence ?? ''),

                        // ⚠️ В products price/price_old decimal(10,2) — як int ти втрачаєш копійки.
                        // Якщо ок — залишай. Якщо ні:
                        'price' => (float) ($p->price ?? 0),
                        'price_old' => (float) ($p->price_old ?? 0),

                        'we_recommended' => (bool) $p->we_recommended,
                        'popularity' => (int) ($p->popularity ?? 0),
                        'orders_count' => (int) ($p->orders_count ?? 0),
                        'views_count' => (int) ($p->views_count ?? 0),
                        'added_to_cart_count' => (int) ($p->added_to_cart_count ?? 0),

                        // ✅ замість неіснуючого updated_at_ts
                        'updated_at_ts' => $p->updated_at ? $p->updated_at->getTimestamp() : 0,

                        'ai_product_type' => is_string($p->aiIndex->product_type ?? null) ? trim($p->aiIndex->product_type) : '',
                        'ai_category' => is_string($p->aiIndex->ai_category ?? null) ? trim($p->aiIndex->ai_category) : '',
                        'has_ai_type' => (bool) ($p->aiIndex->product_type ?? null),
                        'has_ai_category' => (bool) ($p->aiIndex->ai_category ?? null),
                        
                        // AI-generated search enhancement fields
                        'ai_keywords' => $this->flattenArrayField($p->aiIndex->keywords ?? []),
                        'ai_slang' => $this->flattenArrayField($p->aiIndex->slang ?? []),
                        'ai_synonyms' => $this->flattenArrayField($p->aiIndex->raw_ai_json['synonyms'] ?? []),
                        'ai_search_queries' => $this->flattenArrayField($p->aiIndex->raw_ai_json['search_queries'] ?? []),
                        'ai_materials' => $this->flattenArrayField($p->aiIndex->materials ?? []),
                        'ai_standards' => $this->flattenArrayField($p->aiIndex->standards ?? []),
                        
                        // Normalized fields for robust filtering (use null instead of empty string)
                        'color_norm' => ColorNormalizer::toNorm((string) ($p->color ?? '')),
                    ];
                }

                if (! empty($docs)) {
                    $result = $index->addDocuments($docs);
                    $taskId = $result['taskUid'] ?? $result['taskUid'] ?? null;
                    $processedCount += count($docs);
                    $percent = $totalCount > 0 ? round(($processedCount / $totalCount) * 100, 1) : 0;
                    echo "📤 Проіндексовано: {$processedCount}/{$totalCount} ({$percent}%)\n";

                    if ($taskId !== null) {
                        $task = $this->waitForTaskCompletion($meili, $taskId, 30);
                        $status = $task['status'] ?? 'unknown';
                        $err = $task['error'] ?? null;
                        $tookMs = isset($task['duration']) ? $task['duration'] : null;
                        echo "   • Task #{$taskId}: {$status}" . ($tookMs !== null ? " ({$tookMs})" : '') . ($err ? " — {$err}" : '') . "\n";
                    }
                }
            });

        $duration = round(microtime(true) - $startTime, 2);
        echo "\n✅ Індексація завершена!\n";
        echo "📊 Оброблено товарів: {$processedCount}\n";
        echo "⏱️  Час виконання: {$duration} сек\n";
        
        // Complete sync log
        $syncLog->complete([
            'total_processed' => $processedCount,
            'created' => $processedCount, // All indexed
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ]);

        // Mark Meili indexing as completed in onboarding progress
        if ($this->tenantId !== null) {
            $this->markMeiliCompleted($processedCount);
        }
        
        } catch (\Throwable $e) {
            $syncLog->fail($e->getMessage());
            throw $e;
        }
    }

    /**
     * Update Meili indexing progress
     */
    private function updateMeiliProgress(int $processed, int $total): void
    {
        $progress = TenantOnboardingProgress::where('tenant_id', $this->tenantId)->first();
        
        if (!$progress || $progress->status !== 'in_progress') {
            return;
        }

        $percent = $total > 0 
            ? min(95, (int) round($processed / $total * 100))
            : 0;

        $detail = "Індексація: {$processed} з {$total} товарів";
        
        $progress->updateStep('meili_indexing', 'in_progress', $percent, $detail, [
            'total' => $total,
            'processed' => $processed,
        ]);
    }

    /**
     * Mark Meili indexing as completed and finalize onboarding
     */
    private function markMeiliCompleted(int $processedCount): void
    {
        $progress = TenantOnboardingProgress::where('tenant_id', $this->tenantId)->first();
        
        if (!$progress) {
            return;
        }

        $progress->updateStep('meili_indexing', 'completed', 100, 
            "Проіндексовано {$processedCount} товарів",
            ['processed' => $processedCount]
        );

        // Check if all steps are completed and mark onboarding as done
        $this->finalizeOnboardingIfComplete($progress);
    }

    /**
     * Finalize onboarding if all steps are completed
     */
    private function finalizeOnboardingIfComplete(TenantOnboardingProgress $progress): void
    {
        $steps = $progress->steps ?? [];
        $allCompleted = true;

        foreach (['horoshop_sync', 'categories_rebuild', 'brands_sync', 'ai_enrichment', 'meili_indexing'] as $stepKey) {
            $step = $steps[$stepKey] ?? null;
            if (!$step || $step['status'] !== 'completed') {
                $allCompleted = false;
                break;
            }
        }

        if ($allCompleted && $progress->status !== 'completed') {
            $progress->complete();
            
            // Update tenant
            \App\Models\Tenant::where('id', $this->tenantId)
                ->update(['onboarding_completed_at' => now()]);

            \Illuminate\Support\Facades\Log::info('IndexProductsToMeiliJob: Onboarding finalized', [
                'tenant_id' => $this->tenantId,
            ]);
        }
    }

    /**
     * Poll Meili task status to show completion and duration.
     */
    protected function waitForTaskCompletion(MeiliClient $meili, int $taskId, int $timeoutSeconds = 30): array
    {
        $client = $meili->client();
        $start = microtime(true);
        $task = [];

        while (true) {
            $task = $client->getTask($taskId);
            $status = $task['status'] ?? '';
            if (in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
                break;
            }

            if (microtime(true) - $start > $timeoutSeconds) {
                $task['status'] = $status ?: 'timeout';
                break;
            }

            usleep(500_000); // 0.5s
        }

        return $task;
    }

    /**
     * Flatten array field to space-separated string for Meilisearch searchable text.
     */
    protected function flattenArrayField(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return implode(' ', array_filter($value, 'is_string'));
        }
        return '';
    }
    
    /**
     * 🧹 Видаляє з Meilisearch товари, яких немає в БД або мають in_stock=false.
     * Це вирішує проблему коли товари видаляються з Horoshop, але залишаються в індексі.
     * 
     * При tenant-specific індексації (tenantId != null), видаляє тільки застарілі товари цього тенанта.
     */
    protected function cleanupStaleDocuments($index, MeiliClient $meili): void
    {
        echo "🧹 Перевірка застарілих товарів у Meili...\n";
        
        try {
            // Отримуємо ID з Meilisearch (з фільтром по тенанту якщо потрібно)
            $meiliIds = [];
            $limit = 1000;
            $offset = 0;
            
            // Build Meili query with tenant filter if needed
            $meiliFilter = null;
            if ($this->tenantId !== null) {
                $meiliFilter = "tenant_id = {$this->tenantId}";
                echo "   🏪 Фільтр тенанта: #{$this->tenantId}\n";
            }
            
            do {
                if ($meiliFilter) {
                    // Use search with filter to get only tenant's documents
                    $result = $index->search('', [
                        'limit' => $limit,
                        'offset' => $offset,
                        'filter' => $meiliFilter,
                        'attributesToRetrieve' => ['id'],
                    ]);
                    $docs = $result->getHits();
                } else {
                    // Get all documents
                    $query = (new \Meilisearch\Contracts\DocumentsQuery())
                        ->setLimit($limit)
                        ->setOffset($offset)
                        ->setFields(['id']);
                    $result = $index->getDocuments($query);
                    $docs = $result->getResults();
                }
                
                if (empty($docs)) {
                    break;
                }
                
                foreach ($docs as $doc) {
                    $meiliIds[] = (int) $doc['id'];
                }
                
                $offset += $limit;
            } while (count($docs) === $limit);
            
            if (empty($meiliIds)) {
                echo "   ℹ️  Meili індекс порожній, cleanup не потрібен\n\n";
                return;
            }
            
            echo "   📋 Знайдено " . count($meiliIds) . " товарів у Meili\n";
            
            // Отримуємо валідні ID з БД (тільки in_stock=true, без tenant scope)
            $validQuery = Product::withoutGlobalScope(TenantScope::class)
                ->where('in_stock', true)
                ->whereIn('id', $meiliIds);
            
            // Apply tenant filter to DB query as well (for consistency)
            if ($this->tenantId !== null) {
                $validQuery->where('tenant_id', $this->tenantId);
            }
            
            $validIds = $validQuery
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();
            
            // Знаходимо ID для видалення
            $idsToDelete = array_diff($meiliIds, $validIds);
            
            if (empty($idsToDelete)) {
                echo "   ✅ Застарілих товарів не знайдено\n\n";
                return;
            }
            
            echo "   🗑️  Видалення " . count($idsToDelete) . " застарілих товарів...\n";
            
            // Видаляємо чанками
            foreach (array_chunk($idsToDelete, 500) as $chunk) {
                $result = $index->deleteDocuments($chunk);
                $taskId = $result['taskUid'] ?? null;
                
                if ($taskId !== null) {
                    $task = $this->waitForTaskCompletion($meili, $taskId, 30);
                    $status = $task['status'] ?? 'unknown';
                    echo "   • Delete task #{$taskId}: {$status} (" . count($chunk) . " товарів)\n";
                }
            }
            
            echo "   ✅ Cleanup завершено\n\n";
            
        } catch (\Throwable $e) {
            echo "   ⚠️  Cleanup помилка: {$e->getMessage()}\n\n";
        }
    }
    
    /**
     * Build synonyms map for Meilisearch from base synonyms + ProductSynonym table.
     * Merges static synonyms with dynamic ones from database.
     */
    protected function buildMeiliSynonyms(): array
    {
        // Base synonyms (always included)
        $baseSynonyms = [
            // Електроніка / Смартфони
            'smartphone' => ['смартфон', 'телефон', 'phone', 'mobile', 'iphone', 'мобільний'],
            'phone' => ['телефон', 'смартфон', 'smartphone', 'iphone', 'мобільний'],
            'телефон' => ['смартфон', 'smartphone', 'phone', 'iphone', 'мобільний'],
            'iphone' => ['айфон', 'apple phone', 'smartphone', 'телефон'],
            'айфон' => ['iphone', 'apple phone', 'smartphone', 'телефон'],
            // Шоломи
            'шолом' => ['каска', 'шлем', 'helmet', 'ballistic helmet', 'кевларовий шолом'],
            'каска' => ['шолом', 'helmet', 'ballistic helmet'],
            'helmet' => ['шолом', 'каска'],
            // Плитоноски
            'плитоноска' => ['plate carrier', 'плейткерієр', 'плитник', 'розвантажка', 'разгрузка'],
            'plate carrier' => ['плитоноска', 'плейткерієр'],
            // Одяг
            'сорочка' => ['shirt', 'бойова сорочка', 'combat shirt'],
            'штани' => ['брюки', 'pants', 'бойові штани'],
            // Взуття
            'берці' => ['черевики', 'boots', 'ботинки', 'берцы'],
            'кросівки' => ['кроси', 'sneakers', 'тактичні кросівки'],
            // Підсумки
            'підсумок' => ['подсумок', 'pouch', 'сумка', 'mag pouch'],
            'pouch' => ['підсумок', 'подсумок'],
            // Рюкзаки
            'рюкзак' => ['backpack', 'ранець', 'наплічник'],
            'backpack' => ['рюкзак', 'ранець'],
            // Термобілизна
            'термобілизна' => ['термуха', 'thermal underwear', 'base layer', 'кальсони', 'подштанники', 'термобілізна', 'level 1', 'level 2'],
            'термуха' => ['термобілизна', 'thermal underwear', 'base layer', 'термобілізна'],
            'thermal' => ['термо', 'термобілизна', 'термуха'],
            // Бренди з варіантами написання
            'ops-core' => ['ops core', 'opscore', 'опс-кор', 'опскор'],
            'crye precision' => ['crye', 'край', 'край прецішн'],
            // Загальні рівні/левели (універсально)
            'level' => ['левел', 'лвл', 'рівень', 'lvl'],
            'левел' => ['level', 'лвл', 'рівень', 'lvl'],
            'лвл' => ['level', 'левел', 'рівень', 'lvl'],
            'рівень' => ['level', 'левел', 'лвл', 'lvl'],
        ];
        
        // Load dynamic synonyms from ProductSynonym table (relational: product_type + synonym)
        try {
            $dbSynonyms = ProductSynonym::query()
                ->where('is_active', true)
                ->select('product_type', 'synonym')
                ->get();
            
            foreach ($dbSynonyms as $row) {
                $type = mb_strtolower(trim($row->product_type ?? ''));
                $synonym = mb_strtolower(trim($row->synonym ?? ''));
                
                if (empty($type) || empty($synonym)) {
                    continue;
                }
                
                // Add synonym to product_type group
                if (!isset($baseSynonyms[$type])) {
                    $baseSynonyms[$type] = [];
                }
                if (!in_array($synonym, $baseSynonyms[$type])) {
                    $baseSynonyms[$type][] = $synonym;
                }
                
                // Also add reverse mapping: synonym -> product_type
                if (!isset($baseSynonyms[$synonym])) {
                    $baseSynonyms[$synonym] = [];
                }
                if (!in_array($type, $baseSynonyms[$synonym])) {
                    $baseSynonyms[$synonym][] = $type;
                }
            }
            
            echo "📚 Loaded " . count($dbSynonyms) . " synonyms from database\n";
            
        } catch (\Throwable $e) {
            echo "⚠️  Could not load ProductSynonym: {$e->getMessage()}\n";
        }
        
        return $baseSynonyms;
    }
}
