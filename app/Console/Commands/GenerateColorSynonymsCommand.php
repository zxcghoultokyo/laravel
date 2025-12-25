<?php

namespace App\Console\Commands;

use App\Models\ColorSynonym;
use App\Models\Product;
use App\Services\Ai\AiRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateColorSynonymsCommand extends Command
{
    protected $signature = 'synonyms:colors 
                            {--force : Regenerate all, even existing}
                            {--dry-run : Show what would be generated without saving}';

    protected $description = 'Generate color synonyms from existing product colors using AI';

    public function __construct(private AiRouter $aiRouter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🎨 Generating color synonyms...');

        // Get unique colors from products
        $colors = Product::query()
            ->whereNotNull('color')
            ->where('color', '!=', '')
            ->select('color', DB::raw('COUNT(*) as cnt'))
            ->groupBy('color')
            ->orderByDesc('cnt')
            ->pluck('cnt', 'color')
            ->toArray();

        if (empty($colors)) {
            $this->error('No colors found in products table!');
            return 1;
        }

        $this->info("Found " . count($colors) . " unique colors in products");
        
        // Show top colors
        $this->table(['Color', 'Count'], collect($colors)->take(20)->map(fn($cnt, $color) => [$color, $cnt])->values()->toArray());

        // Get existing color groups
        $existingGroups = ColorSynonym::query()
            ->distinct()
            ->pluck('color_group')
            ->toArray();

        if (!empty($existingGroups) && !$this->option('force')) {
            $this->warn("Found " . count($existingGroups) . " existing color groups. Use --force to regenerate.");
        }

        // Generate synonyms using AI
        $colorsList = array_keys($colors);
        $synonymsMap = $this->generateSynonymsWithAI($colorsList);

        if (empty($synonymsMap)) {
            $this->error('AI failed to generate synonyms!');
            return 1;
        }

        $this->info("\n📝 Generated synonym groups:");
        foreach ($synonymsMap as $group => $synonyms) {
            $this->line("  <fg=green>{$group}</> → " . implode(', ', $synonyms));
        }

        if ($this->option('dry-run')) {
            $this->warn("\n--dry-run mode: No changes saved.");
            return 0;
        }

        // Save to database
        $this->saveSynonyms($synonymsMap, $this->option('force'));

        $this->info("\n✅ Color synonyms generated successfully!");
        
        // Clear cache
        app(\App\Services\Search\ColorService::class)->clearCache();
        $this->info("🔄 Cache cleared.");

        return 0;
    }

    private function generateSynonymsWithAI(array $colors): array
    {
        $colorsList = implode("\n", array_map(fn($c, $i) => ($i + 1) . ". {$c}", $colors, array_keys($colors)));

        $prompt = <<<PROMPT
Ти — експерт з кольорів тактичного/військового спорядження для українського магазину.

Ось список унікальних кольорів з бази товарів:
{$colorsList}

Твоє завдання:
1. Згрупуй ці кольори в КАНОНІЧНІ групи (англійською, lowercase)
2. Для кожної групи додай ВСІ можливі синоніми (укр, рус, англ, сленг, скорочення)

ВАЖЛИВО:
- Канонічна група = англійське слово lowercase (black, olive, multicam, pixel, tan, coyote, green, khaki, brown, white, red, blue, camo)
- Синоніми включають: оригінальну назву з бази, переклади, сленг, скорочення
- "мультікам" і "Мультикам" = одна група "multicam"
- "Оливковий" і "Олива" = одна група "olive"
- "Піксель", "MM14", "укрпіксель" = одна група "pixel"
- "Хаки", "Хакі" = або "khaki" або "olive" (залежить від контексту)
- "Койот", "Coyote", "tan", "FDE" = група "coyote" або "tan"

Поверни JSON:
{
  "black": ["чорний", "чорна", "чорне", "черный", "black", "blk"],
  "multicam": ["мультикам", "мультікам", "multicam", "mc", "мульт"],
  "olive": ["олива", "оливковий", "оліва", "olive", "od", "ranger green"],
  "pixel": ["піксель", "пиксель", "mm14", "мм14", "укрпіксель", "pixel"],
  ...
}

Поверни ТІЛЬКИ JSON без пояснень.
PROMPT;

        try {
            $this->info("\n🤖 Calling AI for synonym generation...");
            $response = $this->aiRouter->callOpenAI($prompt, 0.3);
            
            // Clean response (remove markdown if present)
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
            Log::error('GenerateColorSynonyms: AI failed', ['error' => $e->getMessage()]);
            return $this->getFallbackSynonyms();
        }
    }

    private function getFallbackSynonyms(): array
    {
        return [
            'black' => ['чорний', 'чорна', 'чорне', 'черный', 'black', 'blk'],
            'multicam' => ['мультикам', 'мультікам', 'multicam', 'mc', 'мульт', 'Multicam'],
            'olive' => ['олива', 'оливковий', 'оліва', 'olive', 'od', 'Оливковий', 'Олива'],
            'pixel' => ['піксель', 'пиксель', 'mm14', 'мм14', 'укрпіксель', 'pixel', 'Піксель', 'ММ14'],
            'coyote' => ['койот', 'coyote', 'Койот'],
            'tan' => ['тан', 'tan', 'fde', 'пісочний'],
            'khaki' => ['хакі', 'хаки', 'khaki', 'Хаки', 'Хакі'],
            'green' => ['зелений', 'зелена', 'green', 'Зелений'],
            'brown' => ['коричневий', 'коричнева', 'brown', 'Коричневий'],
            'camo' => ['камуфляж', 'камо', 'camo', 'Камуфляж'],
            'white' => ['білий', 'біла', 'white', 'Білий'],
            'red' => ['червоний', 'червона', 'red', 'Червоний'],
            'blue' => ['синій', 'синя', 'blue', 'Синій'],
            'alpine_mc' => ['alpine mc', 'Alpine MC', 'альпійський мультикам'],
            'black_multicam' => ['black multicam', 'Black Multicam', 'чорний мультикам', 'мультикам чорний', 'мультікам чорний'],
            'tropical_mc' => ['тропічний мультикам', 'Тропічний мультикам', 'tropical multicam'],
        ];
    }

    private function saveSynonyms(array $synonymsMap, bool $force): void
    {
        if ($force) {
            $this->warn("Deleting existing synonyms...");
            ColorSynonym::truncate();
        }

        $inserted = 0;
        $skipped = 0;

        foreach ($synonymsMap as $colorGroup => $synonyms) {
            $colorGroup = strtolower(trim($colorGroup));
            
            foreach ($synonyms as $index => $synonym) {
                $synonym = trim($synonym);
                if (empty($synonym)) continue;

                // Check if exists
                $exists = ColorSynonym::where('color_group', $colorGroup)
                    ->where('synonym', $synonym)
                    ->exists();

                if ($exists && !$force) {
                    $skipped++;
                    continue;
                }

                ColorSynonym::updateOrCreate(
                    ['color_group' => $colorGroup, 'synonym' => $synonym],
                    [
                        'language' => $this->detectLanguage($synonym),
                        'is_primary' => $index === 0,
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
        // Simple detection based on character set
        if (preg_match('/[а-яіїєґ]/ui', $text)) {
            return preg_match('/[іїєґ]/ui', $text) ? 'uk' : 'ru';
        }
        return 'en';
    }
}
