<?php

namespace App\Console\Commands;

use App\Models\ProductSynonym;
use App\Models\Product;
use App\Services\Ai\AiRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateProductSynonymsCommand extends Command
{
    protected $signature = 'synonyms:products 
                            {--force : Regenerate all, even existing}
                            {--dry-run : Show what would be generated without saving}';

    protected $description = 'Generate product type synonyms from category paths using AI';

    public function __construct(private AiRouter $aiRouter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🏷️ Generating product type synonyms...');

        // Extract product types from category paths
        $categories = Product::query()
            ->whereNotNull('category_path')
            ->where('category_path', '!=', '')
            ->select('category_path', DB::raw('COUNT(*) as cnt'))
            ->groupBy('category_path')
            ->orderByDesc('cnt')
            ->limit(100)
            ->pluck('cnt', 'category_path')
            ->toArray();

        if (empty($categories)) {
            $this->error('No categories found in products table!');
            return 1;
        }

        $this->info("Found " . count($categories) . " unique category paths");

        // Extract leaf categories (last segment)
        $productTypes = [];
        foreach ($categories as $path => $count) {
            $segments = explode('/', $path);
            $leaf = end($segments);
            $leaf = trim($leaf);
            if (!empty($leaf)) {
                $productTypes[$leaf] = ($productTypes[$leaf] ?? 0) + $count;
            }
        }
        arsort($productTypes);

        $this->info("Extracted " . count($productTypes) . " product types");
        $this->table(['Product Type', 'Count'], collect($productTypes)->take(30)->map(fn($cnt, $type) => [$type, $cnt])->values()->toArray());

        // Generate synonyms using AI
        $synonymsMap = $this->generateSynonymsWithAI(array_keys($productTypes));

        if (empty($synonymsMap)) {
            $this->error('AI failed to generate synonyms!');
            return 1;
        }

        $this->info("\n📝 Generated synonym groups:");
        foreach ($synonymsMap as $type => $synonyms) {
            $this->line("  <fg=cyan>{$type}</> → " . implode(', ', array_slice($synonyms, 0, 5)) . (count($synonyms) > 5 ? '...' : ''));
        }

        if ($this->option('dry-run')) {
            $this->warn("\n--dry-run mode: No changes saved.");
            return 0;
        }

        // Save to database
        $this->saveSynonyms($synonymsMap, $this->option('force'));

        $this->info("\n✅ Product synonyms generated successfully!");

        return 0;
    }

    private function generateSynonymsWithAI(array $productTypes): array
    {
        $typesList = implode("\n", array_slice($productTypes, 0, 50));

        $prompt = <<<PROMPT
Ти — експерт з тактичного/військового спорядження для українського магазину Contractor.

Ось список типів товарів з категорій:
{$typesList}

Твоє завдання:
1. Для кожного типу товару створи список СИНОНІМІВ (укр, рус, англ, сленг, скорочення)
2. Синоніми = те, що користувач може написати в пошуку

ВАЖЛИВО:
- Включай сленг військових/тактичних спільнот
- Включай англійські терміни
- Включай скорочення (БК, РПС, ІПП)
- Включай помилкові написання якщо вони поширені

Приклади:
- "Плитоноски" → плитоноска, бронік, plate carrier, pc, бронежилет, жилет, носій плит
- "Турнікети" → турнікет, cat, тк, джгут, tq, турник, кровоспин
- "Підсумки" → підсумок, pouch, сумка, кишеня, mag pouch
- "Шоломи" → шолом, каска, helmet, балістичний шолом, кевларовий шолом
- "Бронеплити" → бронеплита, плита, plate, sapi, esapi, броня, керамічна плита

Поверни JSON (тільки для типів де є сенс додавати синоніми):
{
  "плитоноски": ["плитоноска", "бронік", "plate carrier", "pc", "жилет", "носій плит"],
  "турнікети": ["турнікет", "cat", "tq", "джгут", "кровоспин"],
  ...
}

Поверни ТІЛЬКИ JSON без пояснень. Максимум 30 типів.
PROMPT;

        try {
            $this->info("\n🤖 Calling AI for synonym generation...");
            $response = $this->aiRouter->callOpenAI($prompt, 0.3);
            
            // Clean response
            $response = preg_replace('/```json\s*/', '', $response);
            $response = preg_replace('/```\s*$/', '', $response);
            $response = trim($response);
            
            $result = json_decode($response, true);
            
            if (!is_array($result)) {
                $this->error("Invalid JSON response: " . substr($response, 0, 500));
                return $this->getFallbackSynonyms();
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->error("AI error: " . $e->getMessage());
            Log::error('GenerateProductSynonyms: AI failed', ['error' => $e->getMessage()]);
            return $this->getFallbackSynonyms();
        }
    }

    private function getFallbackSynonyms(): array
    {
        return [
            'плитоноски' => ['плитоноска', 'бронік', 'plate carrier', 'pc', 'бронежилет', 'жилет', 'носій плит', 'броник'],
            'турнікети' => ['турнікет', 'cat', 'tq', 'джгут', 'кровоспин', 'турник', 'жгут'],
            'шоломи' => ['шолом', 'каска', 'helmet', 'балістичний шолом', 'кевлар', 'шлем'],
            'бронеплити' => ['бронеплита', 'плита', 'plate', 'sapi', 'esapi', 'броня', 'керамічна плита', 'бронепластина'],
            'підсумки' => ['підсумок', 'pouch', 'mag pouch', 'кишеня', 'сумка', 'подсумок'],
            'рюкзаки' => ['рюкзак', 'backpack', 'ранець', 'pack', 'сумка'],
            'берці' => ['берці', 'берцы', 'boots', 'черевики', 'ботинки', 'тактичні черевики'],
            'рукавиці' => ['рукавиці', 'рукавички', 'gloves', 'перчатки', 'тактичні рукавиці'],
            'окуляри' => ['окуляри', 'очки', 'glasses', 'балістичні окуляри', 'goggles'],
            'навушники' => ['навушники', 'наушники', 'earmor', 'peltor', 'активні навушники', 'тактичні навушники'],
            'ліхтарі' => ['ліхтар', 'ліхтарик', 'flashlight', 'фонарь', 'фонарик', 'torch'],
            'ножі' => ['ніж', 'нож', 'knife', 'клинок', 'тактичний ніж'],
            'аптечки' => ['аптечка', 'ifak', 'медичний підсумок', 'мед кіт', 'first aid'],
            'футболки' => ['футболка', 'tshirt', 't-shirt', 'майка', 'тактична футболка'],
            'штани' => ['штани', 'брюки', 'pants', 'тактичні штани', 'бойові штани'],
        ];
    }

    private function saveSynonyms(array $synonymsMap, bool $force): void
    {
        if ($force) {
            $this->warn("Deleting existing synonyms...");
            ProductSynonym::truncate();
        }

        $inserted = 0;
        $skipped = 0;

        foreach ($synonymsMap as $productType => $synonyms) {
            $productType = mb_strtolower(trim($productType));
            
            foreach ($synonyms as $synonym) {
                $synonym = mb_strtolower(trim($synonym));
                if (empty($synonym)) continue;

                // Check if exists
                $exists = ProductSynonym::where('product_type', $productType)
                    ->where('synonym', $synonym)
                    ->exists();

                if ($exists && !$force) {
                    $skipped++;
                    continue;
                }

                ProductSynonym::updateOrCreate(
                    ['product_type' => $productType, 'synonym' => $synonym],
                    [
                        'language' => $this->detectLanguage($synonym),
                        'weight' => 1,
                        'domain' => null,
                        'is_active' => true,
                    ]
                );
                $inserted++;
            }
        }

        $this->info("Inserted: {$inserted}, Skipped: {$skipped}");
    }

    private function detectLanguage(string $text): string
    {
        if (preg_match('/[а-яіїєґ]/ui', $text)) {
            return preg_match('/[іїєґ]/ui', $text) ? 'uk' : 'ru';
        }
        return 'en';
    }
}
