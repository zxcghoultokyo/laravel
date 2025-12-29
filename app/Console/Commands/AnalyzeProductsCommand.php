<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeProductsWithAiJob;
use App\Models\Product;
use App\Models\ProductAiIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnalyzeProductsCommand extends Command
{
    protected $signature = 'products:analyze 
        {--batch=50 : Products per batch (then pause)}
        {--force : Re-analyze already processed products}
        {--all : Analyze ALL products, not just in_stock}
        {--limit=0 : Limit total products to analyze (0 = no limit)}
        {--continue : Auto-continue after each batch}
        {--delay=1 : Delay between requests in seconds}';

    protected $description = 'Analyze products with AI to generate search keywords, slang, synonyms';

    private int $processed = 0;
    private int $failed = 0;
    private int $consecutiveErrors = 0;

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $force = $this->option('force');
        $all = $this->option('all');
        $limit = (int) $this->option('limit');
        $autoContinue = $this->option('continue');
        $delay = max(0.5, (float) $this->option('delay'));

        $config = config('services.openai', []);
        $apiKey = $config['key'] ?? null;
        
        if (!$apiKey) {
            $this->error('OpenAI API key not configured!');
            return 1;
        }

        do {
            // Count products to analyze (refresh each batch)
            $query = Product::query()->whereNotNull('title');
            
            if (!$all) {
                $query->where('in_stock', true);
            }

            if (!$force) {
                // Skip products with good analysis (keywords array with 3+ items)
                $query->whereNotIn('id', function ($q) {
                    $q->select('product_id')
                        ->from('product_ai_index')
                        ->whereRaw("JSON_LENGTH(keywords) >= 3");
                });
            }

            $remaining = $query->count();
            $alreadyAnalyzed = ProductAiIndex::whereRaw("JSON_LENGTH(keywords) >= 3")->count();
            $poorAnalysis = ProductAiIndex::whereRaw("JSON_LENGTH(keywords) < 3 OR keywords IS NULL")->count();

            $this->newLine();
            $this->info("=== Product AI Analysis ===");
            $this->info("Total in DB: " . Product::count());
            $this->info("In stock: " . Product::where('in_stock', true)->count());
            $this->info("Good analysis (3+ keywords): {$alreadyAnalyzed}");
            $this->info("Poor/empty analysis: {$poorAnalysis}");
            $this->info("Remaining to analyze: {$remaining}");
            
            if ($remaining === 0) {
                $this->info('✅ All products analyzed!');
                return 0;
            }

            $batchCount = min($batchSize, $remaining);
            if ($limit > 0) {
                $batchCount = min($batchCount, $limit);
            }
            
            $this->info("This batch: {$batchCount} products");
            
            // Show config (masked key) - use model_analyze for product analysis (cheaper)
            $maskedKey = substr($apiKey, 0, 8) . '...' . substr($apiKey, -4);
            $analyzeModel = $config['model_analyze'] ?? $config['model'] ?? 'gpt-4.1-mini';
            $this->info("API Key: {$maskedKey}");
            $this->info("Model: {$analyzeModel} (model_analyze)");
            $this->newLine();

            // Get batch of products (skip only good analysis with 3+ keywords)
            $products = Product::query()
                ->whereNotNull('title')
                ->when(!$all, fn($q) => $q->where('in_stock', true))
                ->when(!$force, fn($q) => $q->whereNotIn('id', function ($sub) {
                    $sub->select('product_id')
                        ->from('product_ai_index')
                        ->whereRaw("JSON_LENGTH(keywords) >= 3");
                }))
                ->orderBy('id')
                ->limit($batchCount)
                ->get();

            $bar = $this->output->createProgressBar($products->count());
            $bar->start();

            $batchProcessed = 0;
            $batchFailed = 0;

            foreach ($products as $product) {
                try {
                    $result = $this->analyzeProduct($product, $apiKey, $config);
                    if ($result) {
                        $batchProcessed++;
                        $this->processed++;
                        $this->consecutiveErrors = 0;
                        $this->line(" ✓ [{$product->id}] " . mb_substr($product->title, 0, 60));
                    } else {
                        $batchFailed++;
                        $this->failed++;
                        $this->consecutiveErrors++;
                        $this->line(" ✗ [{$product->id}] Failed");
                    }
                } catch (\Throwable $e) {
                    $batchFailed++;
                    $this->failed++;
                    $this->consecutiveErrors++;
                    $errorMsg = mb_substr($e->getMessage(), 0, 100);
                    $this->line(" ✗ [{$product->id}] {$errorMsg}");
                    
                    // If too many consecutive errors, pause longer
                    if ($this->consecutiveErrors >= 5) {
                        $this->warn("⚠️ 5 consecutive errors, pausing 30s...");
                        sleep(30);
                        $this->consecutiveErrors = 0;
                    }
                }
                
                $bar->advance();
                
                // Rate limiting
                usleep((int)($delay * 1000000));
            }

            $bar->finish();
            $this->newLine(2);
            
            $this->info("Batch done: ✓ {$batchProcessed} / ✗ {$batchFailed}");
            $this->info("Session total: ✓ {$this->processed} / ✗ {$this->failed}");
            $this->info("Total analyzed: " . ProductAiIndex::whereNotNull('keywords')->count());
            
            // Check if we should continue
            if (!$autoContinue) {
                $remaining = Product::query()
                    ->whereNotNull('title')
                    ->when(!$all, fn($q) => $q->where('in_stock', true))
                    ->whereNotIn('id', function ($q) {
                        $q->select('product_id')
                            ->from('product_ai_index')
                            ->whereNotNull('keywords');
                    })
                    ->count();
                    
                if ($remaining > 0) {
                    $this->newLine();
                    $this->info("💡 Run again to continue: php artisan products:analyze --continue");
                }
                break;
            }
            
            // Small pause between batches
            $this->info("⏳ Pausing 5s before next batch...");
            sleep(5);
            
        } while ($autoContinue);

        return 0;
    }

    private function analyzeProduct(Product $product, string $apiKey, array $config): bool
    {
        $raw = is_array($product->raw) ? $product->raw : json_decode($product->raw ?? '{}', true);
        
        $title = $product->title ?? '';
        $description = $this->extractDescription($raw);
        $category = $product->category_path ?? '';
        $characteristics = $this->extractCharacteristics($raw);

        $prompt = $this->buildPrompt($title, $description, $category, $characteristics);

        try {
            // Use model_analyze (cheaper) for product analysis
            $model = $config['model_analyze'] ?? $config['model'] ?? 'gpt-4.1-mini';
            
            // Build request payload - universal for all models
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a military/tactical gear expert. Respond ONLY with valid JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ];
            
            // Models that DON'T support temperature parameter
            $noTemperatureModels = ['o1', 'o3', 'gpt-5-nano', 'o1-mini', 'o1-preview', 'o3-mini'];
            $supportsTemperature = !collect($noTemperatureModels)->contains(fn($m) => str_starts_with($model, $m));
            
            if ($supportsTemperature) {
                $payload['temperature'] = 0.3;
            }
            
            // Models that use max_completion_tokens instead of max_tokens
            $newTokenModels = ['gpt-5', 'o1', 'o3'];
            $usesNewTokenParam = collect($newTokenModels)->contains(fn($m) => str_starts_with($model, $m));
            
            if ($usesNewTokenParam) {
                $payload['max_completion_tokens'] = 800;
            } else {
                $payload['max_tokens'] = 800;
            }
            
            $response = Http::timeout(30)
                ->withToken($apiKey)
                ->post(rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions', $payload);

            $data = $response->json();
            
            // Check for API errors
            if (isset($data['error'])) {
                $this->error("API Error: " . ($data['error']['message'] ?? json_encode($data['error'])));
                return false;
            }
            
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                $this->error("Empty response. Status: " . $response->status() . " Body: " . mb_substr($response->body(), 0, 200));
                return false;
            }

            $json = $this->parseJsonFromResponse($content);
            if (!$json) {
                $this->error("Failed to parse JSON from: " . mb_substr($content, 0, 300));
                return false;
            }

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

            return true;
        } catch (\Throwable $e) {
            $this->error("Exception: " . $e->getMessage());
            return false;
        }
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
        $content = preg_replace('/```json\s*/i', '', $content);
        $content = preg_replace('/```\s*$/i', '', $content);
        $content = trim($content);

        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        if (preg_match('/\{[\s\S]*\}/u', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }

        return null;
    }
}
