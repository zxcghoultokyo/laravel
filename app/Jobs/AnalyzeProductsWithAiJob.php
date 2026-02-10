<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Models\SyncLog;
use App\Models\TenantOnboardingProgress;
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

    public int $timeout = 600; // 10 minutes for batch with rate limiting delays
    public int $tries = 3;

    /**
     * Constructor with optional tenant_id filter for tenant-specific enrichment.
     * 
     * @param int $batchSize Products per batch
     * @param int $offset Skip first N products
     * @param bool $forceReanalyze Re-analyze even if already has keywords
     * @param int|null $tenantId If set, only analyze products for this tenant
     * @param bool $singleBatchOnly If true, don't auto-dispatch next batch (used by OnboardTenantJob)
     */
    public function __construct(
        public int $batchSize = 10,
        public int $offset = 0,
        public bool $forceReanalyze = false,
        public ?int $tenantId = null,
        public bool $singleBatchOnly = false
    ) {
        // Use meili queue to avoid blocking OnboardTenantJob on default queue
        $this->onQueue('meili');
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
            
            // Mark AI enrichment as completed in onboarding progress
            if ($this->tenantId !== null) {
                $this->markAiEnrichmentCompleted();
            }
            return;
        }

        Log::info('AnalyzeProductsWithAiJob: analyzing batch', [
            'offset' => $this->offset,
            'count' => $products->count(),
        ]);

        $analyzed = 0;
        $errors = 0;
        foreach ($products as $index => $product) {
            try {
                $this->analyzeProduct($product, $apiKey, $config);
                $analyzed++;
                
                // Rate limit protection: delay between API calls (skip for last product)
                if ($index < $products->count() - 1) {
                    usleep(300000); // 300ms delay = ~3 requests/sec
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::error('AnalyzeProductsWithAiJob: failed to analyze product', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
                
                // On rate limit, wait longer
                if (str_contains($e->getMessage(), 'rate_limit') || str_contains($e->getMessage(), '429')) {
                    sleep(5);
                }
            }
        }

        // Complete sync log for first batch only
        if ($syncLog) {
            $syncLog->complete([
                'analyzed' => $analyzed,
                'errors' => $errors,
                'batch_size' => $this->batchSize,
                'tenant_id' => $this->tenantId,
            ]);
        }

        // Update onboarding progress if tenant-specific job
        if ($this->tenantId !== null) {
            $this->updateOnboardingProgress($analyzed);
        }

        // Dispatch next batch if more products exist (unless singleBatchOnly mode)
        if ($products->count() === $this->batchSize && !$this->singleBatchOnly) {
            Log::info('AnalyzeProductsWithAiJob: dispatching next batch', [
                'tenant_id' => $this->tenantId,
                'next_offset' => $this->offset + $this->batchSize,
            ]);
            
            self::dispatch(
                batchSize: $this->batchSize,
                offset: $this->offset + $this->batchSize,
                forceReanalyze: $this->forceReanalyze,
                tenantId: $this->tenantId  // CRITICAL: pass tenant_id to next batch
            )->onQueue('meili')->delay(now()->addSeconds(2));
        }
    }

    /**
     * Update onboarding progress with current AI enrichment stats
     * Note: Updates even if onboarding is "completed" - AI runs in background
     */
    private function updateOnboardingProgress(int $batchAnalyzed): void
    {
        $progress = TenantOnboardingProgress::where('tenant_id', $this->tenantId)->first();
        
        if (!$progress) {
            return;
        }

        // Allow updates even if onboarding is "completed" - AI enrichment continues in background
        // Only skip if AI step itself is already marked as completed with 100%
        $aiStep = $progress->steps['ai_enrichment'] ?? null;
        if ($aiStep && $aiStep['status'] === 'completed' && ($aiStep['percent'] ?? 0) >= 100) {
            return;
        }

        // Count total products for this tenant (in_stock only)
        $totalProducts = Product::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->where('in_stock', true)
            ->count();

        // Count enriched products (in_stock only to match total)
        $enrichedCount = ProductAiIndex::whereHas('product', function ($q) {
            $q->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $this->tenantId)
                ->where('in_stock', true);
        })->whereNotNull('keywords')->count();

        // Calculate progress
        $percent = $totalProducts > 0 
            ? min(95, (int) round($enrichedCount / $totalProducts * 100))
            : 0;

        $detail = "AI аналіз: {$enrichedCount} з {$totalProducts} товарів";
        
        // Update progress
        $progress->updateStep('ai_enrichment', 'in_progress', $percent, $detail, [
            'total' => $totalProducts,
            'enriched' => $enrichedCount,
            'processed' => $enrichedCount,
            'batch_analyzed' => $batchAnalyzed,
        ]);

        Log::info('AnalyzeProductsWithAiJob: updated onboarding progress', [
            'tenant_id' => $this->tenantId,
            'enriched' => $enrichedCount,
            'total' => $totalProducts,
            'percent' => $percent,
        ]);
    }

    /**
     * Mark AI enrichment as completed when all products are processed
     */
    private function markAiEnrichmentCompleted(): void
    {
        $progress = TenantOnboardingProgress::where('tenant_id', $this->tenantId)->first();
        
        if (!$progress) {
            return;
        }

        // Count final stats (in_stock only)
        $totalProducts = Product::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->where('in_stock', true)
            ->count();

        $enrichedCount = ProductAiIndex::whereHas('product', function ($q) {
            $q->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $this->tenantId)
                ->where('in_stock', true);
        })->whereNotNull('keywords')->count();

        $detail = "AI аналіз завершено: {$enrichedCount} товарів оброблено";
        
        $progress->updateStep('ai_enrichment', 'completed', 100, $detail, [
            'total' => $totalProducts,
            'enriched' => $enrichedCount,
            'processed' => $enrichedCount,
        ]);

        Log::info('AnalyzeProductsWithAiJob: AI enrichment completed', [
            'tenant_id' => $this->tenantId,
            'enriched' => $enrichedCount,
            'total' => $totalProducts,
        ]);

        // Trigger next step: Meilisearch indexing
        // This ensures the onboarding flow continues even if OnboardTenantJob timed out
        $this->triggerMeiliIndexing();
    }

    /**
     * Trigger Meilisearch indexing after AI enrichment completes
     * Note: Triggers even if onboarding status is "completed" - ensures products are searchable
     */
    private function triggerMeiliIndexing(): void
    {
        $progress = TenantOnboardingProgress::where('tenant_id', $this->tenantId)->first();
        
        if (!$progress) {
            return;
        }

        // Check if meili step hasn't started yet or is still pending
        $steps = $progress->steps ?? [];
        $meiliStep = $steps['meili_indexing'] ?? null;
        
        if ($meiliStep && in_array($meiliStep['status'], ['completed', 'in_progress'])) {
            // Meili already running or completed
            return;
        }

        $progress->updateStep('meili_indexing', 'in_progress', 0, 'Запуск індексації...');

        // Dispatch Meili indexing job
        IndexProductsToMeiliJob::dispatch(
            chunk: 500,
            tenantId: $this->tenantId
        );

        Log::info('AnalyzeProductsWithAiJob: triggered Meili indexing', [
            'tenant_id' => $this->tenantId,
        ]);
    }

    private function analyzeProduct(Product $product, string $apiKey, array $config): void
    {
        $raw = is_array($product->raw) ? $product->raw : json_decode($product->raw ?? '{}', true);
        
        $title = $product->title ?? '';
        $description = $this->extractDescription($raw);
        $category = $product->category_path ?? '';
        $characteristics = $this->extractCharacteristics($raw);

        $prompt = $this->buildPrompt($title, $description, $category, $characteristics);

        // Use gpt-4o-mini for batch enrichment - best cost/limits balance
        // Note: gpt-5-nano has severe rate limits and parameter restrictions
        $model = 'gpt-4o-mini';
        
        // Build request body with max_tokens for gpt-4o-mini
        $requestBody = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an e-commerce product expert for Ukrainian market. Classify ANY product type correctly. Respond ONLY with valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 800,
        ];
        
        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post(rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions', $requestBody);

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
        // Pre-detect accessory keywords to give GPT a strong hint
        $titleLower = mb_strtolower($title);
        $isLikelyAccessory = preg_match('/кріплення|адаптер|mount|adapter|подушк|pad|накладк|кавер|чохол|cover|планка|rail|рейка|тримач|holder|противаг|панел|velcro/ui', $titleLower);
        $accessoryHint = $isLikelyAccessory 
            ? "\n\n🚨 УВАГА! Назва містить слово-аксесуар! product_type НЕ МОЖЕ бути 'helmet' або 'plate_carrier'!\n" 
            : '';
        
        return <<<PROMPT
Проаналізуй цей товар та згенеруй JSON для пошукового індексу.
{$accessoryHint}
ТОВАР:
Назва: {$title}
Категорія: {$category}
Опис: {$description}
Характеристики: {$characteristics}

🚨 ПЕРШЕ ПРАВИЛО (ОБОВ'ЯЗКОВЕ):
Перевір назву на слова-аксесуари ПЕРЕД класифікацією:
- "кріплення/адаптер/mount/adapter" → helmet_mount або plate_carrier_mount
- "подушк/pad/накладк" → helmet_pads
- "кавер/чохол/cover" → helmet_cover або plate_carrier_cover  
- "планка/rail/рейка/тримач/holder" → helmet_mount
- "панел/velcro/cummerbund/камербанд" → plate_carrier_accessory
- "підсумок/pouch" → pouch
Якщо назва містить будь-яке з цих слів → ЦЕ АКСЕСУАР!

Згенеруй JSON з полями:

1. "product_type": тип товару англійською (ОБОВ'ЯЗКОВО!)
   
   ⛔ ЗАБОРОНЕНО використовувати "helmet" якщо в назві є:
   кріплення, адаптер, mount, adapter, подушк, pad, накладк, кавер, 
   чохол, cover, планка, rail, рейка, тримач, holder, противаг, панел
   
   ⛔ ЗАБОРОНЕНО використовувати "plate_carrier" якщо в назві є:
   підсумок, pouch, плита окремо, cummerbund, камербанд, панел
   
   ✅ ПРАВИЛЬНІ ТИПИ ДЛЯ АКСЕСУАРІВ:
   - "Кріплення ПНБ на шолом" → "helmet_mount"
   - "Тримач навісного обладнання" → "helmet_mount"
   - "Демпферна подушка PAD" → "helmet_pads"
   - "Адаптер Earmor ARC" → "helmet_mount"
   - "Кріплення чебурашки" → "helmet_mount"
   - "Підсумок для магазинів" → "pouch"
   
   ✅ ТІЛЬКИ ЦІ ТОВАРИ = "helmet":
   - Повноцінний шолом (Ops-Core, FAST, MICH, Sestan)
   - Каска, балістичний/тактичний шолом
   
   ✅ ТІЛЬКИ ЦІ ТОВАРИ = "plate_carrier":
   - Повноцінний плитоносець/жилет
   
   ІНШІ ТИПИ:
   - armor_plate, side_plate (плити)
   - pouch (підсумки)
   - plate_carrier_accessory (камербанд, панелі)
   - boots, gloves, uniform, backpack, holster, tourniquet, flashlight, knife
   - active_headset (Peltor, Comtac)

2. "ai_category": загальна категорія англійською (ОБОВ'ЯЗКОВО!)
   ВАЖЛИВО: Аксесуари мають категорію "accessories"!
   Категорії: armor, apparel, footwear, accessories, bags, optics, electronics, home, 
              beauty, sports, kids, food, books, tools, automotive, pets, garden

3. "keywords": масив 10-20 ключових слів УКРАЇНСЬКОЮ та АНГЛІЙСЬКОЮ для пошуку.
   Включи: назву, бренд, тип, призначення, характеристики, синоніми.

4. "slang": масив 5-10 сленгових назв УКРАЇНСЬКОЮ як шукають реальні люди.

5. "synonyms": масив синонімів назви товару (варіанти написання, переклади)

6. "search_queries": масив 5-10 ТИПОВИХ ПОШУКОВИХ ЗАПИТІВ українською

7. "materials": масив матеріалів якщо є (glass, aluminum, cordura, nylon, leather, etc)

8. "standards": масив стандартів якщо є (IP68, NIJ III, MIL-STD, etc)

9. "usage": масив призначення (everyday, work, military, outdoor, etc)

КРИТИЧНО ВАЖЛИВО:
- product_type та ai_category ОБОВ'ЯЗКОВІ - НІКОЛИ не повертай null!
- АКСЕСУАРИ завжди мають окремий product_type!
- ВИЗНАЧАЙ ТИП ПО НАЗВІ ТОВАРУ, НЕ ПО КАТЕГОРІЇ!
- Якщо назва містить "кріплення/адаптер/кавер/подушки/планка" - це АКСЕСУАР!
- Всі ключові слова МАЛИМИ ЛІТЕРАМИ

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
                // Handle nested structures like {"id": 1, "value": {"ua": "Text"}}
                if (isset($value['value'])) {
                    $value = is_array($value['value']) 
                        ? ($value['value']['ua'] ?? $value['value']['uk'] ?? reset($value['value']) ?: '')
                        : $value['value'];
                } else {
                    // Try to flatten simple arrays
                    $value = implode(', ', array_filter($value, 'is_string'));
                }
            }
            if (is_string($value) && $value !== '') {
                $lines[] = "{$key}: {$value}";
            }
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
