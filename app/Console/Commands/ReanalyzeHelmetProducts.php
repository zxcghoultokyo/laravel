<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Re-analyze products that have incorrect ai_product_type.
 * 
 * Specifically targets helmet accessories that were incorrectly classified as "helmet".
 */
class ReanalyzeHelmetProducts extends Command
{
    protected $signature = 'products:reanalyze-helmets 
                            {--tenant= : Tenant ID to filter products}
                            {--dry-run : Show products without re-analyzing}
                            {--limit=100 : Maximum products to process}';

    protected $description = 'Re-analyze helmet-related products with incorrect ai_product_type';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $config = config('services.openai', []);
        $apiKey = $config['key'] ?? null;

        if (!$apiKey && !$dryRun) {
            $this->error('OpenAI API key not configured');
            return 1;
        }

        // Find products that need re-analysis
        // Category contains "Аксесуар" but ai_product_type is "helmet" (incorrect)
        $query = ProductAiIndex::with('product')
            ->whereHas('product', function ($q) use ($tenantId) {
                $q->withoutGlobalScope(TenantScope::class)
                    ->where('in_stock', true);
                
                if ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                }
                
                // Products in "Аксесуари на шоломи" category
                $q->where(function ($sq) {
                    $sq->where('category_path', 'like', '%Аксесуар%шолом%')
                       ->orWhere('category_path', 'like', '%Комплектуюч%шолом%');
                });
            })
            // Currently incorrectly classified as "helmet"
            ->where('product_type', 'helmet')
            ->limit($limit);

        $incorrectProducts = $query->get();

        $this->info("Found {$incorrectProducts->count()} products with incorrect helmet type");

        if ($incorrectProducts->isEmpty()) {
            $this->info('No products need re-analysis');
            return 0;
        }

        if ($dryRun) {
            $this->table(
                ['ID', 'Title', 'Category', 'Current Type'],
                $incorrectProducts->map(fn($ai) => [
                    $ai->product->id,
                    mb_substr($ai->product->title, 0, 50),
                    mb_substr($ai->product->category_path, 0, 40),
                    $ai->product_type,
                ])
            );
            return 0;
        }

        $bar = $this->output->createProgressBar($incorrectProducts->count());
        $bar->start();

        $success = 0;
        $errors = 0;

        foreach ($incorrectProducts as $aiIndex) {
            try {
                $this->reanalyzeProduct($aiIndex->product, $apiKey, $config);
                $success++;
                
                // Rate limiting
                usleep(500000); // 500ms
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->error("Error for product {$aiIndex->product->id}: {$e->getMessage()}");
                
                if (str_contains($e->getMessage(), 'rate_limit') || str_contains($e->getMessage(), '429')) {
                    sleep(5);
                }
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Re-analyzed: {$success} products");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        $this->info('Run `php artisan meili:index` to update Meilisearch with new types');

        return 0;
    }

    private function reanalyzeProduct(Product $product, string $apiKey, array $config): void
    {
        $raw = is_array($product->raw) ? $product->raw : json_decode($product->raw ?? '{}', true);
        
        $title = $product->title ?? '';
        $description = $this->extractDescription($raw);
        $category = $product->category_path ?? '';
        $characteristics = $this->extractCharacteristics($raw);

        $prompt = $this->buildPrompt($title, $description, $category, $characteristics);

        $model = 'gpt-4o-mini';
        
        $response = Http::timeout(30)
            ->withToken($apiKey)
            ->post(rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an e-commerce product expert. Classify products precisely. ACCESSORIES are NOT the main product! Respond ONLY with valid JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
                'max_tokens' => 800,
            ]);

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            throw new \RuntimeException('Empty AI response');
        }

        $json = $this->parseJsonFromResponse($content);
        if (!$json) {
            throw new \RuntimeException('Failed to parse JSON: ' . mb_substr($content, 0, 200));
        }

        // Validate the new type is actually an accessory type
        $newType = $json['product_type'] ?? null;
        if ($newType === 'helmet') {
            // AI still thinks it's a helmet - force correct based on category
            $newType = $this->determineAccessoryType($title, $category);
            $json['product_type'] = $newType;
            $json['ai_category'] = 'accessories';
            
            Log::warning('ReanalyzeHelmetProducts: forced accessory type', [
                'product_id' => $product->id,
                'title' => $title,
                'forced_type' => $newType,
            ]);
        }

        // Update ProductAiIndex
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

        Log::info('ReanalyzeHelmetProducts: updated product', [
            'product_id' => $product->id,
            'title' => $title,
            'old_type' => 'helmet',
            'new_type' => $json['product_type'],
        ]);
    }

    /**
     * Determine accessory type from title and category when AI fails.
     */
    private function determineAccessoryType(string $title, string $category): string
    {
        $titleLower = mb_strtolower($title);
        $catLower = mb_strtolower($category);

        // Check for specific accessory patterns
        if (str_contains($titleLower, 'подушк') || str_contains($titleLower, 'накладк') || str_contains($titleLower, 'pads')) {
            return 'helmet_pads';
        }
        
        if (str_contains($titleLower, 'кавер') || str_contains($titleLower, 'cover') || str_contains($titleLower, 'чохол')) {
            return 'helmet_cover';
        }
        
        if (str_contains($titleLower, 'планк') || str_contains($titleLower, 'picatinny') || 
            str_contains($titleLower, 'адаптер') || str_contains($titleLower, 'mount') ||
            str_contains($titleLower, 'wing-loc') || str_contains($titleLower, 'рейк')) {
            return 'helmet_mount';
        }
        
        if (str_contains($titleLower, 'ремін') || str_contains($titleLower, 'strap') || str_contains($titleLower, 'chin')) {
            return 'helmet_strap';
        }
        
        // Default accessory type
        return 'helmet_accessory';
    }

    private function buildPrompt(string $title, string $description, string $category, string $characteristics): string
    {
        return <<<PROMPT
Проаналізуй цей товар та згенеруй JSON для пошукового індексу.

УВАГА! Цей товар з категорії "{$category}" - це АКСЕСУАР для шолома, НЕ сам шолом!

ТОВАР:
Назва: {$title}
Категорія: {$category}
Опис: {$description}
Характеристики: {$characteristics}

Згенеруй JSON з полями:

1. "product_type": тип товару англійською. ВАЖЛИВО - це АКСЕСУАР, тому обери:
   - "helmet_cover" - кавери, чохли на шолом
   - "helmet_pads" - подушки, накладки всередину шолома
   - "helmet_mount" - кріплення, адаптери, планки Пікатінні, wing-loc НА шолом
   - "helmet_strap" - ремінці, підборіддя
   - "helmet_accessory" - інші аксесуари
   НІКОЛИ НЕ ВИКОРИСТОВУЙ "helmet" для аксесуарів!

2. "ai_category": "accessories" (бо це аксесуар)

3. "keywords": масив 10-15 ключових слів для пошуку

4. "slang": масив 3-5 сленгових назв

5. "materials": матеріали якщо є

Відповідай ТІЛЬКИ валідним JSON без markdown.
PROMPT;
    }

    private function extractDescription(array $raw): string
    {
        $desc = $raw['description'] ?? $raw['description_full'] ?? '';
        if (is_array($desc)) {
            $desc = $desc['uk'] ?? $desc['ua'] ?? reset($desc) ?: '';
        }
        return mb_substr(strip_tags((string)$desc), 0, 500);
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
                if (isset($value['value'])) {
                    $value = is_array($value['value']) 
                        ? ($value['value']['ua'] ?? $value['value']['uk'] ?? reset($value['value']) ?: '')
                        : $value['value'];
                } else {
                    $value = implode(', ', array_filter($value, 'is_string'));
                }
            }
            if (is_string($value) && $value !== '') {
                $lines[] = "{$key}: {$value}";
            }
        }
        return implode('; ', array_slice($lines, 0, 10));
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
