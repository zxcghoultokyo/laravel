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
            // === ЗАХИСТ / БРОНЯ ===
            'плитоноски' => ['плитоноска', 'бронік', 'plate carrier', 'pc', 'бронежилет', 'жилет', 'носій плит', 'броник', 'плитник', 'carrier', 'vest', 'тактичний жилет', 'бронежилетка'],
            'бронежилети' => ['бронежилет', 'броник', 'жилет', 'vest', 'body armor', 'броня', 'захист', 'бж'],
            'бронеплити' => ['бронеплита', 'плита', 'plate', 'sapi', 'esapi', 'броня', 'керамічна плита', 'бронепластина', 'armor plate', 'ceramic', 'керамика', 'пластина', 'плити'],
            'шоломи' => ['шолом', 'каска', 'helmet', 'балістичний шолом', 'кевлар', 'шлем', 'helm', 'fast', 'mich', 'ach', 'pasgt', 'bump', 'балістика', 'тактичний шолом'],
            'шоломи та комплектуючі' => ['шолом', 'каска', 'helmet', 'кріплення', 'підвіс', 'рейки', 'cover', 'чохол', 'nvg mount', 'shroud'],
            
            // === МЕДИЦИНА ===
            'турнікети' => ['турнікет', 'cat', 'tq', 'джгут', 'кровоспин', 'турник', 'жгут', 'tourniquet', 'кровоспинний', 'сат', 'softt', 'combat tourniquet'],
            'аптечки' => ['аптечка', 'ifak', 'медичний підсумок', 'мед кіт', 'first aid', 'медкіт', 'мед', 'медичка', 'аптека', 'fak', 'med kit', 'медична сумка'],
            'бандажі та перевязувальний матеріал' => ['бандаж', 'бинт', 'перевязка', 'ізраїльський бандаж', 'israeli bandage', 'olaes', 'bandage', 'гемостатик', 'celox', 'quickclot', 'марля'],
            
            // === ПІДСУМКИ ===
            'підсумки' => ['підсумок', 'pouch', 'mag pouch', 'кишеня', 'сумка', 'подсумок', 'магазинний', 'tactical pouch'],
            'утилітарні підсумки' => ['утилітарний', 'utility', 'admin pouch', 'gp pouch', 'загального призначення', 'органайзер'],
            'підсумки для магазинів' => ['підсумок для магазинів', 'mag pouch', 'magazine pouch', 'подсумок для магазинов', 'маг пауч', 'fast mag', 'taco'],
            
            // === РЮКЗАКИ / СУМКИ ===
            'рюкзаки' => ['рюкзак', 'backpack', 'ранець', 'pack', 'сумка', 'assault pack', 'штурмовий рюкзак', 'тактичний рюкзак', 'рюкзачок', 'баул'],
            'сумки' => ['сумка', 'bag', 'duffle', 'range bag', 'стрілецька сумка', 'транспортна сумка', 'баул', 'сумочка'],
            
            // === РЕМЕНI / РПС ===
            'ремінно плечові та поясні системи (рпс)' => ['рпс', 'ременно плечова', 'chest rig', 'battle belt', 'war belt', 'пояс', 'розвантаження', 'розвантажка', 'лбе', 'лбз', 'harness', 'rig'],
            '2-точкові' => ['ремінь', '2 точковий', 'двоточковий', '2-point sling', 'sling', 'слінг', 'ремень', 'оружейный ремень', 'тактичний ремінь'],
            
            // === ВЗУТТЯ ===
            'берці' => ['берці', 'берцы', 'boots', 'черевики', 'ботинки', 'тактичні черевики', 'tactical boots', 'військові черевики', 'армійські берці', 'combat boots', 'бєрци'],
            'взуття' => ['взуття', 'обувь', 'boots', 'черевики', 'кросівки', 'кроссовки', 'sneakers', 'туфлі', 'сандалі'],
            
            // === ОДЯГ ===
            'куртки' => ['куртка', 'jacket', 'softshell', 'софтшел', 'софт шел', 'хардшел', 'hardshell', 'вітровка', 'куртка тактична', 'парка', 'parka', 'флісова куртка'],
            'штани' => ['штани', 'брюки', 'pants', 'тактичні штани', 'бойові штани', 'combat pants', 'trousers', 'карго', 'cargo', 'tactical pants'],
            'тактичні штани' => ['штани', 'брюки', 'pants', 'тактичні штани', 'бойові штани', 'combat pants', 'trousers', 'карго'],
            'футболки' => ['футболка', 'tshirt', 't-shirt', 'майка', 'тактична футболка', 'поло', 'polo', 'combat shirt'],
            'бойові сорочки (ubacs)' => ['ubacs', 'юбакс', 'бойова сорочка', 'combat shirt', 'сорочка', 'рубашка', 'тактична сорочка'],
            'шорти' => ['шорти', 'shorts', 'шорты', 'тактичні шорти', 'tactical shorts'],
            
            // === ТЕРМОБІЛИЗНА / LAYERS ===
            'level 1 (легка термобілизна)' => ['level 1', 'левел 1', 'лвл 1', 'l1', 'термобілизна', 'термобелье', 'base layer', 'нижня білизна', 'термуха'],
            'level 2 (зігрівальна термобілизна)' => ['level 2', 'левел 2', 'лвл 2', 'l2', 'термобілизна', 'зігрівальна', 'waffle', 'grid fleece'],
            'level 3 (флісові кофти)' => ['level 3', 'левел 3', 'лвл 3', 'l3', 'флісова кофта', 'фліс', 'fleece', 'флиска', 'кофта', 'полартек', 'polartec'],
            'level 6 (gore-tex та інший вологостійкий одяг)' => ['level 6', 'левел 6', 'лвл 6', 'l6', 'gore-tex', 'goretex', 'гортекс', 'дощовик', 'rain jacket', 'waterproof', 'мембрана'],
            'level 7 (зимовий реверсивний одяг)' => ['level 7', 'левел 7', 'лвл 7', 'l7', 'ecwcs', 'зимовий', 'primaloft', 'утеплений', 'пуховик', 'зимова куртка', 'gen iii', 'gen 3'],
            'тактичні кофти' => ['кофта', 'тактична кофта', 'combat shirt', 'худі', 'hoodie', 'світшот', 'sweatshirt'],
            
            // === ГОЛОВНІ УБОРИ ===
            'кепки, панами та бандани' => ['кепка', 'cap', 'бейсболка', 'панама', 'boonie', 'буні', 'бандана', 'bandana', 'головний убір'],
            'шапки, шарфи, бафи та балаклави' => ['шапка', 'beanie', 'баф', 'buff', 'балаклава', 'balaclava', 'шарф', 'scarf', 'watch cap', 'тактична шапка'],
            
            // === РУКАВИЦІ ===
            'рукавиці' => ['рукавиці', 'рукавички', 'gloves', 'перчатки', 'тактичні рукавиці', 'mechanix', 'меканікс', 'рукавици', 'tactical gloves'],
            
            // === ОПТИКА / АКСЕСУАРИ ===
            'окуляри' => ['окуляри', 'очки', 'glasses', 'балістичні окуляри', 'goggles', 'ess', 'oakley', 'захисні окуляри', 'eyewear'],
            'навушники' => ['навушники', 'наушники', 'earmor', 'peltor', 'активні навушники', 'тактичні навушники', 'ear protection', 'comtac', 'sordin', 'hearing protection'],
            'активні навушники та інші засоби захисту слуху' => ['навушники', 'earmor', 'peltor', 'comtac', 'sordin', 'активні', 'захист слуху', 'ear pro'],
            'ліхтарі' => ['ліхтар', 'ліхтарик', 'flashlight', 'фонарь', 'фонарик', 'torch', 'тактичний ліхтар', 'surefire', 'streamlight', 'olight', 'weapon light'],
            
            // === НОЖІ / ІНСТРУМЕНТИ ===
            'ножі' => ['ніж', 'нож', 'knife', 'клинок', 'тактичний ніж', 'складний ніж', 'fixed blade', 'мультитул', 'multitool', 'benchmade', 'gerber', 'cold steel'],
            
            // === ЗБРОЯ / АКСЕСУАРИ ===
            'штурмові гвинтівки' => ['гвинтівка', 'ar-15', 'ar15', 'm4', 'автомат', 'штурмова', 'assault rifle', 'rifle', 'карабін', 'калаш', 'ак', 'ak'],
            'основні' => ['пістолет', 'pistol', 'handgun', 'glock', 'глок', 'sig', 'beretta'],
            
            // === ШЕВРОНИ ===
            'шеврони, патчі та інші знаки розрізнення' => ['шеврон', 'патч', 'patch', 'нашивка', 'morale patch', 'прапор', 'flag', 'velcro patch', 'pvc patch', 'знак'],
            
            // === ТУРИЗМ ===
            'інше туристичне обладнання' => ['туристичне', 'кемпінг', 'camping', 'намет', 'tent', 'спальник', 'sleeping bag', 'каримат', 'mat', 'термос', 'пальник'],
            
            // === УНІФОРМА ===
            'уніформа, одяг і взуття' => ['уніформа', 'форма', 'uniform', 'камуфляж', 'camo', 'bdu', 'acu', 'multicam', 'мультикам', 'піксель', 'pixel'],
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
