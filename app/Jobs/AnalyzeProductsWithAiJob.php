<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Models\SyncLog;
use App\Scopes\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI-аналіз товарів для покращення пошуку.
 * 
 * Генерує:
 * - keywords: ключові слова українською та англійською
 * - slang: сленгові назви, скорочення, жаргон
 * - synonyms: синоніми назви товару
 * - search_queries: типові пошукові запити користувачів
 * - materials: матеріали
 * - standards: стандарти захисту
 * - usage: призначення/застосування
 */
class AnalyzeProductsWithAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;

    /**
     * Constructor with optional tenant_id filter for tenant-specific enrichment.
     * 
     * @param int $batchSize Products per batch
     * @param int $offset Skip first N products
     * @param bool $forceReanalyze Re-analyze even if already has keywords
     * @param int|null $tenantId If set, only analyze products for this tenant
     */
    public function __construct(
        public int $batchSize = 10,
        public int $offset = 0,
        public bool $forceReanalyze = false,
        public ?int $tenantId = null
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // Build log message with tenant info if specified
        $logMsg = $this->tenantId 
            ? "AI enrichment batch={$this->batchSize} tenant=#{$this->tenantId}"
            : "AI enrichment batch={$this->batchSize}";
        
        // Only log SyncLog for first batch (offset = 0)
        $syncLog = $this->offset === 0 
            ? SyncLog::start(SyncLog::TYPE_AI_ENRICHMENT, $logMsg)
            : null;
        
        try {
            $this->processAnalysis($syncLog);
        } catch (\Throwable $e) {
            if ($syncLog) {
                $syncLog->fail($e->getMessage());
            }
            throw $e;
        }
    }

    private function processAnalysis(?SyncLog $syncLog): void
    {
        $config = config('services.openai', []);
        $apiKey = $config['key'] ?? null;
        
        if (!$apiKey) {
            Log::error('AnalyzeProductsWithAiJob: OpenAI API key not configured');
            if ($syncLog) {
                $syncLog->fail('OpenAI API key not configured');
            }
            return;
        }

        // Get products to analyze (bypass tenant scope for system job)
        $query = Product::withoutGlobalScope(TenantScope::class)
            ->where('in_stock', true)
            ->whereNotNull('title')
            ->orderBy('id');
        
        // Filter by tenant if specified
        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        // Skip already analyzed unless force
        if (!$this->forceReanalyze) {
            $query->whereNotIn('id', function ($q) {
                $q->select('product_id')
                    ->from('product_ai_index')
                    ->whereNotNull('keywords');
            });
        }

        $products = $query->skip($this->offset)
            ->take($this->batchSize)
            ->get();

        if ($products->isEmpty()) {
            Log::info('AnalyzeProductsWithAiJob: no more products to analyze');
            if ($syncLog) {
                $syncLog->complete(['message' => 'No more products to analyze']);
            }
            return;
        }

        Log::info('AnalyzeProductsWithAiJob: analyzing batch', [
            'offset' => $this->offset,
            'count' => $products->count(),
        ]);

        $analyzed = 0;
        $errors = 0;
        foreach ($products as $product) {
            try {
                $this->analyzeProduct($product, $apiKey, $config);
                $analyzed++;
            } catch (\Throwable $e) {
                $errors++;
                Log::error('AnalyzeProductsWithAiJob: failed to analyze product', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Complete sync log for first batch only
        if ($syncLog) {
            $syncLog->complete([
                'analyzed' => $analyzed,
                'errors' => $errors,
                'batch_size' => $this->batchSize,
            ]);
        }

        // Dispatch next batch
        if ($products->count() === $this->batchSize) {
            self::dispatch($this->batchSize, $this->offset + $this->batchSize, $this->forceReanalyze)
                ->delay(now()->addSeconds(2));
        }
    }

    private function analyzeProduct(Product $product, string $apiKey, array $config): void
    {
        $raw = is_array($product->raw) ? $product->raw : json_decode($product->raw ?? '{}', true);
        
        $title = $product->title ?? '';
        $description = $this->extractDescription($raw);
        $category = $product->category_path ?? '';
        $characteristics = $this->extractCharacteristics($raw);

        $prompt = $this->buildPrompt($title, $description, $category, $characteristics);

        // Use model_analyze for batch enrichment (cheaper model like gpt-5-nano)
        $model = $config['model_analyze'] ?? 'gpt-5-nano';
        
        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post(rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a military/tactical gear expert. Respond ONLY with valid JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 800,
            ]);

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            Log::warning('AnalyzeProductsWithAiJob: empty AI response', ['product_id' => $product->id]);
            return;
        }

        // Parse JSON from response
        $json = $this->parseJsonFromResponse($content);
        if (!$json) {
            Log::warning('AnalyzeProductsWithAiJob: failed to parse AI JSON', [
                'product_id' => $product->id,
                'content' => mb_substr($content, 0, 500),
            ]);
            return;
        }

        // Save to product_ai_index
        ProductAiIndex::updateOrCreate(
            ['product_id' => $product->id],
            [
                'product_type' => $json['product_type'] ?? null,
                'ai_category' => $json['ai_category'] ?? null,
                'materials' => $json['materials'] ?? [],
                'standards' => $json['standards'] ?? [],
                'slang' => $json['slang'] ?? [],
                'keywords' => $json['keywords'] ?? [],
                'usage' => $json['usage'] ?? [],
                'raw_ai_json' => $json,
            ]
        );

        Log::info('AnalyzeProductsWithAiJob: analyzed product', [
            'product_id' => $product->id,
            'title' => $title,
            'keywords_count' => count($json['keywords'] ?? []),
            'slang_count' => count($json['slang'] ?? []),
        ]);
    }

    private function buildPrompt(string $title, string $description, string $category, string $characteristics): string
    {
        return <<<PROMPT
Проаналізуй цей товар військового спорядження та згенеруй JSON для пошукового індексу.

ТОВАР:
Назва: {$title}
Категорія: {$category}
Опис: {$description}
Характеристики: {$characteristics}

Згенеруй JSON з полями:

1. "product_type": основний тип товару англійською (plate_carrier, helmet, boots, pouch, gloves, uniform, etc)

2. "ai_category": загальна категорія (armor, apparel, footwear, accessories, bags, optics, etc)

3. "keywords": масив 10-20 ключових слів УКРАЇНСЬКОЮ та АНГЛІЙСЬКОЮ для пошуку.
   Включи: назву, бренд, тип, призначення, характеристики.
   Приклад: ["плитоноска", "plate carrier", "бронежилет", "тактичний жилет", "molle", "швидкоскид"]

4. "slang": масив 5-10 сленгових/жаргонних назв УКРАЇНСЬКОЮ як шукають реальні люди.
   Приклад для плитоноски: ["плитка", "бронік", "броня", "жилетка", "pc"]
   Приклад для шолома: ["каска", "шлем", "кєпка", "балістика"]
   Приклад для берців: ["берци", "черевики", "боти", "чоботи"]

5. "synonyms": масив синонімів назви товару (варіанти написання, переклади)

6. "search_queries": масив 5-10 ТИПОВИХ ПОШУКОВИХ ЗАПИТІВ українською як їх пише користувач.
   Приклад: ["яку плитоноску обрати", "плитоноска для штурму", "легка плитоноска до 15000"]

7. "materials": масив матеріалів якщо є (cordura, nylon, PE, ceramic, steel)

8. "standards": масив стандартів якщо є (NIJ III, NIJ IV, ДСТУ 8782, STANAG)

9. "usage": масив призначення (assault, reconnaissance, training, everyday)

ВАЖЛИВО:
- Всі ключові слова МАЛИМИ ЛІТЕРАМИ
- Українські слова в різних відмінках не потрібні, тільки основна форма
- Slang має бути РЕАЛЬНИМ жаргоном який використовують військові/страйкболісти
- Search queries мають бути РЕАЛІСТИЧНИМИ запитами з чату

Відповідай ТІЛЬКИ валідним JSON без markdown.
PROMPT;
    }

    private function extractDescription(array $raw): string
    {
        $desc = $raw['description'] ?? $raw['description_full'] ?? '';
        if (is_array($desc)) {
            $desc = $desc['uk'] ?? $desc['ua'] ?? reset($desc) ?: '';
        }
        return mb_substr(strip_tags((string)$desc), 0, 1000);
    }

    private function extractCharacteristics(array $raw): string
    {
        $chars = $raw['characteristics'] ?? $raw['attrs'] ?? [];
        if (!is_array($chars)) {
            return '';
        }
        
        $lines = [];
        foreach ($chars as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $lines[] = "{$key}: {$value}";
        }
        return implode('; ', array_slice($lines, 0, 20));
    }

    private function parseJsonFromResponse(string $content): ?array
    {
        // Remove markdown code blocks if present
        $content = preg_replace('/```json\s*/i', '', $content);
        $content = preg_replace('/```\s*$/i', '', $content);
        $content = trim($content);

        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        // Try to extract JSON from text
        if (preg_match('/\{[\s\S]*\}/u', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }

        return null;
    }
}
