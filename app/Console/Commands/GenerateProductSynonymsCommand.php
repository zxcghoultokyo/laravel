<?php

namespace App\Console\Commands;

use App\Models\ProductSynonym;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Ai\AiRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateProductSynonymsCommand extends Command
{
    protected $signature = 'synonyms:products 
                            {--tenant= : Tenant ID to generate synonyms for (null = global)}
                            {--force : Regenerate all, even existing}
                            {--dry-run : Show what would be generated without saving}';

    protected $description = 'Generate product type synonyms from category paths using AI';

    public function __construct(private AiRouter $aiRouter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        
        // Start sync log
        $syncLog = SyncLog::start(SyncLog::TYPE_PRODUCT_SYNONYMS, $tenantId ? "Tenant {$tenantId}" : "Global");
        if ($tenantId) {
            $syncLog->tenant_id = $tenantId;
            $syncLog->save();
        }
        
        $this->info('🏷️ Generating product type synonyms...');
        if ($tenantId) {
            $this->info("For tenant ID: {$tenantId}");
        } else {
            $this->info("Global synonyms (no tenant)");
        }

        try {
            // Extract product types from category paths
            $query = Product::query()
                ->whereNotNull('category_path')
                ->where('category_path', '!=', '');
                
            // Filter by tenant if specified
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            
            $categories = $query
                ->select('category_path', DB::raw('COUNT(*) as cnt'))
                ->groupBy('category_path')
                ->orderByDesc('cnt')
                ->pluck('cnt', 'category_path')
                ->toArray();

            if (empty($categories)) {
                $this->error('No categories found in products table!');
                $syncLog->fail('No categories found');
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
                $syncLog->fail('AI failed to generate synonyms');
                return 1;
            }

            $this->info("\n📝 Generated synonym groups:");
            foreach ($synonymsMap as $type => $synonyms) {
                $this->line("  <fg=cyan>{$type}</> → " . implode(', ', array_slice($synonyms, 0, 5)) . (count($synonyms) > 5 ? '...' : ''));
            }

            if ($this->option('dry-run')) {
                $this->warn("\n--dry-run mode: No changes saved.");
                $syncLog->complete(['dry_run' => true, 'types_count' => count($synonymsMap)]);
                return 0;
            }

            // Save to database
            $stats = $this->saveSynonyms($synonymsMap, $this->option('force'), $tenantId);

            $this->info("\n✅ Product synonyms generated successfully!");
            
            $syncLog->complete([
                'categories_count' => count($categories),
                'types_count' => count($productTypes),
                'synonym_groups' => count($synonymsMap),
                'inserted' => $stats['inserted'],
                'skipped' => $stats['skipped'],
            ]);

            return 0;
            
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
            $syncLog->fail($e->getMessage());
            throw $e;
        }
    }

    private function generateSynonymsWithAI(array $productTypes): array
    {
        // Спочатку спробуємо fallback словник (швидше і надійніше)
        $fallbackSynonyms = $this->getFallbackSynonyms();
        $result = [];
        $aiNeededTypes = [];
        
        // Перевіряємо які типи є в fallback словнику
        foreach ($productTypes as $type) {
            $normalizedType = mb_strtolower(trim($type));
            $matched = false;
            
            foreach ($fallbackSynonyms as $fallbackType => $synonyms) {
                // Порівнюємо нормалізовано
                if ($this->typesMatch($normalizedType, $fallbackType)) {
                    $result[$type] = $synonyms;
                    $matched = true;
                    break;
                }
            }
            
            if (!$matched) {
                $aiNeededTypes[] = $type;
            }
        }
        
        $this->info("\n📚 Fallback dictionary matched: " . count($result) . " types");
        $this->info("🤖 Types needing AI: " . count($aiNeededTypes));
        
        // Якщо є типи без fallback, спробуємо AI
        if (!empty($aiNeededTypes) && count($aiNeededTypes) <= 100) {
            $aiResult = $this->callAiForSynonyms($aiNeededTypes);
            $result = array_merge($result, $aiResult);
        } elseif (count($aiNeededTypes) > 100) {
            $this->warn("Too many types for AI ({$aiNeededTypes}), using fallback only");
        }
        
        return $result;
    }
    
    /**
     * Check if two product types match (fuzzy matching)
     */
    private function typesMatch(string $type1, string $type2): bool
    {
        $type1 = mb_strtolower(trim($type1));
        $type2 = mb_strtolower(trim($type2));
        
        // Exact match
        if ($type1 === $type2) return true;
        
        // Starts with match ("бронежилети та плитоноски" matches "бронежилети")
        if (str_starts_with($type1, $type2) || str_starts_with($type2, $type1)) return true;
        
        // Contains match for short keys
        if (strlen($type2) >= 4 && str_contains($type1, $type2)) return true;
        
        return false;
    }
    
    /**
     * Call AI for synonym generation
     */
    private function callAiForSynonyms(array $productTypes): array
    {
        $typesList = implode("\n", array_slice($productTypes, 0, 80));

        $prompt = <<<PROMPT
Ти — експерт з e-commerce та пошукової оптимізації для українського інтернет-магазину.

Ось список типів товарів з категорій:
{$typesList}

Твоє завдання:
1. Для кожного типу товару створи список СИНОНІМІВ (укр, рус, англ, сленг, скорочення)
2. Синоніми = те, що користувач може написати в пошуку
3. Для кожного типу мінімум 8-15 синонімів!

ВАЖЛИВО:
- Включай український сленг та жаргон
- Включай російські варіанти (багато користувачів шукають російською)
- Включай англійські терміни
- Включай скорочення та абревіатури
- Включай поширені помилкові написання
- Включай бренди-приклади якщо релевантно

Поверни JSON для ВСІХ типів:
{
  "тип1": ["синонім1", "синонім2", ...],
  ...
}

Поверни ТІЛЬКИ JSON без пояснень.
PROMPT;

        try {
            $this->info("\n🤖 Calling AI for " . count($productTypes) . " types...");
            $response = $this->aiRouter->callOpenAI($prompt, 0.3);
            
            // Clean response
            $response = preg_replace('/```json\s*/', '', $response);
            $response = preg_replace('/```\s*$/', '', $response);
            $response = trim($response);
            
            $result = json_decode($response, true);
            
            if (!is_array($result)) {
                $this->error("Invalid JSON response: " . substr($response, 0, 500));
                return [];
            }
            
            $this->info("✅ AI returned " . count($result) . " synonym groups");
            return $result;
            
        } catch (\Exception $e) {
            $this->error("AI error: " . $e->getMessage());
            Log::error('GenerateProductSynonyms: AI failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function getFallbackSynonyms(): array
    {
        // Universal fallback synonyms for common product types
        return [
            // === ЕЛЕКТРОНІКА ===
            'смартфони' => ['смартфон', 'телефон', 'мобільний', 'мобілка', 'phone', 'smartphone', 'айфон', 'iphone', 'труба', 'звонилка', 'мобила', 'сотовий'],
            'ноутбуки' => ['ноутбук', 'ноут', 'бук', 'лептоп', 'laptop', 'notebook', 'комп', 'ноутік', 'портативний', 'macbook', 'макбук'],
            'планшети' => ['планшет', 'tablet', 'таблет', 'айпад', 'ipad', 'планшетник', 'таблетка'],
            'навушники' => ['навушники', 'наушники', 'вуха', 'headphones', 'earbuds', 'earphones', 'ейрподси', 'airpods', 'bluetooth навушники', 'бездротові'],
            'телевізори' => ['телевізор', 'телевизор', 'тв', 'tv', 'television', 'телік', 'телек', 'плазма', 'смарт тв', 'smart tv'],
            'зарядки' => ['зарядка', 'зарядний', 'charger', 'charging', 'блок живлення', 'адаптер', 'зарядное', 'шнур', 'кабель'],
            'павербанки' => ['павербанк', 'powerbank', 'power bank', 'зарядка портативна', 'батарея', 'акумулятор', 'повербанк'],
            
            // === ОДЯГ (ЗАГАЛЬНИЙ) ===
            'куртки' => ['куртка', 'курточка', 'jacket', 'пальто', 'парка', 'вітровка', 'бомбер', 'пуховик', 'зимова куртка', 'демісезонна'],
            'штани' => ['штани', 'брюки', 'pants', 'trousers', 'джинси', 'jeans', 'спортивні штани', 'штаны', 'легінси'],
            'футболки' => ['футболка', 'tshirt', 't-shirt', 'майка', 'топ', 'top', 'теніска', 'поло', 'polo'],
            'сукні' => ['сукня', 'плаття', 'dress', 'платье', 'вечірня сукня', 'коктейльне', 'літня сукня'],
            'светри' => ['светр', 'свитер', 'sweater', 'кофта', 'пуловер', 'pullover', 'джемпер', 'кардиган', 'cardigan'],
            'жіночий одяг' => ['жіночий одяг', 'женская одежда', 'women clothing', 'одяг для жінок', 'жіноче', 'дамський одяг', 'ladies'],
            'чоловічий одяг' => ['чоловічий одяг', 'мужская одежда', 'men clothing', 'одяг для чоловіків', 'чоловіче', 'mens wear', 'menswear'],
            'дитячий одяг' => ['дитячий одяг', 'детская одежда', 'kids clothes', 'боді', 'комбінезон', 'пінетки', 'чоловічки'],
            'одяг та взуття' => ['одяг', 'взуття', 'clothing', 'footwear', 'обувь', 'одежда', 'гардероб', 'wardrobe'],
            
            // === ВЗУТТЯ ===
            'кросівки' => ['кросівки', 'кроссовки', 'кроси', 'sneakers', 'тапки', 'найки', 'адідас', 'adidas', 'nike', 'спортивне взуття'],
            'черевики' => ['черевики', 'ботинки', 'boots', 'черевички', 'напівчеревики', 'зимові черевики', 'демісезонні'],
            'туфлі' => ['туфлі', 'туфли', 'shoes', 'лодочки', 'балетки', 'мокасини', 'оксфорди', 'лофери'],
            'сандалі' => ['сандалі', 'сандали', 'sandals', 'босоніжки', 'шльопанці', 'в\'єтнамки', 'шльопки'],
            
            // === АКСЕСУАРИ ===
            'сумки' => ['сумка', 'сумочка', 'bag', 'рюкзак', 'backpack', 'клатч', 'портфель', 'шопер', 'тоут'],
            'рюкзаки' => ['рюкзак', 'backpack', 'ранець', 'pack', 'рюкзачок', 'наплічник', 'міський рюкзак'],
            'гаманці' => ['гаманець', 'кошелек', 'wallet', 'портмоне', 'гаманчик', 'кошельок', 'клатч'],
            'ремені' => ['ремінь', 'ремень', 'belt', 'пояс', 'ременець', 'шкіряний ремінь'],
            
            // === КРАСА ===
            'парфуми' => ['парфуми', 'духи', 'perfume', 'туалетна вода', 'eau de parfum', 'аромат', 'парфюм', 'одеколон'],
            'косметика' => ['косметика', 'cosmetics', 'макіяж', 'makeup', 'помада', 'тіні', 'туш', 'пудра', 'тональний'],
            'шампуні' => ['шампунь', 'shampoo', 'засіб для волосся', 'бальзам', 'кондиціонер', 'маска для волосся'],
            'шампунь' => ['шампунь', 'shampoo', 'засіб для волосся', 'мийка для волосся', 'догляд за волоссям', 'hair care'],
            'лак для волосся' => ['лак', 'hairspray', 'фіксатор', 'стайлінг', 'styling', 'спрей для волосся', 'укладка'],
            'крем для обличчя' => ['крем', 'cream', 'face cream', 'зволожувач', 'moisturizer', 'денний крем', 'нічний крем', 'догляд за шкірою'],
            'крем для рук' => ['крем для рук', 'hand cream', 'догляд за руками', 'зволоження рук', 'крем'],
            'молочко для обличчя' => ['молочко', 'lotion', 'face lotion', 'очищення', 'демакіяж', 'cleanser', 'міцелярна вода'],
            'молочко для тіла' => ['молочко для тіла', 'body lotion', 'body milk', 'догляд за тілом', 'зволоження тіла'],
            'тональний крем' => ['тональний', 'foundation', 'тоналка', 'bb крем', 'cc крем', 'makeup base', 'основа під макіяж'],
            'сіль для ванної' => ['сіль для ванної', 'bath salt', 'ванна', 'засіб для ванни', 'релакс', 'спа'],
            
            // === ДІМ ===
            'меблі' => ['меблі', 'мебель', 'furniture', 'стіл', 'стул', 'шафа', 'диван', 'ліжко', 'комод'],
            'дивани' => ['диван', 'sofa', 'couch', 'софа', 'канапа', 'тахта', 'кутовий диван', 'розкладний'],
            'столи' => ['стіл', 'стол', 'table', 'desk', 'письмовий стіл', 'обідній стіл', 'журнальний столик'],
            'лампи' => ['лампа', 'світильник', 'lamp', 'люстра', 'торшер', 'бра', 'led лампа', 'освітлення'],
            
            // === СПОРТ ===
            'велосипеди' => ['велосипед', 'велік', 'bike', 'bicycle', 'байк', 'гірський велосипед', 'mtb', 'шосейник'],
            'гантелі' => ['гантелі', 'гантели', 'dumbbells', 'гирі', 'штанга', 'weights', 'вантажі', 'обважнювачі'],
            'килимки для йоги' => ['килимок', 'yoga mat', 'мат', 'каремат', 'килимок для фітнесу', 'йога мат'],
            
            // === ТАКТИЧНЕ СПОРЯДЖЕННЯ ===
            'плитоноски' => ['плитоноска', 'бронік', 'plate carrier', 'pc', 'бронежилет', 'жилет', 'носій плит', 'броник', 'плитник', 'плейт керрієр', 'плейт-керріер', 'плейткерріер', 'carrier', 'жилетка', 'тактичний жилет', 'бронежилетка'],
            'бронежилети та плитоноски' => ['бронежилет', 'плитоноска', 'plate carrier', 'body armor', 'броня', 'бронік', 'жилет', 'vest', 'armor', 'броник', 'плитник', 'carrier'],
            'шоломи' => ['шолом', 'каска', 'helmet', 'балістичний шолом', 'кевлар', 'шлем', 'helm', 'fast', 'mich', 'ach', 'ops core', 'опс кор', 'тактичний шолом', 'захисний шолом', 'бойовий шолом', 'kevlar', 'bump helmet', 'бамп'],
            'бронепластини' => ['бронепластина', 'плита', 'plate', 'armor plate', 'керамічна плита', 'сталева плита', 'балістична плита', 'броня', 'пластина', 'plates', 'sapi', 'esapi'],
            'підсумки' => ['підсумок', 'підсумки', 'pouch', 'pouches', 'сумка', 'кишеня', 'mag pouch', 'магазинний підсумок', 'підсумок для магазинів', 'патронташ', 'molle'],
            'розвантажувальні системи' => ['розвантажка', 'розвантаження', 'chest rig', 'load bearing', 'разгрузка', 'рпс', 'нагрудник', 'нагрудна система', 'лбв', 'lbe'],
            'турнікети' => ['турнікет', 'cat', 'tq', 'джгут', 'кровоспин', 'tourniquet', 'жгут', 'кровоспинний', 'кровоспинка', 'турникет', 'combat tourniquet'],
            'активні наушники' => ['активні навушники', 'тактичні навушники', 'активні наушники', 'peltor', 'comtac', 'сордін', 'sordin', 'earmor', 'headset', 'гарнітура', 'навушники', 'наушники', 'активка', 'пелтор', 'комтак'],
            'аксесуари для навушників' => ['кріплення навушників', 'адаптер навушників', 'чебурашки', 'arc rail', 'helmet mount', 'рейка', 'кріплення на шолом', 'адаптер', 'ear muffs mount'],
            'комплектуючі на шоломи' => ['кавер', 'чохол на шолом', 'helmet cover', 'накладки', 'пади', 'pads', 'підвіс', 'suspension', 'ремінь шолома', 'nvg mount', 'кріплення пнв', 'страйкбайк'],
            'мякі балістичні вставки' => ['м\'яка вставка', 'soft armor', 'soft insert', 'балістична вставка', 'м\'який бронепакет', 'nij iiia', 'soft panel'],
            'захисне спорядження та модулі' => ['захист', 'захисний модуль', 'protective gear', 'модулі захисту', 'бокові плити', 'side plates', 'додатковий захист'],
            
            // === ТАКТИЧНИЙ ОДЯГ ===
            'тактичні штани' => ['тактичні штани', 'бойові штани', 'combat pants', 'cargo', 'карго', 'військові штани', 'штани з наколінниками', 'штани тактика', 'tactical pants'],
            'бойові штани з наколінниками' => ['штани з наколінниками', 'combat pants', 'наколінники', 'knee pads', 'тактичні штани', 'бойові штани', 'g3', 'crye'],
            'бойові сорочки (ubacs)' => ['убакс', 'ubacs', 'бойова сорочка', 'combat shirt', 'тактична сорочка', 'сорочка', 'бойовка'],
            'рукавиці' => ['рукавиці', 'перчатки', 'gloves', 'тактичні рукавиці', 'mechanix', 'механікс', 'рукавички', 'рукави'],
            'берці' => ['берці', 'берцы', 'boots', 'черевики', 'тактичні черевики', 'tactical boots', 'військові черевики', 'combat boots', 'бойове взуття', 'тактичне взуття'],
            'взуття' => ['взуття', 'обувь', 'footwear', 'черевики', 'кросівки', 'берці', 'boots', 'shoes', 'тактичне взуття'],
            
            // === ГОЛОВНІ УБОРИ ===
            'шапки, шарфи, бафи та балаклави' => ['шапка', 'баф', 'балаклава', 'шарф', 'бафф', 'buff', 'balaclava', 'beanie', 'шиємаска', 'маска', 'зимова шапка'],
            'кепки, панами та бандани' => ['кепка', 'панама', 'бандана', 'cap', 'hat', 'boonie', 'буні', 'козирок', 'бейсболка', 'тактична кепка'],
            
            // === СУМКИ ТА РЮКЗАКИ ===
            'рюкзаки' => ['рюкзак', 'backpack', 'ранець', 'pack', 'рюкзачок', 'наплічник', 'тактичний рюкзак', 'assault pack', 'штурмовий рюкзак', 'daypack'],
            'сумки' => ['сумка', 'сумочка', 'bag', 'дамп', 'dump pouch', 'тактична сумка', 'медична сумка', 'range bag', 'стрілецька сумка'],
            
            // === МЕДИЦИНА ===
            'медицина' => ['медицина', 'аптечка', 'ifak', 'медичний', 'first aid', 'бинт', 'турнікет', 'медичне спорядження', 'tactical medicine', 'тактична медицина'],
            
            // === ШЕВРОНИ ===
            'шеврони та патчі ' => ['шеврон', 'патч', 'patch', 'нашивка', 'нарукавний знак', 'прапор', 'flag patch', 'morale patch', 'velcro patch', 'липучка'],
            'шеврони' => ['шеврон', 'патч', 'patch', 'нашивка', 'шевроны', 'нашивки', 'нарукавний знак', 'прапор', 'morale patch', 'липучка', 'velcro'],
            
            // === ЕЛЕКТРИЧНЕ ТА ТУРИСТИЧНЕ ===
            'електричне обладнання' => ['електрика', 'батарея', 'акумулятор', 'battery', 'зарядка', 'ліхтар', 'flashlight', 'електронне'],
            'туристичне обладнання' => ['туризм', 'кемпінг', 'camping', 'похід', 'outdoor', 'спорядження', 'намет', 'tent', 'спальник', 'sleeping bag'],
            
            // === LEVEL ОДЯГ ===
            'level 1' => ['термобілизна', 'терма', 'base layer', 'термо', 'нижня білизна', 'термоодяг', 'thermal'],
            'level 3' => ['фліс', 'флісова кофта', 'fleece', 'полар', 'polar', 'флісовка', 'mid layer'],
            'level 5' => ['софтшел', 'soft shell', 'softshell', 'вітровка', 'windbreaker', 'мембрана'],
            'level 7' => ['зимовий одяг', 'зимова куртка', 'утеплений', 'insulated', 'зимовка', 'прималофт', 'primaloft', 'пуховик'],
            
            // === ЗБРОЙОВІ АКСЕСУАРИ ===
            'збройові ремені' => ['ремінь', 'слінг', 'sling', 'збройовий ремінь', 'тактичний ремінь', '2 point', '1 point', 'одноточка', 'двохточка', 'ременюка'],
            'штурмові гвинтівки' => ['підсумок ар', 'ar pouch', 'm4', 'ak', 'magazine', 'магазин', 'калаш', 'підсумок магазин', 'rifle pouch'],
            
            // === ЧИСТКА ЗБРОЇ ===
            'чистка та догляд за зброєю' => ['чистка зброї', 'gun cleaning', 'набір для чистки', 'cleaning kit', 'мастило', 'oil', 'щітка', 'шомпол', 'патчі'],
            
            // === ДИТЯЧЕ ===
            'іграшки' => ['іграшка', 'игрушка', 'toy', 'toys', 'ляльки', 'машинки', 'конструктор', 'lego', 'лего'],
            'коляски' => ['коляска', 'stroller', 'прогулянкова коляска', 'дитяча коляска', 'люлька', 'трансформер'],
            'дитячий одяг' => ['дитячий одяг', 'детская одежда', 'kids clothes', 'боді', 'комбінезон', 'пінетки', 'чоловічки'],
        ];
    }

    private function saveSynonyms(array $synonymsMap, bool $force, ?int $tenantId = null): array
    {
        if ($force) {
            $this->warn("Deleting existing synonyms...");
            if ($tenantId) {
                ProductSynonym::where('tenant_id', $tenantId)->delete();
            } else {
                ProductSynonym::whereNull('tenant_id')->delete();
            }
        }

        $inserted = 0;
        $skipped = 0;

        foreach ($synonymsMap as $productType => $synonyms) {
            $productType = mb_strtolower(trim($productType));
            
            foreach ($synonyms as $synonym) {
                $synonym = mb_strtolower(trim($synonym));
                if (empty($synonym)) continue;

                // Check if exists (for this tenant or global)
                $exists = ProductSynonym::where('product_type', $productType)
                    ->where('synonym', $synonym)
                    ->where(fn($q) => $tenantId 
                        ? $q->where('tenant_id', $tenantId)
                        : $q->whereNull('tenant_id'))
                    ->exists();

                if ($exists && !$force) {
                    $skipped++;
                    continue;
                }

                ProductSynonym::updateOrCreate(
                    [
                        'product_type' => $productType, 
                        'synonym' => $synonym,
                        'tenant_id' => $tenantId,
                    ],
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
        
        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    private function detectLanguage(string $text): string
    {
        if (preg_match('/[а-яіїєґ]/ui', $text)) {
            return preg_match('/[іїєґ]/ui', $text) ? 'uk' : 'ru';
        }
        return 'en';
    }
}
