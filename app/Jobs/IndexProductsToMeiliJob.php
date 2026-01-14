<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use App\Support\ProductRawExtractor;
use App\Support\ColorNormalizer;
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

    public function __construct(int $chunkSize = 500)
    {
        $this->chunkSize = max(50, (int) $chunkSize);
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
        
        $totalCount = Product::count();
        $processedCount = 0;
        $startTime = microtime(true);

        echo "🔄 Індексація товарів у Meilisearch...\n";
        echo "📦 Всього товарів: {$totalCount}\n";
        echo "📊 Розмір чанку: {$chunkSize}\n\n";

        // Ensure filterable attributes for AI flags exist (idempotent)
        try {
            $index->updateSettings([
                'filterableAttributes' => array_values(array_unique([
                    'has_ai_type', 'has_ai_category', 'brand', 'color', 'color_norm', 'size', 'in_stock', 'quantity', 'display_in_showcase', 'price',
                ])),
                'searchableAttributes' => [
                    // Primary fields (highest priority)
                    'title',
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
                // Синоніми для покращення пошуку (одне слово знаходить всі варіанти)
                'synonyms' => [
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
                    // Бренди з варіантами написання
                    'ops-core' => ['ops core', 'opscore', 'опс-кор', 'опскор'],
                    'crye precision' => ['crye', 'край', 'край прецішн'],
                ],
            ]);
            echo "✅ Налаштування Meili оновлено (включно з синонімами)\n\n";
        } catch (\Throwable $e) {
            echo "⚠️  Налаштування Meili: {$e->getMessage()}\n\n";
        }

        Product::query()
            ->with('aiIndex')
            ->orderBy('id')
            ->chunk($chunkSize, function ($products) use ($index, $meili, &$processedCount, $totalCount) {
                $docs = [];

                // Підтягнемо raw батьківських товарів для fallback
                $parentArticles = $products->pluck('parent_article')->filter()->unique()->all();
                $parentRawMap = [];
                if ($parentArticles) {
                    Product::query()
                        ->whereIn('article', $parentArticles)
                        ->get(['article', 'raw'])
                        ->each(function ($parent) use (&$parentRawMap) {
                            $parentRawMap[$parent->article] = is_array($parent->raw ?? null) ? $parent->raw : (array) ($parent->raw ?? []);
                        });
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
}
