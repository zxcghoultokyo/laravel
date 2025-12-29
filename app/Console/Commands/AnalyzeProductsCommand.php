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
        {--batch=10 : Products per batch}
        {--force : Re-analyze already processed products}
        {--all : Analyze ALL products, not just in_stock}
        {--limit=0 : Limit total products to analyze (0 = no limit)}';

    protected $description = 'Analyze products with AI to generate search keywords, slang, synonyms';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $force = $this->option('force');
        $all = $this->option('all');
        $limit = (int) $this->option('limit');

        // Count products to analyze
        $query = Product::query()->whereNotNull('title');
        
        if (!$all) {
            $query->where('in_stock', true);
        }

        if (!$force) {
            $query->whereNotIn('id', function ($q) {
                $q->select('product_id')
                    ->from('product_ai_index')
                    ->whereNotNull('keywords');
            });
        }

        $total = $query->count();
        $alreadyAnalyzed = ProductAiIndex::whereNotNull('keywords')->count();

        $this->info("=== Product AI Analysis ===");
        $this->info("Total in DB: " . Product::count());
        $this->info("In stock: " . Product::where('in_stock', true)->count());
        $this->info("Already analyzed: {$alreadyAnalyzed}");
        $this->info("To analyze: {$total}");
        
        if ($limit > 0) {
            $total = min($total, $limit);
            $this->info("Limited to: {$total}");
        }

        if ($total === 0) {
            $this->info('Nothing to analyze!');
            return 0;
        }

        $this->info("\nStarting analysis...");
        
        $config = config('services.openai', []);
        $apiKey = $config['key'] ?? null;
        
        if (!$apiKey) {
            $this->error('OpenAI API key not configured!');
            return 1;
        }
        
        // Show config (masked key)
        $maskedKey = $apiKey ? (substr($apiKey, 0, 8) . '...' . substr($apiKey, -4)) : 'NOT SET';
        $this->info("API Key: {$maskedKey}");
        $this->info("Model: " . ($config['model'] ?? 'gpt-4o-mini'));
        $this->info("Base URL: " . ($config['base_url'] ?? 'https://api.openai.com/v1'));
        $this->newLine();

        $processed = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query = Product::query()->whereNotNull('title')->orderBy('id');
        
        if (!$all) {
            $query->where('in_stock', true);
        }

        if (!$force) {
            $query->whereNotIn('id', function ($q) {
                $q->select('product_id')
                    ->from('product_ai_index')
                    ->whereNotNull('keywords');
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunk($batchSize, function ($products) use ($apiKey, $config, &$processed, &$failed, $bar) {
            foreach ($products as $product) {
                try {
                    $result = $this->analyzeProduct($product, $apiKey, $config);
                    if ($result) {
                        $processed++;
                        $this->line(" ✓ [{$product->id}] {$product->title}");
                    } else {
                        $failed++;
                        $this->line(" ✗ [{$product->id}] Failed to parse AI response");
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->line(" ✗ [{$product->id}] Error: " . $e->getMessage());
                }
                $bar->advance();
                
                // Rate limiting - 0.5s between requests
                usleep(500000);
            }
        });

        $bar->finish();
        $this->newLine(2);
        
        $this->info("=== Results ===");
        $this->info("Processed: {$processed}");
        $this->info("Failed: {$failed}");
        $this->info("Total analyzed now: " . ProductAiIndex::whereNotNull('keywords')->count());

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
            $response = Http::timeout(30)
                ->withToken($apiKey)
                ->post(rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions', [
                    'model' => $config['model'] ?? 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a military/tactical gear expert. Respond ONLY with valid JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 800,
                ]);

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
