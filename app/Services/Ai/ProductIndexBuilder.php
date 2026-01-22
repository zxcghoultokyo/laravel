<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Services\Search\SlangDictionaryService;
use App\Services\Ai\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductIndexBuilder
{
    protected int $maxRetries = 3;
    protected int $retryDelayMs = 100;
    protected ?\Closure $onLockRetry = null;
    protected ?SlangDictionaryService $slangDictionary = null;
    protected ?EmbeddingService $embeddingService = null;
    protected bool $generateEmbeddings = true;

    /**
     * Встановити callback для виводу при lock retry.
     */
    public function onLockRetry(\Closure $callback): self
    {
        $this->onLockRetry = $callback;
        return $this;
    }
    
    /**
     * Enable or disable embedding generation.
     */
    public function withEmbeddings(bool $enabled = true): self
    {
        $this->generateEmbeddings = $enabled;
        return $this;
    }
    
    /**
     * Get or create slang dictionary service.
     */
    protected function getSlangDictionary(): SlangDictionaryService
    {
        if (!$this->slangDictionary) {
            $this->slangDictionary = app(SlangDictionaryService::class);
        }
        return $this->slangDictionary;
    }
    
    /**
     * Get or create embedding service.
     */
    protected function getEmbeddingService(): EmbeddingService
    {
        if (!$this->embeddingService) {
            $this->embeddingService = app(EmbeddingService::class);
        }
        return $this->embeddingService;
    }
    
    /**
     * Generate embedding for a product.
     */
    protected function generateEmbedding(Product $product, array $payload): ?array
    {
        if (!$this->generateEmbeddings) {
            return null;
        }
        
        try {
            $service = $this->getEmbeddingService();
            
            if (!$service->isAvailable()) {
                return null;
            }
            
            // Build text for embedding
            $text = $service->buildProductText([
                'title' => $product->title,
                'category_path' => $product->category_path,
                'brand' => $product->brand,
                'keywords' => $payload['keywords'] ?? [],
                'slang' => $payload['slang'] ?? [],
                'description' => $this->extractDescription($product),
            ]);
            
            $embedding = $service->embed($text);
            
            if ($embedding) {
                Log::debug('ProductIndexBuilder: generated embedding', [
                    'product_id' => $product->id,
                    'text_length' => mb_strlen($text),
                    'dimensions' => count($embedding),
                ]);
            }
            
            return $embedding;
            
        } catch (\Throwable $e) {
            Log::warning('ProductIndexBuilder: embedding generation failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Extract description from product raw data.
     */
    protected function extractDescription(Product $product): ?string
    {
        $raw = $product->raw ?? [];
        
        $description = $raw['description']['ua'] 
            ?? $raw['description']['ru'] 
            ?? $raw['short_description']['ua']
            ?? $raw['short_description']['ru']
            ?? null;
            
        if (is_string($description)) {
            return strip_tags($description);
        }
        
        return null;
    }

    public function buildForProduct(Product $product): ProductAiIndex
    {
        $aiData = [];

        try {
            /** @var \App\Services\Ai\AiClient $ai */
            $ai = app(AiClient::class);

            $system = implode("\n", [
                'You are an AI enrichment service for a Ukrainian tactical/military e-commerce catalog.',
                'Return a STRICT JSON object with keys:',
                'product_type: short snake_case type (e.g., helmet, plate_carrier, armor_plate, tshirt, pouch, gloves)',
                'ai_category: broad category (e.g., helmets, armor, apparel, accessories, pouches)',
                'materials: JSON array of strings ["nylon", "cordura"] or null',
                'standards: JSON array of strings ["NIJ III+", "DSTU"] or null',
                'slang: JSON array of UKRAINIAN slang/jargon names that people use to search for this product.',
                '  Examples: plate_carrier -> ["плитка", "бронік", "pc", "розгрузка", "жилетка"]',
                '  Examples: helmet -> ["каска", "шолом", "кевлар", "бумпер"]',
                '  Examples: armor_plate -> ["плита", "броня", "кераміка", "сталевка"]',
                '  IMPORTANT: Generate 5-10 slang terms that Ukrainian military/airsoft community uses!',
                'keywords: JSON array of search keywords in Ukrainian and English',
                'usage: JSON array of use cases ["assault", "training", "everyday"] or null',
                'IMPORTANT: Always return arrays for materials, standards, slang, keywords, usage - never comma-separated strings!',
                'If unsure about other fields, leave them null but ALWAYS generate slang for tactical products.',
            ]);

            $payload = [
                'title'         => (string) ($product->title ?? ''),
                'category_path' => (string) ($product->category_path ?? ''),
                'raw'           => $product->raw ?? null,
                'color'         => (string) ($product->color ?? ''),
                'brand'         => (string) ($product->brand ?? ''),
                'search_index'  => (string) ($product->search_index ?? ''),
            ];

            // Use gpt-4o-mini for enrichment - best cost/performance balance
            // Note: gpt-5-nano has too many restrictions (rate limits, no response_format, etc.)
            $model = 'gpt-4o-mini';
            
            $aiData = $ai->chatJson($system, $payload, [
                'temperature' => 0.3,
                'model' => $model,
            ]);
            
            // Log AI response for debugging
            Log::info('ProductIndexBuilder AI response', [
                'product_id' => $product->id,
                'slang' => $aiData['slang'] ?? 'MISSING',
                'product_type' => $aiData['product_type'] ?? 'MISSING',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ProductIndexBuilder::buildForProduct AI error: ' . $e->getMessage());
        }

        // fallback, якщо AI нічого не віддав
        $defaults = $this->fallbackFromProduct($product);

        $payload = array_merge($defaults, is_array($aiData) ? $aiData : []);
        
        // Augment slang from dictionary based on product_type
        $productType = $payload['product_type'] ?? null;
        if ($productType) {
            $existingSlang = $payload['slang'] ?? [];
            if (is_string($existingSlang)) {
                $existingSlang = [$existingSlang];
            }
            $payload['slang'] = $this->getSlangDictionary()->getAugmentedSlang($productType, $existingSlang);
            
            Log::debug('ProductIndexBuilder: augmented slang', [
                'product_id' => $product->id,
                'product_type' => $productType,
                'original_slang' => $existingSlang,
                'augmented_slang' => $payload['slang'],
            ]);
        }

        // Generate embedding for semantic search
        $embedding = $this->generateEmbedding($product, $payload);

        // Забезпечити наявність raw_ai_json для аудиту/дебагу
        $rawJson = null;
        try {
            $rawJson = json_encode($aiData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            $rawJson = null;
        }

        return $this->upsertWithRetry($product->id, [
            'product_type' => $payload['product_type'] ?? null,
            'ai_category'  => $payload['ai_category'] ?? null,
            'materials'    => $payload['materials'] ?? null,
            'standards'    => $payload['standards'] ?? null,
            'slang'        => $payload['slang'] ?? null,
            'keywords'     => $payload['keywords'] ?? null,
            'usage'        => $payload['usage'] ?? null,
            'embedding'    => $embedding,
            'raw_ai_json'  => $rawJson,
        ]);
    }

    /**
     * Швидкий білд без AI — тільки rule-based fallback.
     * Корисно для швидкого первинного заповнення або при недоступності API.
     */
    public function buildForProductFallbackOnly(Product $product): ProductAiIndex
    {
        $payload = $this->fallbackFromProduct($product);

        return $this->upsertWithRetry($product->id, [
            'product_type' => $payload['product_type'] ?? null,
            'ai_category'  => $payload['ai_category'] ?? null,
            'materials'    => $payload['materials'] ?? null,
            'standards'    => $payload['standards'] ?? null,
            'slang'        => $payload['slang'] ?? null,
            'keywords'     => $payload['keywords'] ?? null,
            'usage'        => $payload['usage'] ?? null,
            'embedding'    => $payload['embedding'] ?? null,
            'raw_ai_json'  => null,
        ]);
    }

    /**
     * Batch upsert для масового оновлення без AI.
     * Значно швидше ніж по одному.
     *
     * @param iterable<Product> $products
     * @return int кількість оброблених
     */
    public function buildBatchFallbackOnly(iterable $products): int
    {
        $rows = [];
        $now = now();

        foreach ($products as $product) {
            $payload = $this->fallbackFromProduct($product);
            $rows[] = [
                'product_id'   => $product->id,
                'product_type' => $payload['product_type'] ?? null,
                'ai_category'  => $payload['ai_category'] ?? null,
                'materials'    => $payload['materials'] ?? null,
                'standards'    => $payload['standards'] ?? null,
                'slang'        => $payload['slang'] ?? null,
                'keywords'     => $payload['keywords'] ?? null,
                'usage'        => $payload['usage'] ?? null,
                'embedding'    => null,
                'raw_ai_json'  => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        return $this->batchUpsertWithRetry($rows);
    }

    /**
     * Upsert з retry для обходу database lock.
     */
    protected function upsertWithRetry(int $productId, array $data): ProductAiIndex
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                return ProductAiIndex::updateOrCreate(
                    ['product_id' => $productId],
                    $data
                );
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;

                // Якщо це lock — чекаємо і пробуємо знову
                if ($this->isLockException($e) && $attempt < $this->maxRetries) {
                    $delayMs = $this->retryDelayMs * $attempt;
                    usleep($delayMs * 1000); // exponential backoff
                    
                    $msg = "DB LOCK: retry {$attempt}/{$this->maxRetries} for product {$productId}, wait {$delayMs}ms";
                    Log::debug("ProductIndexBuilder: " . $msg);
                    
                    if ($this->onLockRetry) {
                        ($this->onLockRetry)($msg);
                    }
                    continue;
                }

                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * Batch upsert з retry.
     */
    protected function batchUpsertWithRetry(array $rows): int
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                ProductAiIndex::upsert(
                    $rows,
                    ['product_id'],
                    ['product_type', 'ai_category', 'materials', 'standards', 'slang', 'keywords', 'usage', 'embedding', 'raw_ai_json', 'updated_at']
                );
                return count($rows);
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;

                            if ($this->isLockException($e) && $attempt < $this->maxRetries) {
                    $delayMs = $this->retryDelayMs * $attempt;
                    usleep($delayMs * 1000);
                    
                    $msg = "DB LOCK: batch retry {$attempt}/{$this->maxRetries}, wait {$delayMs}ms";
                    Log::debug("ProductIndexBuilder: " . $msg);
                    
                    if ($this->onLockRetry) {
                        ($this->onLockRetry)($msg);
                    }
                    continue;
                }

                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * Перевірка чи exception — це database lock.
     */
    protected function isLockException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'lock')
            || str_contains($message, 'deadlock')
            || str_contains($message, 'try restarting transaction')
            || str_contains($message, 'database is locked')
            || $e->getCode() == 1213  // MySQL deadlock
            || $e->getCode() == 1205; // MySQL lock wait timeout
    }

    /**
     * Дуже простий fallback: щось витягуємо з самого продукту,
     * щоб запис не був пустим навіть без AI.
     */
    protected function fallbackFromProduct(Product $product): array
    {
        $category = (string) ($product->category_path ?? '');
        $title    = (string) ($product->title ?? '');

        // Rule-based mapping: базове покриття без LLM
        $mapType = $this->mapTypeFromCategory($category);

        return [
            'product_type' => $mapType,
            'ai_category'  => $this->mapAiCategoryFromType($mapType),
            'materials'    => null,
            'standards'    => null,
            'slang'        => null,
            'keywords'     => trim($title . ' ' . $category),
            'usage'        => null,
            'embedding'    => null,
        ];
    }

    protected function mapTypeFromCategory(?string $categoryPath): ?string
    {
        if (! $categoryPath) {
            return null;
        }
        $q = mb_strtolower($categoryPath);

        // UA/RU keywords for core mappings
        if (mb_stripos($q, 'шолом') !== false || mb_stripos($q, 'каска') !== false || mb_stripos($q, 'шоломи') !== false) {
            return 'helmet';
        }
        if (mb_stripos($q, 'бронепластин') !== false || mb_stripos($q, 'плита') !== false || mb_stripos($q, 'плити') !== false) {
            return 'armor_plate';
        }
        if (mb_stripos($q, 'плитоноск') !== false) {
            return 'plate_carrier';
        }
        if (mb_stripos($q, 'футболк') !== false || mb_stripos($q, 'футболка') !== false) {
            return 'tshirt';
        }
        return null;
    }

    protected function mapAiCategoryFromType(?string $type): ?string
    {
        return match ($type) {
            'helmet'        => 'helmets',
            'armor_plate'   => 'armor',
            'plate_carrier' => 'armor',
            'tshirt'        => 'apparel',
            default         => null,
        };
    }
}
