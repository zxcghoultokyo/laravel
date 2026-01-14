<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Services\Ai\ProductIndexBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parent-based AI enrichment job.
 * 
 * Замість збагачення кожного варіанту товару окремо:
 * 1. Групуємо товари по parent_article
 * 2. Збагачуємо тільки "головний" товар з групи
 * 3. Копіюємо AI-індекс на всі варіанти
 * 
 * Це економить API виклики: замість 10 запитів на 10 кольорів = 1 запит.
 */
class ParentBasedEnrichmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;

    public function __construct(
        public int $batchSize = 20,
        public int $offset = 0,
        public bool $onlyMissing = true
    ) {}

    public function handle(ProductIndexBuilder $builder): void
    {
        // Отримати унікальні parent_article що потребують enrichment
        $query = DB::table('products')
            ->select('parent_article')
            ->whereNotNull('parent_article')
            ->where('parent_article', '!=', '')
            ->where('in_stock', true)
            ->groupBy('parent_article');

        if ($this->onlyMissing) {
            // Тільки ті що не мають AI-індексу
            $query->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('product_ai_index as ai')
                    ->join('products as p2', 'p2.id', '=', 'ai.product_id')
                    ->whereRaw('p2.parent_article = products.parent_article')
                    ->whereNotNull('ai.product_type');
            });
        }

        $parentArticles = $query
            ->orderBy('parent_article')
            ->skip($this->offset)
            ->take($this->batchSize)
            ->pluck('parent_article')
            ->toArray();

        if (empty($parentArticles)) {
            Log::info('ParentBasedEnrichmentJob: no more parent articles to process');
            return;
        }

        Log::info('ParentBasedEnrichmentJob: processing batch', [
            'offset' => $this->offset,
            'count' => count($parentArticles),
        ]);

        $processed = 0;
        $copied = 0;

        foreach ($parentArticles as $parentArticle) {
            try {
                $result = $this->enrichParentGroup($parentArticle, $builder);
                $processed++;
                $copied += $result['copied'];
            } catch (\Throwable $e) {
                Log::error('ParentBasedEnrichmentJob: failed to process parent', [
                    'parent_article' => $parentArticle,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ParentBasedEnrichmentJob: batch completed', [
            'processed' => $processed,
            'copied' => $copied,
        ]);

        // Dispatch next batch
        if (count($parentArticles) === $this->batchSize) {
            self::dispatch($this->batchSize, $this->offset + $this->batchSize, $this->onlyMissing)
                ->delay(now()->addSeconds(2));
        }
    }

    /**
     * Збагатити групу товарів з однаковим parent_article.
     */
    private function enrichParentGroup(string $parentArticle, ProductIndexBuilder $builder): array
    {
        // Знайти всі товари з цим parent_article
        $products = Product::where('parent_article', $parentArticle)
            ->where('in_stock', true)
            ->get();

        if ($products->isEmpty()) {
            return ['copied' => 0];
        }

        // Вибрати "головний" товар для AI-аналізу (з найповнішим описом)
        $mainProduct = $this->selectMainProduct($products);

        // Перевірити чи вже є AI-індекс
        $existingIndex = ProductAiIndex::where('product_id', $mainProduct->id)
            ->whereNotNull('product_type')
            ->first();

        // Якщо немає - створити через AI
        if (!$existingIndex) {
            $existingIndex = $builder->buildForProduct($mainProduct);
            
            Log::info('ParentBasedEnrichmentJob: enriched main product', [
                'parent_article' => $parentArticle,
                'main_product_id' => $mainProduct->id,
                'product_type' => $existingIndex->product_type,
            ]);
        }

        // Скопіювати AI-індекс на всі інші варіанти
        $copied = 0;
        foreach ($products as $product) {
            if ($product->id === $mainProduct->id) {
                continue;
            }

            // Копіюємо тільки якщо немає власного індексу
            $hasIndex = ProductAiIndex::where('product_id', $product->id)
                ->whereNotNull('product_type')
                ->exists();

            if (!$hasIndex) {
                ProductAiIndex::updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'product_type' => $existingIndex->product_type,
                        'ai_category' => $existingIndex->ai_category,
                        'materials' => $existingIndex->materials,
                        'standards' => $existingIndex->standards,
                        'slang' => $existingIndex->slang,
                        'keywords' => $existingIndex->keywords,
                        'usage' => $existingIndex->usage,
                        'raw_ai_json' => null, // Не копіюємо raw - це копія
                    ]
                );
                $copied++;
            }
        }

        return ['copied' => $copied];
    }

    /**
     * Вибрати найкращий товар для AI-аналізу з групи.
     * Критерії: найдовший опис, є характеристики, є фото.
     */
    private function selectMainProduct($products): Product
    {
        return $products->sortByDesc(function ($product) {
            $score = 0;
            
            // Довжина опису
            $raw = is_array($product->raw) ? $product->raw : json_decode($product->raw ?? '{}', true);
            $description = $raw['description'] ?? '';
            $score += mb_strlen($description);
            
            // Наявність характеристик
            $characteristics = $raw['characteristics'] ?? [];
            $score += count($characteristics) * 10;
            
            // Наявність фото
            $photos = $raw['photos'] ?? [];
            $score += count($photos) * 5;
            
            return $score;
        })->first();
    }
}
