<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

/**
 * AI-powered category alias generation command.
 * 
 * Generates natural language phrases people might use when searching
 * for products in each category (e.g., "плитоноска" → "бронежилет", "броник", "plate carrier").
 */
class GenerateCategoryAliasesCommand extends Command
{
    protected $signature = 'category:aliases 
                            {--force : Overwrite existing AI-generated aliases}
                            {--dry-run : Show what would be generated without saving}
                            {--batch=10 : Number of categories per AI batch}';

    protected $description = 'Generate category aliases using AI (natural phrases people use when searching)';

    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function handle(): int
    {
        $this->apiKey  = config('services.openai.key', '');
        $this->model   = config('services.openai.model', 'gpt-4.1-mini');
        $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');

        if (empty($this->apiKey)) {
            $this->error('❌ OPENAI_API_KEY not configured');
            return 1;
        }

        $force  = $this->option('force');
        $dryRun = $this->option('dry-run');
        $batch  = (int) $this->option('batch');

        // Отримуємо категорії з найбільшою кількістю товарів
        $categories = Category::query()
            ->where('is_active', true)
            ->where('products_count', '>', 0)
            ->orderByDesc('products_count')
            ->get();

        if ($categories->isEmpty()) {
            $this->warn('⚠️ No active categories found. Run php artisan category:rebuild first.');
            return 0;
        }

        $this->info("📂 Found {$categories->count()} active categories");

        // Групуємо категорії для batch-запитів до AI
        $chunks = $categories->chunk($batch);
        $totalGenerated = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            $categoryData = $chunk->map(fn($c) => [
                'id'   => $c->id,
                'path' => $c->path,
                'slug' => $c->slug,
            ])->values()->toArray();

            $this->info("🤖 Processing batch " . ($chunkIndex + 1) . "/" . $chunks->count() . " ({$chunk->count()} categories)...");

            $aliases = $this->generateAliasesViaAI($categoryData);

            if (empty($aliases)) {
                $this->warn("   ⚠️ No aliases generated for this batch");
                continue;
            }

            foreach ($aliases as $catId => $phrases) {
                $category = $categories->firstWhere('id', $catId);
                if (!$category) continue;

                foreach ($phrases as $phrase) {
                    $phrase = trim($phrase);
                    if (mb_strlen($phrase) < 2) continue;

                    $norm = $this->normalize($phrase);
                    if (mb_strlen($norm) < 2) continue;

                    // Перевіряємо чи вже є такий alias
                    $exists = CategoryAlias::query()
                        ->where('category_id', $catId)
                        ->where('phrase_norm', $norm)
                        ->exists();

                    if ($exists && !$force) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("   [DRY] {$category->path}: \"{$phrase}\"");
                        $totalGenerated++;
                        continue;
                    }

                    CategoryAlias::query()->updateOrCreate(
                        ['category_id' => $catId, 'phrase_norm' => $norm],
                        [
                            'phrase'    => $phrase,
                            'weight'    => 40, // AI-generated вагою між full_path (50) і segment (30)
                            'source'    => 'ai_generated',
                            'is_active' => true,
                        ]
                    );

                    $totalGenerated++;
                    $this->line("   ✅ {$category->path}: \"{$phrase}\"");
                }
            }
        }

        $action = $dryRun ? 'would be generated' : 'generated';
        $this->newLine();
        $this->info("✅ Done! {$totalGenerated} aliases {$action}.");

        return 0;
    }

    protected function generateAliasesViaAI(array $categories): array
    {
        $categoryList = collect($categories)
            ->map(fn($c) => "- ID:{$c['id']} = \"{$c['path']}\"")
            ->implode("\n");

        $prompt = <<<PROMPT
Ти — експерт з e-commerce та пошуку товарів для українського тактичного магазину.

Для кожної категорії згенеруй 5-10 альтернативних фраз/слів, які покупці можуть використовувати при пошуку товарів цієї категорії.

Включай:
- Синоніми (український, російський варіанти)
- Сленг та розмовні форми ("броник" замість "бронежилет")
- Англійські терміни ("plate carrier", "belt", "pouch")
- Скорочення та абревіатури
- Типові помилки написання
- Загальні запити ("жилет тактичний", "розвантаження")

Категорії:
{$categoryList}

Відповідай ТІЛЬКИ у JSON форматі:
{
  "ID_категорії": ["фраза1", "фраза2", ...],
  ...
}

Приклад для "Тактичне спорядження / Плитоноски":
{
  "123": ["плитоноска", "плитоносці", "plate carrier", "бронежилет", "броник", "жилет тактичний", "розвантаження", "нагрудник", "плейт керріер", "бронік", "жилетка бронезахисна"]
}

ВАЖЛИВО: 
- НЕ повторюй точну назву категорії
- Фрази мають бути те, що люди ШУКАЮТЬ, а не офіційні назви
- Включай варіанти з помилками якщо вони поширені
PROMPT;

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type'  => 'application/json',
                ])
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'messages'    => [
                        ['role' => 'system', 'content' => 'You are a helpful assistant that generates search aliases for product categories. Always respond with valid JSON only.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.7,
                    'max_tokens'  => 4000,
                ]);

            if (!$response->successful()) {
                $this->error("   ❌ API error: " . $response->status());
                return [];
            }

            $content = $response->json('choices.0.message.content', '');
            
            // Витягуємо JSON з відповіді
            $content = $this->extractJson($content);
            
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("   ❌ JSON parse error: " . json_last_error_msg());
                $this->line("   Raw: " . mb_substr($content, 0, 200));
                return [];
            }

            // Конвертуємо ключі в int
            $result = [];
            foreach ($data as $key => $phrases) {
                $id = (int) $key;
                if ($id > 0 && is_array($phrases)) {
                    $result[$id] = $phrases;
                }
            }

            return $result;

        } catch (\Exception $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
            return [];
        }
    }

    protected function extractJson(string $content): string
    {
        // Видаляємо markdown code blocks
        $content = preg_replace('/```json\s*/i', '', $content);
        $content = preg_replace('/```\s*$/i', '', $content);
        $content = preg_replace('/```/', '', $content);
        
        // Шукаємо JSON об'єкт
        if (preg_match('/\{[\s\S]*\}/u', $content, $matches)) {
            return $matches[0];
        }

        return trim($content);
    }

    protected function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $s) ?: '';
        $s = preg_replace('/\s+/u', ' ', $s) ?: '';
        return trim($s);
    }
}
