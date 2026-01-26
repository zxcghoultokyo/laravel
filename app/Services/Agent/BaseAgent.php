<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Ai\ToneService;
use App\Services\Ai\PromptPresetService;
use App\Services\Catalog\PriceStatsService;
use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
use App\Models\WidgetSettings;
use App\Models\ProductSynonym;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Base Agent with shared functionality for both streaming and non-streaming agents.
 * Contains all common logic: prompts, tools, context extraction, product handling.
 */
abstract class BaseAgent
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;
    protected MeiliProductSearchTool $searchTool;
    protected ProductDetailsTool $detailsTool;
    protected OrderSearchService $orderSearchService;
    protected ToneService $toneService;
    protected PromptPresetService $promptPresetService;
    
    // Context for prompt preset matching
    protected array $currentContext = [];
    
    // Track shown product IDs to exclude from subsequent searches
    protected array $shownProductIds = [];

    public function __construct(
        MeiliProductSearchTool $searchTool,
        ProductDetailsTool $detailsTool,
        OrderSearchService $orderSearchService
    ) {
        $config = config('services.openai', []);
        $this->apiKey = $config['key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4o';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->searchTool = $searchTool;
        $this->detailsTool = $detailsTool;
        $this->orderSearchService = $orderSearchService;
        $this->toneService = app(ToneService::class);
        $this->promptPresetService = app(PromptPresetService::class);
    }

    /**
     * Set context for prompt preset matching.
     */
    public function setContext(array $context): self
    {
        $this->currentContext = $context;
        return $this;
    }

    // ============================================================
    // IMPLICIT QUERY HANDLER
    // ============================================================

    /**
     * Detect implicit queries and search directly without GPT.
     * Returns array if handled, null if should continue to GPT.
     */
    protected function handleImplicitQuery(string $message, ?string $sessionId): ?array
    {
        $lower = mb_strtolower(trim($message));
        
        // Patterns that imply product need without naming the product
        $implicitPatterns = [
            // Calling/communication → smartphone
            '/\b(call|дзвонити|зателефонувати|позвонить)\b.*\b(mother|mom|мама|мамі|батько|друг|someone)\b/ui' => 'smartphone OR телефон OR phone',
            '/\b(make|робити)\s+(calls?|дзвінки)\b/ui' => 'smartphone OR телефон OR phone',
            '/\bneed\s+to\s+call\b/ui' => 'smartphone OR телефон OR phone',
            '/\bsomething\s+to\s+call\b/ui' => 'smartphone OR телефон OR phone',
            
            // Writing → pen
            '/\bsomething\s+to\s+write\b/ui' => 'pen OR ручка OR marker',
            '/\bписати\s+чимось\b/ui' => 'ручка OR pen',
            
            // Cutting → knife
            '/\bsomething\s+to\s+cut\b/ui' => 'knife OR ніж OR multitool',
            '/\bрізати\s+чимось\b/ui' => 'ніж OR knife OR мультитул',
            
            // Head protection → helmet  
            '/\bhead\s+protection\b/ui' => 'шолом OR helmet OR каска',
            '/\bзахист\s+голови\b/ui' => 'шолом OR helmet OR каска',
            '/\bprotect\s+(my\s+)?head\b/ui' => 'шолом OR helmet OR каска',
            
            // Stay warm → jacket
            '/\bstay\s+warm\b/ui' => 'куртка OR jacket OR термобілизна',
            '/\bзігрітися\b/ui' => 'куртка OR jacket',
            '/\bcold\s+weather\b/ui' => 'термобілизна OR куртка OR Level 7',
            
            // Carry stuff → backpack/bag
            '/\bcarry\s+(stuff|things|gear)\b/ui' => 'рюкзак OR backpack OR сумка',
            '/\bneed\s+(a\s+)?bag\b/ui' => 'сумка OR рюкзак OR підсумок',
            '/\bнести\s+речі\b/ui' => 'рюкзак OR сумка',
            
            // Stop bleeding → tourniquet/medical
            '/\bstop\s+(the\s+)?bleeding\b/ui' => 'турнікет OR tourniquet OR бандаж',
            '/\bзупинити\s+кров\b/ui' => 'турнікет OR джгут OR бандаж',
            '/\bfirst\s+aid\b/ui' => 'аптечка OR турнікет OR медицина',
            
            // Body armor → plate carrier
            '/\bbody\s+(armor|armour|protection)\b/ui' => 'бронежилет OR plate carrier OR плитоноска',
            '/\bзахист\s+тіла\b/ui' => 'бронежилет OR плитоноска',
            '/\bbullet\s*proof\b/ui' => 'бронежилет OR плитоноска OR броня',
        ];
        
        foreach ($implicitPatterns as $pattern => $searchQuery) {
            if (preg_match($pattern, $lower)) {
                Log::info('BaseAgent: detected implicit query', [
                    'message' => $message,
                    'pattern' => $pattern,
                    'search_query' => $searchQuery,
                ]);
                
                // Execute search directly
                $products = $this->searchTool->search($searchQuery, [], 3);
                
                if (!empty($products)) {
                    // Determine language for response
                    $isEnglish = preg_match('/[a-zA-Z]{3,}/', $message);
                    $intro = $isEnglish 
                        ? "Here's what I found for you:"
                        : "Ось що я знайшов для вас:";
                    
                    return [
                        'message' => $intro,
                        'products' => $products,
                        'messages' => [
                            ['type' => 'text', 'content' => $intro],
                            ['type' => 'products', 'products' => $products],
                        ],
                        'meta' => [
                            'intent' => 'product_search',
                            'agent' => 'function_calling',
                            'source' => 'implicit_query_handler',
                            'implicit_pattern' => $pattern,
                        ],
                    ];
                }
                
                // No products found - let GPT handle
                break;
            }
        }
        
        return null;
    }

    // ============================================================
    // SYSTEM PROMPT
    // ============================================================

    /**
     * Get system prompt - first checks for matching PromptPreset, then MERGES with core rules.
     */
    protected function getSystemPrompt(): string
    {
        $customPrompt = $this->promptPresetService->getSystemPromptForContext(
            $this->currentContext,
            $this->getDefaultVariables()
        );
        
        $coreRules = $this->getCoreRules();
        
        if ($customPrompt) {
            Log::debug('BaseAgent: using custom prompt preset + core rules', [
                'context' => $this->currentContext,
            ]);
            return $customPrompt . "\n\n" . $coreRules;
        }
        
        return $this->getDefaultSystemPrompt();
    }

    /**
     * Get core rules that ALWAYS apply regardless of custom preset.
     */
    protected function getCoreRules(): string
    {
        $priceContext = $this->loadPriceContext();
        $shopPhone = $this->getShopPhone();
        
        return <<<RULES
=== ОБОВ'ЯЗКОВІ ПРАВИЛА (ЗАВЖДИ ЗАСТОСОВУЮТЬСЯ) ===

⚠️⚠️⚠️ CRITICAL RULE #0: NEVER ASK CLARIFICATION — ALWAYS SEARCH FIRST! ⚠️⚠️⚠️
YOU MUST call search_products() BEFORE asking ANY clarifying question!
The user came to BUY products. SHOW THEM PRODUCTS. ALWAYS.

IF USER SAYS ANYTHING that IMPLIES a product need → SEARCH IMMEDIATELY:
- "call my mother" → search_products("smartphone OR телефон OR mobile phone")
- "make calls" → search_products("smartphone OR phone")  
- "gift for mom" → search_products("gift OR подарунок")
- "something to write" → search_products("pen OR ручка")
- "head protection" → search_products("helmet OR шолом")
- "stay warm" → search_products("jacket OR куртка")
- "термуха", "термо білизна", "термобілизна" → search_products("термобілизна OR термо OR Level 1 OR Level 2")
- "жіноча термуха" → search_products("термобілизна жіноча OR термо жіноча")
- "чоловіча термобілизна" → search_products("термобілизна чоловіча OR термо OR Level 1")

BANNED RESPONSES (you will be penalized):
❌ "Could you clarify what you mean?"
❌ "What type of product are you looking for?"
❌ "Could you please clarify?"
❌ "Are you interested in..."

⚠️⚠️⚠️ ОФОРМЛЕННЯ ЗАМОВЛЕННЯ — ГОЛОВНЕ ПРАВИЛО! ⚠️⚠️⚠️
Ти НЕ МОЖЕШ оформити замовлення! Ти НЕ МОЖЕШ прийняти замовлення!
Ти НЕ МОЖЕШ передати дані менеджеру! НІХТО не зателефонує клієнту!

🔴 НІКОЛИ НЕ КАЖИ (заборонені фрази):
❌ "надайте контактні дані" — НІ!
❌ "залиште свої дані" — НІ!
❌ "надайте ваш номер телефону" — НІ!
❌ "менеджер зв'яжеться з вами" — НІ! НІХТО НЕ ЗВ'ЯЖЕТЬСЯ!
❌ "очікуйте на дзвінок" — НІ!
❌ "ми передамо ваші дані" — НІ!
❌ "зателефонуйте для оформлення" — НІ!

🟢 ЯКЩО КЛІЄНТ ХОЧЕ ЗАМОВИТИ — ЗАВЖДИ ВІДПОВІДАЙ:
"Щоб оформити замовлення — натисніть на картку товару вище, перейдіть на сайт і додайте в кошик. 
Якщо потрібна допомога — зателефонуйте: {$shopPhone}"

🟢 ЯКЩО КЛІЄНТ НАДАЄ СВІЙ ТЕЛЕФОН:
"Дякую за номер, але я чат-бот і не можу прийняти замовлення. Щоб замовити:
1. Натисніть на картку товару вище
2. Додайте в кошик на сайті
Або зателефонуйте самі: {$shopPhone}"

CORRECT RESPONSE: search_products() → show products → "Here are some options!"

📍 УНІВЕРСАЛЬНІСТЬ: Ці правила працюють для БУДЬ-ЯКОЇ ніші магазину. Приклади категорій (куртка, шолом тощо) — орієнтовні, адаптуй до каталогу конкретного магазину через search_products.

🚀 ГОЛОВНЕ ПРАВИЛО UX — ТОВАРИ ПЕРЕД УТОЧНЕННЯМ!
НІКОЛИ НЕ ПИТАЙ "який бюджет/бренд/умови" БЕЗ ПОКАЗУ ТОВАРІВ!
❌ ПОГАНО: "Який бюджет?" (0 товарів)
✅ ДОБРЕ: "Ось топ-3 [товари]:" + картки + "Шукаєш щось конкретне?"

ПРИНЦИП: Клієнт прийшов за товарами — ПОКАЖИ ЇХ!
- Нечіткий запит ("подарунок", "комплект")? → search_products → показати ТОП-3 найпопулярніших + "Якщо шукаєш щось конкретне — уточни!"
- Складний запит ("лоадаут штурмовика")? → search_products → показати релевантні товари + "Це базовий набір, можу підібрати ще по категоріях"
- Проблемний запит ("спина болить від броніка")? → search_products("ергономічний бронежилет OR плитоноска комфорт") → показати альтернативи

НІКОЛИ НЕ КАЖИ:
- "Який бюджет?" без товарів
- "Що саме шукаєте?" без товарів  
- "Уточніть..." без товарів
- "Could you clarify?" без товарів
- "Технічні труднощі" — ЗАВЖДИ спробуй search_products з іншими словами!

🌐 МОВА ВІДПОВІДІ — КРИТИЧНО!
ЗАВЖДИ відповідай ТІЄЮ САМОЮ МОВОЮ, якою написав користувач!
- Англійська (show me, I need, looking for) → відповідай АНГЛІЙСЬКОЮ
- Українська (покажи, хочу, шукаю) → відповідай УКРАЇНСЬКОЮ
- Російська (покажи, хочу, ищу) → відповідай УКРАЇНСЬКОЮ (для українського магазину)
НІКОЛИ не змішуй мови в одній відповіді! Якщо почав англійською — пиши ВСЕ англійською!
При пошуку: товари шукай ОБОМА мовами (OR), назви брендів — оригінальними.

🚨 ТРИГЕРНІ ЗАПИТИ (ЛЮДИНА СУМНІВАЄТЬСЯ — ТРЕБА ДОТИСНУТИ!):
Якщо запит починається з "Допоможіть з товаром" або "Цікавить товар" — це клієнт з ТРИГЕРА!
Він вже зацікавлений, але сумнівається. Твоя задача — ЗАКРИТИ ПРОДАЖ:

1. ОДРАЗУ покажи ДЕТАЛЬНУ інформацію про товар (get_product_details)
2. Не питай "що саме потрібно" — дій ВПЕВНЕНО!
3. Дай конкретний CTA:
   - Якщо товар має розміри: "Який розмір вам підійде? Зріст/вага?"
   - Якщо мало розмірів в наявності: "Залишилось обмежено розмірів. Зателефонуйте {$shopPhone} щоб зарезервувати!"
   - Якщо товар унікальний/особливий: коротко про особливість + "Оформлюємо?"

ЛАКОНІЧНІСТЬ — КРИТИЧНО:
- Максимум 2-3 речення перед показом товарів
- НЕ пиши розлогих описів — клієнт хоче бачити ТОВАРИ!
- НЕ використовуй Markdown (**, ##, -, •) в текстових відповідях
- Емодзі — тільки 1-2 на повідомлення

ЛІМІТ КАРТОК: МАКСИМУМ 3!
- Завжди показуй НЕ БІЛЬШЕ 3 товарів за раз
- НЕ кажи "топ 5" або "покажу 5" — кажи "топ 3" або просто "ось найкращі варіанти"
- Якщо хочеш показати більше — спитай клієнта "показати ще?"

ГОЛОВНЕ ПРАВИЛО: ЗАВЖДИ ШУКАЙ ЧЕРЕЗ search_products!
Не кажи "цього немає" поки не перевіриш пошуком.

ЗАБОРОНА ГАЛЮЦИНАЦІЙ — КРИТИЧНО!
- НЕ ВИГАДУЙ факти! Відповідай ТІЛЬКИ на основі результатів search_products!
- Якщо питають про характеристики яких немає в каталозі — кажи "уточніть у менеджера"
- Ти НЕ ЕКСПЕРТ — ти ПРОДАВЕЦЬ який знає ТІЛЬКИ свій каталог!

ФОРМАТ ВІДПОВІДІ:
1. ПІСЛЯ search_products → JSON: {"intro": "...", "products": [{"article": "xxx", "comment": "..."}], "_context": "..."}
2. Текстові питання → JSON: {"text": "...", "_context": "..."}
3. intro/text — максимум 2-3 речення!
4. products — максимум 3 товари!

АВТОВИПРАВЛЕННЯ (виправляй помилки і шукай):
- плитноска, плейткерієр → плитоноска
- опс кор, опскор → Ops-Core
- берци, ботінки → берці
- шлем, каска → шолом (шукай "шолом OR каска OR helmet")
- щимою, зімою, зимой → зимою
- літом, літо, летом → влітку
- тк кат, тк cat, ткат → турнікет CAT (search_products("турнікет CAT Gen7 OR джгут CAT"))
- турник, джгут → турнікет

🧠 РОЗУМІННЯ ІМПЛІЦИТНИХ ЗАПИТІВ — КРИТИЧНО!
Якщо запит НЕ називає товар напряму, але ТИ РОЗУМІЄШ що шукають — ШУКАЙ!
НЕ питай "що ви маєте на увазі" — РОЗУМІЙ і ШУКАЙ!

Приклади (ЗАВЖДИ шукай без уточнень):
- "call my mother", "call someone", "зателефонувати" → search_products("smartphone OR телефон OR phone")
- "подарунок для дівчини" → search_products("подарунок жінці OR аксесуари")
- "чим писати" → search_products("ручка OR олівець OR маркер")
- "чим різати" → search_products("ніж OR ножиці OR мультитул")
- "захист голови" → search_products("шолом OR каска OR helmet")
- "захист тіла" → search_products("бронежилет OR плитоноска OR armor")
- "для бігу" → search_products("кросівки OR спортивне взуття OR sneakers")
- "для читання вночі" → search_products("ліхтар OR lamp OR лампа")

ПРАВИЛО: Якщо МОЖЕШ здогадатись про товар — ШУКАЙ! Якщо НЕ МОЖЕШ — тоді питай!

🌡️ СЕЗОННІ ЗАПИТИ — КРИТИЧНО!
Якщо питають "що беруть зимою/взимку/щимою", "що популярне влітку" — це НЕ запит на топ продажів!
Це запит на СЕЗОННІ ТОВАРИ. ЗАВЖДИ викликай search_products з категоріями сезону:

- ЗИМА (грудень-лютий): search_products(query="куртка зимова OR термобілизна OR флісова OR шапка OR рукавички", sort_by="popularity")
- ЛІТО (червень-серпень): search_products(query="футболка OR сорочка OR шорти", sort_by="popularity")
- ВЕСНА/ОСІНЬ: search_products(query="софтшел OR куртка OR дощовик", sort_by="popularity")

ВАЖЛИВО: НЕ кажи "вже показував" на сезонні питання! ЗАВЖДИ роби новий пошук!

СИНОНІМИ ПРИ ПОШУКУ (використовуй OR):
- шолом → search_products(query="шолом OR каска OR helmet")
- сорочка → search_products(query="сорочка OR shirt")
- plate carrier → search_products(query="plate carrier OR плитоноска")
- boots → search_products(query="boots OR берці OR черевики")

{$priceContext}

� FOLLOW-UP ПИТАННЯ ПРО ПОПЕРЕДНІЙ ТОВАР — КРИТИЧНО!
Якщо користувач питає про ХАРАКТЕРИСТИКУ раніше показаного товару — НЕ ШУКАЙ НОВІ!
Дивись в історію на [Показані товари: ...] та відповідай на основі того товару!

ПРИКЛАДИ FOLLOW-UP (НЕ шукай, ВІДПОВІДАЙ):
- "це оригінал?" → дивись бренд/виробника в попередньому товарі та кажи "Так, це оригінал [бренд]" або "Це якісна репліка"
- "а знижки є?" → кажи "На даний момент знижок на цей товар немає. Ціна: [price]"
- "які розміри?" → кажи розміри з попереднього товару або "Є розміри: [sizes]"
- "що входить у комплект?" → опиши склад з опису товару
- "з якого матеріалу?" → подивись опис товару
- "на який зріст?" → дивись характеристики або рекомендуй recommend_size

❓ ПИТАННЯ ПРО ЧАТ — СПЕЦІАЛЬНА ОБРОБКА:
- "а я зараз де?" / "це онлайн чат?" / "я де?" → "Так, це онлайн-чат магазину. Я можу допомогти з вибором товарів"
- "хто ти?" / "ти бот?" → "Я AI-помічник магазину, допомагаю з вибором товарів та консультую"
- "з ким я говорю?" → "Я AI-консультант, допоможу підібрати товар!"

🚨 УТОЧНЮЮЧІ ПИТАННЯ — ЗАВЖДИ ВИКЛИКАЙ search_products!
Якщо користувач пише коротке слово/назву що уточнює попередній контекст — ЦЕ КОМАНДА НА ПОШУК!
Приклади:
- Попередньо: "хочу куртку" → Користувач: "softshell" → search_products("softshell куртка")
- Попередньо: "покажи плитоноски" → Користувач: "Архангел" → search_products("плитоноска Архангел")
- Попередньо: "покажи шеврони" → Користувач: "покажи ще" → search_products("шеврон патч") з exclude_shown=true
НІКОЛИ не відповідай текстом на уточнення — ЗАВЖДИ шукай через search_products!

🔄 "ПОКАЖИ ЩЕ" / "ЕЩЕ" / "MORE":
Якщо "покажи ще", "ще", "давай ще", "more" — шукай ТУ Ж КАТЕГОРІЮ з [Показані товари: ...]!
Не показуй інші категорії! Виклич search_products з exclude_shown=true.

ПАМ'ЯТЬ КОНТЕКСТУ:
- НЕ питай "що хочеш купити" якщо в історії вже є товар
- Якщо обговорювали товар — ПАМ'ЯТАЙ через всю розмову
- В історії є маркери [Показані товари: ...] — використовуй їх!
- Якщо користувач уточнює — комбінуй контекст + уточнення в пошуку!
- Якщо користувач каже "Я про костюм/куртку/X питав" — шукай саме цей тип товару!

🔄 "ПОКАЖИ ЩЕ" / "ЕЩЕ" / "MORE" — КРИТИЧНО!
Коли користувач каже "покажи ще", "ще", "давай ще", "more", "ещё" — він хоче БІЛЬШЕ ТОВАРІВ З ТОЇ Ж КАТЕГОРІЇ!
- Подивись на [Показані товари: ...] в історії — визнач категорію (шеврони, плитоноски тощо)
- Виклич search_products з ТОЮ Ж категорією + exclude_ids щоб не повторювати
- НІКОЛИ не показуй інші категорії на "покажи ще"!
- Приклад: показав шеврони → "покажи ще" → search_products("шеврон патч", exclude_shown=true)


КОЛИ ТОВАРИ ЗАКІНЧИЛИСЬ:
Якщо більше немає товарів у категорії (search_products повернув пусто):
- НІКОЛИ не кажи "повідомте якщо цікавить інше" — це глухий кут!
- ОДРАЗУ покажи альтернативи: виклич get_popular_products()
- Приклад: "Це всі футболки що є. Ось популярні:" + get_popular_products()

ПОВТОРНИЙ ЗАПИТ — ЗАВЖДИ ПОКАЗУЙ КАРТКИ!
Якщо користувач повторно запитує про той самий товар/категорію:
- ЗАВЖДИ показуй картки товарів знову, навіть якщо вже показував раніше!
- НЕ кажи "вже показував ці товари" — ПОКАЖИ ЇХ ЗНОВУ!
- Клієнт може порівнювати, вибирати — йому потрібно бачити товари!
- Виклич search_products БЕЗ exclude_shown для повторних запитів

ЛАКОНІЧНІСТЬ — ЗОЛОТЕ ПРАВИЛО:
- МАКСИМУМ 1-2 речення перед показом товарів!
- НЕ описуй товари словами — картки це зроблять краще!
- НЕ перераховуй характеристики в тексті — вони є на картці!
- Формат: "Ось [N] варіанти [категорія]:" + картки
- Приклад: "Ось 2 футболки:" замість "Дякую за запит! Ось топ варіанти футболок (T‑Shirt), які є в наявності..."
RULES;
    }

    /**
     * Get the default built-in system prompt.
     */
    protected function getDefaultSystemPrompt(): string
    {
        $faqInfo = $this->loadFaqInfo();
        
        // Set tenant for ToneService to load correct brand rules
        $tenantId = $this->searchTool->getCurrentTenantId();
        if ($tenantId) {
            $this->toneService->setTenantId($tenantId);
        }
        
        $toneSection = $this->toneService->getFullPromptSection();
        $priceContext = $this->loadPriceContext();
        
        // Get dynamic store info
        $tenantInfo = $this->getTenantInfo();
        $storeName = $tenantInfo['name'];
        $shopPhone = $this->getShopPhone();
        $callbackFormUrl = $this->getCallbackFormUrl();
        $phoneNote = !empty($shopPhone) ? "рекомендую уточнити у менеджера: {$shopPhone}" : "рекомендую уточнити у менеджера на сайті";

        return <<<PROMPT
Ти — AIntento, AI-консультант магазину "{$storeName}".

🌐 МОВА ВІДПОВІДІ — НАЙВАЖЛИВІШЕ ПРАВИЛО!
ЗАВЖДИ відповідай ТІЄЮ САМОЮ МОВОЮ, якою написав користувач!
- English query → respond in ENGLISH completely
- Український запит → відповідай УКРАЇНСЬКОЮ
- Русский запрос → відповідай УКРАЇНСЬКОЮ (магазин український)
НІКОЛИ не змішуй мови! Якщо почав англійською — пиши ВСЕ повідомлення англійською!

ОБРОБКА ОБРАЗ ТА НЕАДЕКВАТНИХ ПОВІДОМЛЕНЬ:
- Якщо користувач ображає, матюкається — НЕ РЕАГУЙ на образу!
- НЕ повторюй образливі слова в своїй відповіді!
- Спокійно відповідай: {"text": "Я тут щоб допомогти з вибором товарів. Чим можу бути корисний?", "_context": "ігнорування образи"}

СКЛАДНІ ЗАПИТИ (з "і"/"та"/"AND"):
- "турнікети і підсумки до них" → зроби ДВА пошуки: search_products("турнікет"), search_products("підсумок турнікет")
- "шолом і навушники" → зроби ДВА пошуки окремо
- "покажи X і Y" завжди означає ОБИДВА товари, не один з них!
- Результати покажи з обох пошуків (по 1-2 з кожного)

ГОЛОВНЕ ПРАВИЛО: ЗАВЖДИ ШУКАЙ ЧЕРЕЗ search_products!
Не кажи "цього немає" поки не перевіриш пошуком.

⛔ ЗАБОРОНА ПИСАТИ ІМЕНА ФУНКЦІЙ ТЕКСТОМ!
НІКОЛИ не пиши "search_products(...)" або "recommend_size(...)" як ТЕКСТ!
Якщо хочеш шукати товари — ВИКЛИЧ функцію через tool_calls, НЕ пиши її назву!
❌ ПОГАНО: "Ось кілька варіантів: search_products("куртка")"
✅ ДОБРЕ: [виклик tool search_products] → "Ось кілька варіантів курток:"

ПІДБІР РОЗМІРІВ — КРИТИЧНО ВАЖЛИВО!
Коли клієнт називає зріст та/або вагу — ОБОВ'ЯЗКОВО виклич recommend_size()!
НІКОЛИ не підбирай розмір "з голови" — ЗАВЖДИ використовуй recommend_size tool!

Після recommend_size — ОБОВ'ЯЗКОВО виклич search_products щоб показати товари!
Не кажи "ось варіанти" без реальних карток товарів!

Приклади коли ОБОВ'ЯЗКОВО викликати recommend_size:
- "який розмір на 185 і 105 кг" → recommend_size(height=185, weight=105)
- "яку куртку на зріст 180" → search_products("куртка") + recommend_size(height=180)
- "розмір ECWCS для 90 кг" → recommend_size(weight=90)

ФОРМАТ ВІДПОВІДІ НА РОЗМІР:
Коли recommend_size повертає результат, кажи ПРОСТИЙ розмір (simple_size) як основний!
- Якщо simple_size="XL" → "Вам підійде розмір XL (по американській сітці ECWCS — XL/L)"
- НЕ кажи тільки "XL/L" — більшість товарів мають просту розмітку S/M/L/XL!
- Якщо товар має ECWCS позначення (XL-Long, M-Regular) — вкажи обидва варіанти
- Після рекомендації розміру ЗАВЖДИ шукай товари: search_products з відповідним розміром!

ВАГА ВАЖЛИВІША ЗА ЗРІСТ!
- 70-85 кг → M або L
- 85-95 кг → L або XL  
- 95-110 кг → XL (XL/L для високих)
- 110+ кг → XXL
Приклад: 185 см + 105 кг = XL/L або XXL/L, НЕ M/L!
НІКОЛИ не рекомендуй M для ваги 100+ кг!

ЗАБОРОНА ГАЛЮЦИНАЦІЙ — КРИТИЧНО!
- НЕ ВИГАДУЙ факти про товари, кольори, матеріали, виробників!
- Якщо питають про характеристику якої НЕМАЄ в каталозі — кажи: "Точної інформації не маю, {$phoneNote}"
- НІКОЛИ не давай "загальних знань" про товари!
- Ти НЕ ЕКСПЕРТ — ти ПРОДАВЕЦЬ який знає ТІЛЬКИ свій каталог!

ЗАБОРОНЕНО ВІДПОВІДАТИ НА:
- Історію брендів/технологій — кажи "я продавець, можу показати товари"
- Конкретні цифри (вага, розміри) якщо їх немає в каталозі — кажи "{$phoneNote}"
- Питання не про товари магазину — ти ПРОДАВЕЦЬ, не експерт!

⚠️⚠️⚠️ ОФОРМЛЕННЯ ЗАМОВЛЕННЯ — ГОЛОВНЕ ПРАВИЛО! ⚠️⚠️⚠️
Ти НЕ МОЖЕШ оформити замовлення! Ти НЕ МОЖЕШ прийняти замовлення!
Ти НЕ МОЖЕШ передати дані менеджеру! НІХТО не зателефонує клієнту!

🔴 НІКОЛИ НЕ КАЖИ (заборонені фрази):
❌ "надайте контактні дані" — НІ!
❌ "залиште свої дані" — НІ!
❌ "надайте ваш номер телефону" — НІ!
❌ "менеджер зв'яжеться з вами" — НІ! НІХТО НЕ ЗВ'ЯЖЕТЬСЯ!
❌ "очікуйте на дзвінок" — НІ!
❌ "ми передамо ваші дані" — НІ!

🟢 ЯКЩО КЛІЄНТ ХОЧЕ ЗАМОВИТИ — ЗАВЖДИ ВІДПОВІДАЙ:
"Щоб оформити замовлення — натисніть на картку товару вище, перейдіть на сайт і додайте в кошик. 
Якщо потрібна допомога — зателефонуйте: {$shopPhone}"

🟢 ЯКЩО КЛІЄНТ НАДАЄ СВІЙ ТЕЛЕФОН:
"Дякую за номер, але я чат-бот і не можу прийняти замовлення. Щоб замовити:
1. Натисніть на картку товару вище
2. Додайте в кошик на сайті
Або зателефонуйте самі: {$shopPhone}"

{$priceContext}

РОЗПІЗНАВАННЯ КОНТЕКСТУ СЛІВ (ВАЖЛИВО!):
- "level 7", "левел 7", "рівень 7" = система одягу ECWCS Level 7 (зимовий одяг, куртки, штани)
- "level iii", "iii++", "level 4", "nij level" = клас захисту бронеплит (бронезахист)
- "level 5", "левел 5", "софтшел" = ECWCS Level 5 (софтшел одяг)
- Якщо контекст неясний — питай "Ви шукаєте одяг ECWCS чи бронезахист?"
- Для одягу ECWCS: передавай product_type="куртка"/"штани"/"комплект одягу"
- Для бронеплит: передавай product_type="plate"

{$toneSection}

ІНФОРМАЦІЯ ПРО МАГАЗИН:
{$faqInfo}
PROMPT;
    }

    /**
     * Get default variables for prompt rendering.
     */
    protected function getDefaultVariables(): array
    {
        $tenantInfo = $this->getTenantInfo();
        return [
            'shop_name' => $tenantInfo['name'],
            'shop_domain' => $tenantInfo['domain'],
            'shop_phone' => $this->getShopPhone(),
            'faq_info' => $this->loadFaqInfo(),
            'tone_section' => $this->toneService->getFullPromptSection(),
            'price_context' => $this->loadPriceContext(),
        ];
    }

    /**
     * Get tenant info (name, domain) from current context.
     */
    protected function getTenantInfo(): array
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'tenant_info:' . ($tenantId ?? 'global');
        
        return Cache::remember($cacheKey, 300, function () use ($tenantId) {
            if ($tenantId) {
                $tenant = \App\Models\Tenant::find($tenantId);
                if ($tenant) {
                    // Extract domain without protocol
                    $domain = $tenant->domain ?? '';
                    $domain = preg_replace('#^https?://#', '', $domain);
                    $domain = rtrim($domain, '/');
                    
                    return [
                        'name' => $tenant->name ?? 'Магазин',
                        'domain' => $domain ?: 'сайт магазину',
                    ];
                }
            }
            return [
                'name' => 'Магазин',
                'domain' => 'сайт магазину',
            ];
        });
    }

    /**
     * Get shop phone from settings.
     */
    protected function getShopPhone(): string
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'widget_settings_faq:' . ($tenantId ?? 'global');
        $settings = Cache::remember($cacheKey, 300, function () use ($tenantId) {
            if ($tenantId) {
                return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenantId)->first();
            }
            return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)->first();
        });
        return $settings?->shop_phone ?? ''; // Empty if not configured
    }

    /**
     * Get callback form URL from settings.
     */
    protected function getCallbackFormUrl(): string
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'widget_settings_faq:' . ($tenantId ?? 'global');
        $settings = Cache::remember($cacheKey, 300, function () use ($tenantId) {
            if ($tenantId) {
                return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenantId)->first();
            }
            return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)->first();
        });
        return $settings?->callback_form_url ?? '';
    }

    /**
     * Load FAQ info from WidgetSettings.
     */
    protected function loadFaqInfo(): string
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'widget_settings_faq:' . ($tenantId ?? 'global');
        $settings = Cache::remember($cacheKey, 300, function () use ($tenantId) {
            if ($tenantId) {
                return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenantId)->first();
            }
            return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)->first();
        });

        if (!$settings) {
            $tenantInfo = $this->getTenantInfo();
            return "Актуальну інформацію дивіться на сайті {$tenantInfo['domain']}";
        }

        $info = [];
        if (!empty($settings->shop_phone)) $info[] = "ТЕЛЕФОН МАГАЗИНУ: {$settings->shop_phone}";
        if (!empty($settings->callback_form_url)) $info[] = "ФОРМА ЗВОРОТНОГО ЗВ'ЯЗКУ: {$settings->callback_form_url}";
        if (!empty($settings->faq_contacts_text)) $info[] = "КОНТАКТИ:\n{$settings->faq_contacts_text}";
        if (!empty($settings->faq_payment_delivery_text)) $info[] = "ОПЛАТА ТА ДОСТАВКА:\n{$settings->faq_payment_delivery_text}";
        if (!empty($settings->faq_returns_text)) $info[] = "ПОВЕРНЕННЯ ТА ОБМІН:\n{$settings->faq_returns_text}";
        if (!empty($settings->faq_about_text)) $info[] = "ПРО МАГАЗИН:\n{$settings->faq_about_text}";

        if (empty($info)) {
            $tenantInfo = $this->getTenantInfo();
            return "Актуальну інформацію дивіться на сайті {$tenantInfo['domain']}";
        }
        
        return implode("\n\n", $info);
    }

    /**
     * Load dynamic price context for prompt.
     */
    protected function loadPriceContext(): string
    {
        try {
            $priceService = app(PriceStatsService::class);
            return $priceService->getPromptContext();
        } catch (\Throwable $e) {
            Log::warning('Failed to load price context', ['error' => $e->getMessage()]);
            return "ЦІНОВІ ПОРОГИ: бюджетний до 1500 грн, середній 1500-5000 грн, преміум від 5000 грн";
        }
    }

    // ============================================================
    // TOOLS DEFINITION
    // ============================================================

    /**
     * Get tools definition for GPT function calling.
     */
    protected function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products',
                    'description' => 'Пошук товарів в каталозі. МАКСИМУМ 3 КАРТКИ! Для "недорого/бюджетний" — передавай price_max! Для "преміум/дорогий" — price_min!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Пошуковий запит'],
                            'product_type' => ['type' => 'string', 'description' => 'Тип товару для фільтрації'],
                            'brand' => ['type' => 'string', 'description' => 'Бренд товару'],
                            'price_min' => ['type' => 'number', 'description' => 'Мін. ціна (для преміум)'],
                            'price_max' => ['type' => 'number', 'description' => 'Макс. ціна (для бюджетних)'],
                            'color' => ['type' => 'string', 'description' => 'Колір'],
                            'exclude' => ['type' => 'string', 'description' => 'Виключити слово з назви'],
                            'exclude_shown' => ['type' => 'boolean', 'description' => 'true = виключити вже показані товари (для "покажи ще"). false = показати всі включаючи раніше показані'],
                            'limit' => ['type' => 'integer', 'description' => 'Кількість (максимум 3)'],
                            'sort_by' => [
                                'type' => 'string',
                                'enum' => ['relevance', 'popularity', 'price_asc', 'price_desc'],
                                'description' => 'Сортування: "popularity" для "що беруть/хіти/топ"',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_popular_products',
                    'description' => 'Хіти продажів. БЕЗ category = ТОП ВСЬОГО МАГАЗИНУ. З category = топ категорії.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'Категорія (тільки якщо явно вказана)'],
                            'limit' => ['type' => 'integer', 'description' => 'Кількість (максимум 3)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_product_details',
                    'description' => 'Детальна інформація про товар за артикулом.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => ['type' => 'string', 'description' => 'Артикул товару'],
                        ],
                        'required' => ['article'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_order_status',
                    'description' => 'Перевірити статус ІСНУЮЧОГО замовлення. Використовуй ТІЛЬКИ коли клієнт питає "де моє замовлення", "статус замовлення №123". НЕ використовуй для нових замовлень або коли клієнт просто надсилає номер телефону!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id' => ['type' => 'string', 'description' => 'Номер замовлення'],
                            'phone' => ['type' => 'string', 'description' => 'Телефон для пошуку замовлень клієнта (якщо клієнт хоче перевірити статус)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_categories',
                    'description' => 'Список категорій товарів.',
                    'parameters' => ['type' => 'object', 'properties' => (object)[]],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_brands',
                    'description' => 'Список брендів.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'Категорія для фільтрації'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_available_sizes',
                    'description' => 'Дізнатися які розміри є в наявності. ОБОВ\'ЯЗКОВО при питаннях про розміри!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => ['type' => 'string', 'description' => 'Артикул товару'],
                        ],
                        'required' => ['article'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'recommend_size',
                    'description' => 'Підібрати розмір ECWCS за параметрами клієнта. ВАЖЛИВО: при вазі 90+ кг рекомендуй L/XL, при 100+ кг — XL/XXL. Зріст 185+ і вага 100+ = XL/L або XXL/L! Артикул НЕ обов\'язковий - можна підібрати загальний розмір ECWCS.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => ['type' => 'string', 'description' => 'Артикул товару (опціонально - для перевірки наявності розміру)'],
                            'height' => ['type' => 'integer', 'description' => 'Зріст в см'],
                            'weight' => ['type' => 'integer', 'description' => 'Вага в кг (ОБОВ\'ЯЗКОВО передавай якщо є!)'],
                            'chest' => ['type' => 'integer', 'description' => 'Обхват грудей в см'],
                            'waist' => ['type' => 'integer', 'description' => 'Обхват талії в см'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
        ];
    }

    // ============================================================
    // TOOL EXECUTION
    // ============================================================

    /**
     * Execute a tool and return result.
     */
    protected function executeTool(string $name, array $args): array
    {
        return match ($name) {
            'search_products' => $this->toolSearchProducts($args),
            'get_popular_products' => $this->toolGetPopularProducts($args),
            'get_product_details' => $this->toolGetProductDetails($args),
            'get_order_status' => $this->toolGetOrderStatus($args),
            'get_categories' => $this->toolGetCategories(),
            'get_brands' => $this->toolGetBrands($args),
            'get_available_sizes' => $this->toolGetAvailableSizes($args),
            'recommend_size' => $this->toolRecommendSize($args),
            default => ['error' => 'Unknown tool'],
        };
    }

    /**
     * Tool: Search products.
     */
    protected function toolSearchProducts(array $args): array
    {
        $query = $args['query'] ?? '';
        $limit = min($args['limit'] ?? 3, 3);
        $sortBy = $args['sort_by'] ?? 'relevance';

        Log::info('BaseAgent::toolSearchProducts', ['args' => $args, 'sort_by' => $sortBy]);

        $filters = [];
        if (!empty($args['price_min'])) $filters['price_min'] = (float)$args['price_min'];
        if (!empty($args['price_max'])) $filters['price_max'] = (float)$args['price_max'];
        if (!empty($args['brand'])) $filters['brand'] = $args['brand'];
        if ($sortBy !== 'relevance') $filters['sort_by'] = $sortBy;

        // Request more to have room after filtering
        $requestLimit = $limit * 3 + count($this->shownProductIds);
        $results = $this->searchTool->search($query, $filters, $requestLimit);

        // Filter by exclude text
        if (!empty($args['exclude']) && !empty($results)) {
            $exclude = mb_strtolower($args['exclude']);
            $results = array_filter($results, fn($p) => !str_contains(mb_strtolower($p['title'] ?? ''), $exclude));
            $results = array_values($results);
        }

        // Filter by product_type
        if (!empty($args['product_type']) && !empty($results)) {
            $productType = mb_strtolower($args['product_type']);
            $searchTerms = $this->getProductTypeSynonyms($productType);
            $results = array_filter($results, function ($p) use ($searchTerms) {
                $searchText = mb_strtolower(($p['ai_product_type'] ?? '') . ' ' . ($p['title'] ?? '') . ' ' . ($p['category_path'] ?? ''));
                foreach ($searchTerms as $term) {
                    if (str_contains($searchText, $term)) return true;
                }
                return false;
            });
            $results = array_values($results);
        }

        // Filter by color
        if (!empty($args['color']) && !empty($results)) {
            $color = mb_strtolower($args['color']);
            $results = array_filter($results, fn($p) => str_contains(mb_strtolower(($p['title'] ?? '') . ' ' . ($p['color'] ?? '')), $color));
            $results = array_values($results);
        }

        // Exclude already shown products ONLY if explicitly requested (for "покажи ще")
        // By default, allow showing same products again (for repeated queries)
        $excludeShown = $args['exclude_shown'] ?? false;
        if ($excludeShown && !empty($this->shownProductIds) && !empty($results)) {
            $results = array_filter($results, fn($p) => !in_array((int)($p['id'] ?? 0), $this->shownProductIds));
            $results = array_values($results);
        }

        $results = array_slice($results, 0, $limit);

        // Get full product cards with images
        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $tenantId = $this->searchTool->getCurrentTenantId();
            $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
            if (!empty($cards)) $results = $cards;
        }

        return ['products' => $results, 'count' => count($results), 'query' => $query];
    }

    /**
     * Tool: Get popular products.
     */
    protected function toolGetPopularProducts(array $args): array
    {
        $category = $args['category'] ?? null;
        $limit = min($args['limit'] ?? 3, 3);
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'popular_products_v7:' . ($tenantId ?? 'all') . ':' . ($category ?? 'all') . ':' . $limit;

        return Cache::remember($cacheKey, 300, function () use ($category, $limit, $tenantId) {
            $products = [];

            $filterProduct = function ($p) {
                $size = strtolower($p['size'] ?? '');
                $title = strtolower($p['title'] ?? '');
                $price = (float)($p['price'] ?? 0);
                if (preg_match('/\b(50|51|52|53|54|55|xxxl|xxxxl)\b/i', $size . ' ' . $title)) return false;
                if ($price > 20000) return false;
                if (!($p['in_stock'] ?? false)) return false;
                return true;
            };

            // Check for real sales data
            $salesQuery = Product::where('orders_count', '>', 0);
            if ($tenantId) $salesQuery->where('tenant_id', $tenantId);
            $hasOrdersData = $salesQuery->exists();

            if ($hasOrdersData) {
                $query = Product::where('in_stock', true)->where('orders_count', '>', 0)->where('quantity', '>', 0);
                if ($tenantId) $query->where('tenant_id', $tenantId);
                if ($category) {
                    $query->where(function ($q) use ($category) {
                        $q->where('category_path', 'like', "%{$category}%")
                            ->orWhere('title', 'like', "%{$category}%")
                            ->orWhere('search_index', 'like', "%{$category}%");
                    });
                }
                $topSellers = $query->orderBy('orders_count', 'desc')->take($limit * 3)->get();

                foreach ($topSellers as $p) {
                    $item = [
                        'id' => $p->id, 'article' => $p->article, 'title' => $p->title,
                        'price' => $p->price, 'in_stock' => $p->in_stock, 'size' => $p->size,
                        'orders_count' => $p->orders_count, 'popularity' => $p->popularity,
                    ];
                    if ($filterProduct($item)) $products[] = $item;
                    if (count($products) >= $limit) break;
                }
            }

            // Fallback: use dynamic categories from tenant's catalog
            if (count($products) < $limit) {
                if ($category) {
                    $results = $this->searchTool->search($category, [], $limit * 3);
                    $results = array_filter($results, $filterProduct);
                    usort($results, fn($a, $b) => (($b['popularity'] ?? 0) + (($b['orders_count'] ?? 0) * 10)) <=> (($a['popularity'] ?? 0) + (($a['orders_count'] ?? 0) * 10)));
                    $existingIds = array_column($products, 'id');
                    foreach ($results as $r) {
                        if (!in_array($r['id'], $existingIds)) {
                            $products[] = $r;
                            if (count($products) >= $limit) break;
                        }
                    }
                } else {
                    // Get top categories dynamically from tenant's products
                    $popularQueries = $this->getTopCategoriesForTenant($tenantId, 5);
                    
                    // If no categories found, use generic fallback
                    if (empty($popularQueries)) {
                        $popularQueries = ['товар', 'новинка', 'акція'];
                    }
                    
                    $existingIds = array_column($products, 'id');
                    foreach ($popularQueries as $q) {
                        $results = $this->searchTool->search($q, [], 10);
                        $results = array_filter($results, $filterProduct);
                        if (!empty($results)) {
                            usort($results, fn($a, $b) => abs(($a['price'] ?? 0) - 3000) <=> abs(($b['price'] ?? 0) - 3000));
                            $best = array_values($results)[0];
                            if (!in_array($best['id'], $existingIds)) {
                                $products[] = $best;
                                $existingIds[] = $best['id'];
                            }
                        }
                        if (count($products) >= $limit) break;
                    }
                }
            }
            
            // Last resort: just get any in-stock products from tenant
            if (count($products) < $limit) {
                $query = Product::where('in_stock', true)->where('quantity', '>', 0);
                if ($tenantId) $query->where('tenant_id', $tenantId);
                $anyProducts = $query->orderByDesc('updated_at')->take(($limit - count($products)) * 2)->get();
                
                $existingIds = array_column($products, 'id');
                foreach ($anyProducts as $p) {
                    $item = [
                        'id' => $p->id, 'article' => $p->article, 'title' => $p->title,
                        'price' => $p->price, 'in_stock' => $p->in_stock, 'size' => $p->size,
                        'orders_count' => $p->orders_count ?? 0, 'popularity' => $p->popularity ?? 0,
                    ];
                    if ($filterProduct($item) && !in_array($p->id, $existingIds)) {
                        $products[] = $item;
                        $existingIds[] = $p->id;
                    }
                    if (count($products) >= $limit) break;
                }
            }

            // Get full cards with images
            if (!empty($products)) {
                $ids = array_column($products, 'id');
                $tenantId = $this->searchTool->getCurrentTenantId();
                $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
                if (!empty($cards)) $products = $cards;
            }

            return ['products' => array_slice($products, 0, $limit), 'count' => count($products)];
        });
    }

    /**
     * Get top categories for a tenant to use as fallback queries.
     */
    protected function getTopCategoriesForTenant(?int $tenantId, int $limit = 5): array
    {
        $cacheKey = 'tenant_top_categories:' . ($tenantId ?? 'all') . ':' . $limit;
        
        return Cache::remember($cacheKey, 600, function () use ($tenantId, $limit) {
            $query = Product::where('in_stock', true);
            if ($tenantId) $query->where('tenant_id', $tenantId);
            
            $categories = $query
                ->selectRaw('category_path, count(*) as cnt')
                ->whereNotNull('category_path')
                ->where('category_path', '!=', '')
                ->groupBy('category_path')
                ->orderByDesc('cnt')
                ->limit($limit * 2)
                ->pluck('category_path')
                ->toArray();
            
            // Extract last segment of category path as search query
            $queries = [];
            foreach ($categories as $cat) {
                $parts = explode('/', $cat);
                $lastPart = trim(end($parts));
                if (!empty($lastPart) && mb_strlen($lastPart) > 2 && !in_array($lastPart, $queries)) {
                    $queries[] = $lastPart;
                }
                if (count($queries) >= $limit) break;
            }
            
            return $queries;
        });
    }

    /**
     * Tool: Get product details.
     */
    protected function toolGetProductDetails(array $args): array
    {
        $article = $args['article'] ?? '';
        if (empty($article)) return ['error' => 'Article required'];

        $tenantId = $this->searchTool->getCurrentTenantId();
        $query = Product::where('article', $article);
        if ($tenantId) $query->where('tenant_id', $tenantId);
        $product = $query->first();
        if (!$product) return ['error' => 'Product not found'];

        $images = $this->extractProductImages($product);

        return [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'article' => $product->article,
                'price' => $product->price,
                'price_old' => $product->price_old,
                'brand' => $product->brand,
                'in_stock' => $product->in_stock,
                'link' => $product->link,
                'images' => $images,
                'category_path' => $product->category_path,
            ],
        ];
    }

    /**
     * Tool: Get order status.
     */
    protected function toolGetOrderStatus(array $args): array
    {
        $orderId = $args['order_id'] ?? null;
        $phone = $args['phone'] ?? null;

        // Check if order search is available
        if (!$this->orderSearchService->isAvailable()) {
            return ['error' => 'Пошук замовлень тимчасово недоступний.'];
        }

        try {
            $result = $this->orderSearchService->search([
                'order_id' => $orderId,
                'phone' => $phone,
                'limit' => 5,
            ]);

            if (!empty($result['orders'])) {
                return $result['total'] === 1 
                    ? ['order' => $result['orders'][0]]
                    : ['orders' => $result['orders'], 'count' => $result['total']];
            }

            return ['error' => 'Замовлення не знайдено. Перевірте номер або телефон.'];
        } catch (\Exception $e) {
            Log::error('toolGetOrderStatus error', ['error' => $e->getMessage()]);
            return ['error' => 'Не вдалося перевірити замовлення. Спробуйте пізніше.'];
        }
    }

    /**
     * Tool: Get categories.
     */
    protected function toolGetCategories(): array
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'categories_list:' . ($tenantId ?? 'all');

        return Cache::remember($cacheKey, 3600, function () use ($tenantId) {
            $query = Category::whereNotNull('path');
            if ($tenantId) $query->where('tenant_id', $tenantId);
            $categories = $query->orderBy('path')->pluck('path')->unique()->values()->toArray();
            return ['categories' => $categories, 'count' => count($categories)];
        });
    }

    /**
     * Tool: Get brands.
     */
    protected function toolGetBrands(array $args): array
    {
        $category = $args['category'] ?? null;
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'brands_list:' . ($tenantId ?? 'all') . ':' . ($category ?? 'all');

        return Cache::remember($cacheKey, 3600, function () use ($category, $tenantId) {
            $query = Brand::query();
            if ($tenantId) $query->where('tenant_id', $tenantId);
            if ($category) $query->where('categories', 'like', "%{$category}%");
            $brands = $query->orderBy('name')->pluck('name')->toArray();
            return ['brands' => $brands, 'count' => count($brands)];
        });
    }

    /**
     * Tool: Get available sizes for a product.
     */
    protected function toolGetAvailableSizes(array $args): array
    {
        $article = $args['article'] ?? '';
        if (empty($article)) return ['error' => 'Article required'];

        $tenantId = $this->searchTool->getCurrentTenantId();
        $query = Product::query();
        if ($tenantId) $query->where('tenant_id', $tenantId);

        // Find main product
        $product = (clone $query)->where('article', $article)->first();
        if (!$product) $product = (clone $query)->where('id', $article)->first();
        if (!$product) return ['error' => 'Product not found'];

        // Find all size variants
        $parentArticle = $product->parent_article ?? $product->article;
        $variants = (clone $query)
            ->where(function ($q) use ($parentArticle, $product) {
                $q->where('parent_article', $parentArticle)
                    ->orWhere('article', $parentArticle)
                    ->orWhere('parent_article', $product->article);
            })
            ->where('in_stock', true)
            ->where('quantity', '>', 0)
            ->orderBy('size')
            ->get();

        $sizes = $variants->map(fn($v) => [
            'size' => $v->size,
            'article' => $v->article,
            'quantity' => $v->quantity,
            'price' => $v->price,
        ])->values()->toArray();

        return [
            'product' => $product->title,
            'sizes' => $sizes,
            'count' => count($sizes),
        ];
    }

    /**
     * Tool: Recommend size based on measurements.
     */
    protected function toolRecommendSize(array $args): array
    {
        $article = $args['article'] ?? '';
        $height = $args['height'] ?? null;
        $weight = $args['weight'] ?? null;
        $chest = $args['chest'] ?? null;
        $waist = $args['waist'] ?? null;

        // If article provided, get available sizes first
        $availableSizes = [];
        $productTitle = 'ECWCS одяг';
        
        if (!empty($article)) {
            $sizesResult = $this->toolGetAvailableSizes(['article' => $article]);
            if (isset($sizesResult['error'])) {
                // Article not found, continue without it - just recommend ECWCS size
                $availableSizes = [];
            } else {
                $availableSizes = $sizesResult['sizes'] ?? [];
                $productTitle = $sizesResult['product'] ?? 'товар';
            }
        }

        // Estimate chest from weight if not provided (rough formula for tactical gear)
        // Average male: weight/height ratio affects chest size
        if (!$chest && $weight && $height) {
            // BMI-based chest estimation for men
            $bmi = $weight / (($height / 100) ** 2);
            // Rough estimation: BMI 18-22 = ~90-95cm, 22-26 = ~100-105cm, 26-30 = ~110-115cm, 30+ = ~120+cm
            if ($bmi < 22) {
                $chest = 90 + ($bmi - 18) * 2;
            } elseif ($bmi < 26) {
                $chest = 98 + ($bmi - 22) * 3;
            } elseif ($bmi < 30) {
                $chest = 110 + ($bmi - 26) * 3;
            } else {
                $chest = 122 + ($bmi - 30) * 2;
            }
            $chest = (int)round($chest);
        }
        
        // If only weight provided, estimate chest directly
        if (!$chest && $weight) {
            // Simple formula: chest ≈ weight * 1.05 + 10 (rough average for men)
            // 70kg → ~84cm, 85kg → ~99cm, 100kg → ~115cm, 115kg → ~131cm
            $chest = (int)round($weight * 1.05 + 10);
        }

        // ECWCS size chart (US Army standard)
        $ecwcsSizes = [
            'XS/XS' => ['height' => [150, 157], 'chest' => [79, 86], 'waist' => [64, 71]],
            'XS/S' => ['height' => [157, 165], 'chest' => [79, 86], 'waist' => [64, 71]],
            'S/XS' => ['height' => [150, 157], 'chest' => [86, 94], 'waist' => [71, 79]],
            'S/S' => ['height' => [157, 165], 'chest' => [86, 94], 'waist' => [71, 79]],
            'S/R' => ['height' => [165, 175], 'chest' => [86, 94], 'waist' => [71, 79]],
            'S/L' => ['height' => [175, 183], 'chest' => [86, 94], 'waist' => [71, 79]],
            'M/S' => ['height' => [157, 165], 'chest' => [94, 102], 'waist' => [79, 86]],
            'M/R' => ['height' => [165, 175], 'chest' => [94, 102], 'waist' => [79, 86]],
            'M/L' => ['height' => [175, 183], 'chest' => [94, 102], 'waist' => [79, 86]],
            'L/S' => ['height' => [157, 165], 'chest' => [102, 112], 'waist' => [86, 94]],
            'L/R' => ['height' => [165, 175], 'chest' => [102, 112], 'waist' => [86, 94]],
            'L/L' => ['height' => [175, 183], 'chest' => [102, 112], 'waist' => [86, 94]],
            'XL/R' => ['height' => [165, 175], 'chest' => [112, 122], 'waist' => [94, 102]],
            'XL/L' => ['height' => [175, 183], 'chest' => [112, 122], 'waist' => [94, 102]],
            'XXL/R' => ['height' => [165, 175], 'chest' => [122, 132], 'waist' => [102, 112]],
            'XXL/L' => ['height' => [175, 183], 'chest' => [122, 132], 'waist' => [102, 112]],
        ];

        // Find best matching size
        $recommendation = null;
        $bestScore = PHP_INT_MAX;
        $warnings = [];

        // If we have specific available sizes, filter by them. Otherwise check all ECWCS sizes.
        $hasAvailableSizes = !empty($availableSizes);

        foreach ($ecwcsSizes as $size => $ranges) {
            $score = 0;

            // Check if this size is available (only filter if we have specific sizes)
            if ($hasAvailableSizes) {
                $sizePrefix = explode('/', $size)[0]; // Get XS, S, M, L, XL, XXL part
                $availableSize = collect($availableSizes)->first(function($s) use ($sizePrefix, $size) {
                    $sizeStr = $s['size'] ?? '';
                    // Match exact size or prefix match
                    return stripos($sizeStr, $size) !== false || stripos($sizeStr, $sizePrefix) !== false;
                });
                if (!$availableSize) continue;
            }

            // Calculate score - lower is better
            // Chest/body size is MOST important (weight = 4)
            if ($chest) {
                $chestMid = ($ranges['chest'][0] + $ranges['chest'][1]) / 2;
                // Penalize heavily if chest is larger than max range
                if ($chest > $ranges['chest'][1]) {
                    $score += ($chest - $ranges['chest'][1]) * 5; // Heavy penalty for too small
                } else {
                    $score += abs($chest - $chestMid) * 4;
                }
            }
            
            // Height is secondary (weight = 2)
            if ($height) {
                $heightMid = ($ranges['height'][0] + $ranges['height'][1]) / 2;
                $score += abs($height - $heightMid) * 2;
            }
            
            // Waist if provided (weight = 3)
            if ($waist) {
                $waistMid = ($ranges['waist'][0] + $ranges['waist'][1]) / 2;
                if ($waist > $ranges['waist'][1]) {
                    $score += ($waist - $ranges['waist'][1]) * 4;
                } else {
                    $score += abs($waist - $waistMid) * 3;
                }
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $recommendation = $size;
            }
        }

        // Add warnings
        if ($chest && $chest > 130) {
            $warnings[] = 'Великий обхват грудей — рекомендуємо уточнити у менеджера';
        }
        if ($weight && $weight > 110) {
            $warnings[] = 'При вазі 110+ кг можливо знадобиться XXL — зверніть увагу на таблицю розмірів';
        }
        
        // Calculate estimated chest for response
        $estimatedChest = $chest;
        
        // Default to XL/L for heavy people without better match
        if (!$recommendation && $weight && $weight >= 100) {
            $recommendation = 'XL/L';
        } elseif (!$recommendation && $weight && $weight >= 85) {
            $recommendation = 'L/L';
        }

        // Extract base size (XS, S, M, L, XL, XXL) from ECWCS notation (XL/L -> XL)
        $baseSize = $recommendation ? explode('/', $recommendation)[0] : 'L';
        
        // Build user-friendly size explanation
        $sizeExplanation = match($baseSize) {
            'XS' => 'XS (або XS-Short, XS-Regular)',
            'S' => 'S (або S-Short, S-Regular, S-Long)',
            'M' => 'M (або M-Short, M-Regular, M-Long)',
            'L' => 'L (або L-Short, L-Regular, L-Long)',
            'XL' => 'XL (або XL-Regular, XL-Long)',
            'XXL' => 'XXL (або XXL-Regular, XXL-Long)',
            default => $baseSize,
        };

        $result = [
            'status' => 'success',
            'message' => "Рекомендований розмір: {$sizeExplanation}. По системі ECWCS (американська): {$recommendation}",
            'product' => $productTitle,
            'recommended_size' => $recommendation ?? 'L/R',
            'simple_size' => $baseSize,
            'size_explanation' => $sizeExplanation,
            'available_sizes' => $availableSizes,
            'warnings' => $warnings,
            'estimated_chest' => $estimatedChest ? "~{$estimatedChest} см (оцінка за вагою)" : null,
            'note' => "Якщо товар має просту розмітку (S, M, L, XL) — обирайте {$baseSize}. Якщо є позначення росту (Short/Regular/Long або S/R/L) — обирайте {$recommendation}.",
        ];
        
        return $result;
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    /**
     * Extract images from product.
     */
    protected function extractProductImages(Product $product): array
    {
        $images = [];

        // 1. Try raw['pictures'] (Horoshop format)
        if ($product->raw && is_array($product->raw) && !empty($product->raw['pictures'])) {
            $images = collect($product->raw['pictures'])
                ->map(fn($pic) => is_array($pic) ? ($pic['url'] ?? null) : $pic)
                ->filter()->values()->toArray();
        }

        // 2. Try raw['images']
        if (empty($images) && $product->raw && is_array($product->raw) && !empty($product->raw['images'])) {
            $imgs = $product->raw['images'];
            if (is_array($imgs)) {
                $images = collect($imgs)
                    ->map(fn($img) => is_array($img) ? ($img['url'] ?? $img['src'] ?? null) : $img)
                    ->filter()->values()->toArray();
            }
        }

        // 3. Fallback to images field
        if (empty($images) && $product->images) {
            $imgs = $product->images;
            if (is_string($imgs)) $imgs = json_decode($imgs, true) ?: [$imgs];
            if (is_array($imgs)) $images = array_values(array_filter($imgs));
        }

        // 4. Single image fallbacks
        if (empty($images) && $product->raw && is_array($product->raw)) {
            if (!empty($product->raw['image'])) $images = [$product->raw['image']];
            elseif (!empty($product->raw['main_image'])) $images = [$product->raw['main_image']];
        }

        return $images;
    }

    /**
     * Get product type synonyms from DB.
     */
    protected function getProductTypeSynonyms(string $productType): array
    {
        $cacheKey = 'product_type_synonyms:' . md5($productType);

        return Cache::remember($cacheKey, 3600, function () use ($productType) {
            $synonyms = ProductSynonym::where('product_type', $productType)
                ->orWhere('synonym', $productType)
                ->pluck('synonym')
                ->toArray();

            return array_unique(array_merge([$productType], $synonyms));
        });
    }

    /**
     * Deduplicate products by ID.
     */
    protected function dedupeProducts(array $products): array
    {
        $seen = [];
        $result = [];

        foreach ($products as $product) {
            $id = $product['id'] ?? null;
            if ($id && !isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $product;
            }
        }

        return $result;
    }

    /**
     * Detect if message is a trigger query.
     */
    protected function detectTriggerQuery(string $message): bool
    {
        $triggerPhrases = [
            'допоможіть з товаром',
            'допоможи з товаром',
            'цікавить товар',
            'покажи топ товари в категорії',
            'хочу дізнатись більше про',
        ];

        $lowerMessage = mb_strtolower($message);
        foreach ($triggerPhrases as $phrase) {
            if (str_starts_with($lowerMessage, $phrase)) {
                Log::info('BaseAgent: detected trigger query', ['message' => $message]);
                return true;
            }
        }

        return false;
    }

    /**
     * Generate CTA outro for trigger queries.
     * Now checks if GPT already included a size question to avoid duplication.
     * Also detects "top products in category" queries to avoid irrelevant size/color questions.
     */
    protected function generateTriggerOutro(array $products, string $gptResponse = '', string $originalQuery = ''): string
    {
        if (empty($products)) return 'Є питання? Допоможу з вибором!';

        // For "top products in category" queries, don't ask about size/color - products are diverse!
        $lowerQuery = mb_strtolower($originalQuery);
        $isTopProductsQuery = str_contains($lowerQuery, 'топ товари') 
            || str_contains($lowerQuery, 'популярні товари')
            || str_contains($lowerQuery, 'top products');

        $firstProduct = $products[0];
        $quantity = $firstProduct['quantity'] ?? 0;

        // Check if GPT already asked about size/color to avoid duplication
        $lowerResponse = mb_strtolower($gptResponse);
        $alreadyAskedSize = str_contains($lowerResponse, 'розмір') || str_contains($lowerResponse, 'size');
        $alreadyAskedColor = str_contains($lowerResponse, 'колір') || str_contains($lowerResponse, 'color');
        $alreadyAskedGeneric = str_contains($lowerResponse, 'цікавить щось конкретне')
            || str_contains($lowerResponse, 'є питання');

        // For diverse product queries (top products), use generic CTA
        if ($isTopProductsQuery || $alreadyAskedGeneric) {
            return 'Цікавить щось конкретне? Допоможу підібрати!';
        }

        $hasMultipleSizes = false;
        $hasMultipleColors = false;

        foreach ($products as $p) {
            if (!empty($p['size_variants']) && count($p['size_variants']) > 1) $hasMultipleSizes = true;
            if (!empty($p['color_variants']) && count($p['color_variants']) > 1) $hasMultipleColors = true;
        }

        if ($hasMultipleSizes && !$alreadyAskedSize) {
            return 'Який розмір/варіант вам потрібен? Допоможу підібрати!';
        }
        if ($hasMultipleColors && !$alreadyAskedColor) {
            return 'Який колір вам більше підходить?';
        }
        if ($quantity > 0 && $quantity <= 3) {
            return "Залишилось лише {$quantity} шт. в наявності. Оформлюємо?";
        }

        return 'Оформлюємо замовлення? Або є питання?';
    }

    /**
     * Parse GPT structured JSON response.
     */
    protected function parseStructuredResponse(string $responseText, array $allProducts): array
    {
        $json = null;

        // Extract JSON from response
        if (preg_match('/\{[\s\S]*\}/u', $responseText, $matches)) {
            $json = json_decode($matches[0], true);
        }

        // Build products by article index
        $productsByArticle = [];
        foreach ($allProducts as $p) {
            $productsByArticle[$p['article']] = $p;
        }

        // If valid JSON with products
        if ($json && isset($json['products']) && is_array($json['products'])) {
            $messages = [];

            if (!empty($json['intro'])) {
                $messages[] = ['type' => 'text', 'content' => $json['intro']];
            }

            $orderedProducts = [];
            foreach ($json['products'] as $item) {
                $article = $item['article'] ?? '';
                $comment = $item['comment'] ?? '';

                $product = $productsByArticle[$article] ?? null;
                if (!$product) {
                    foreach ($productsByArticle as $a => $p) {
                        if (str_contains($a, $article) || str_contains($article, $a)) {
                            $product = $p;
                            break;
                        }
                    }
                }

                if ($product) {
                    $messages[] = ['type' => 'product', 'product' => $product, 'comment' => $comment];
                    $orderedProducts[] = $product;
                }
            }

            if (!empty($json['outro'])) {
                $messages[] = ['type' => 'text', 'content' => $json['outro']];
            }

            return [
                'intro' => $json['intro'] ?? 'Ось що я знайшов:',
                'outro' => $json['outro'] ?? null,
                'products' => !empty($orderedProducts) ? $orderedProducts : array_slice($allProducts, 0, 5),
                'messages' => $messages,
            ];
        }

        // Handle JSON with 'text' key
        if ($json && isset($json['text'])) {
            $messages = [['type' => 'text', 'content' => $json['text']]];
            foreach (array_slice($allProducts, 0, 5) as $product) {
                $messages[] = ['type' => 'product', 'product' => $product, 'comment' => ''];
            }
            return [
                'intro' => $json['text'],
                'outro' => null,
                'products' => array_slice($allProducts, 0, 5),
                'messages' => $messages,
            ];
        }

        // Fallback: plain text response
        $messages = [];
        if ($responseText) $messages[] = ['type' => 'text', 'content' => $responseText];
        foreach (array_slice($allProducts, 0, 5) as $product) {
            $messages[] = ['type' => 'product', 'product' => $product, 'comment' => ''];
        }

        return [
            'intro' => $responseText ?: 'Ось що я знайшов:',
            'outro' => null,
            'products' => array_slice($allProducts, 0, 5),
            'messages' => $messages,
        ];
    }

    /**
     * Detect if the current message is a fresh/new query (not a follow-up).
     * A fresh query should NOT use previous context.
     */
    protected function isFreshQuery(string $message, array $history): bool
    {
        $msg = mb_strtolower(trim($message));
        $wordCount = count(preg_split('/\s+/u', $msg));
        
        // Short confirmations are NOT fresh queries - they're follow-ups
        $confirmations = ['так', 'ні', 'добре', 'ок', 'окей', 'зрозумів', 'дякую', 'ще', 'інші', 'більше', 'показуй'];
        if (in_array($msg, $confirmations) || $wordCount <= 2 && preg_match('/^(ще|інші|більше|показуй)/u', $msg)) {
            return false;
        }
        
        // If message contains clear category/brand without modifiers - it's a fresh query
        // Brands that indicate a fresh brand-specific search
        $brands = ['defcon', 'm-tac', 'mtac', 'helikon', 'pentagon', 'velmet', '5.11', 'uf pro', 'condor', 
                   'direct action', 'crye', 'ops-core', 'emerson', 'wartech', 'архангел', 'p1g', 'a-tac', 'hrt',
                   'mechanix', 'oakley', 'salomon', 'lowa', 'meindl', 'haix'];
        
        foreach ($brands as $brand) {
            // If message is ONLY the brand name (with maybe small variations)
            if (preg_match('/^' . preg_quote($brand, '/') . '\s*\d*$/ui', $msg)) {
                return true;
            }
        }
        
        // Clear new category queries (single category word without modifiers from context)
        $categories = ['плитоноски', 'шоломи', 'берці', 'рюкзаки', 'куртки', 'штани', 'футболки', 
                       'підсумки', 'рукавиці', 'окуляри', 'ремені', 'бронеплати', 'жилети',
                       'термобілизна', 'фліс', 'софтшел', 'шапки', 'балаклави', 'носки'];
        
        foreach ($categories as $cat) {
            if (preg_match('/^' . preg_quote($cat, '/') . '$/ui', $msg)) {
                return true;
            }
        }
        
        // If there's NO history, it's always fresh
        if (empty($history)) {
            return true;
        }
        
        // If user explicitly starts a new topic with "покажи", "знайди", "шукаю" + different category
        // This is a heuristic - if the new query has a clear product type and it's different from context
        $lastUserMsg = '';
        foreach (array_reverse($history) as $h) {
            if (($h['role'] ?? '') === 'user') {
                $lastUserMsg = mb_strtolower($h['content'] ?? '');
                break;
            }
        }
        
        // Check if current message has a clear search intent with new category
        if (preg_match('/^(покажи|знайди|шукаю|є|маєте)\s+/ui', $msg)) {
            // Extract what they're searching for now vs before
            // If it's clearly different - fresh query
            return true;
        }
        
        return false;
    }

    /**
     * Extract conversation context from history.
     */
    protected function extractConversationContext(array $history): string
    {
        if (empty($history)) return '';

        $contextParts = [];
        $productCategories = [];
        $shownProducts = [];
        $sizes = [];
        $colors = [];
        $brands = [];
        $priceRange = [];
        $userQuestions = [];

        // Category patterns for user queries
        $userCategoryPatterns = [
            'плитоноск' => 'плитоноски',
            'шолом|каск' => 'шоломи',
            'берц|черевик' => 'берці',
            'рюкзак' => 'рюкзаки',
            'підсумок|підсумк' => 'підсумки',
            'куртк' => 'куртки',
            'штан' => 'штани',
            'футболк' => 'футболки',
            'жилет|розвантаж' => 'жилети',
            'бронеплас|плит' => 'бронеплати',
            'рукавиц|рукавич|перчатк' => 'рукавиці',
            'окуляр' => 'окуляри',
            'наколін|налокіт' => 'захист',
            'ремен|ремін|пояс' => 'ремені',
            'патч|шеврон|нашивк' => 'патчі/шеврони',
            'медик|аптечк|турнікет|бандаж|ifak' => 'медицина',
            'ліхтар' => 'ліхтарі',
            'ніж|мультитул' => 'ножі',
            'кепк|бейсболк|панам|шапк' => 'головні убори',
        ];

        foreach ($history as $msg) {
            $content = $msg['content'] ?? '';
            $role = $msg['role'] ?? '';

            // Collect last 3 user questions for context
            if ($role === 'user' && mb_strlen($content) > 3) {
                $userQuestions[] = mb_substr($content, 0, 100);
                
                // Extract categories from user queries (not just shown products)
                foreach ($userCategoryPatterns as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $content)) {
                        $productCategories[] = $category;
                    }
                }
            }

            // Extract shown products from [Показані товари: ...]
            if (preg_match('/\[Показані товари: (.+?)\]/u', $content, $matches)) {
                $products = $matches[1];
                $shownProducts[] = $products;
                
                // Extract categories from product names
                $categoryPatterns = [
                    'плитоноск' => 'плитоноски',
                    'шолом|каск' => 'шоломи',
                    'берц|черевик' => 'берці',
                    'рюкзак' => 'рюкзаки',
                    'підсумок|підсумк' => 'підсумки',
                    'куртк' => 'куртки',
                    'штан' => 'штани',
                    'футболк' => 'футболки',
                    'жилет|розвантаж' => 'жилети',
                    'бронеплас' => 'бронеплати',
                    'рукавиц|рукавич|перчатк' => 'рукавиці',
                    'окуляр' => 'окуляри',
                    'наколін|налокіт' => 'захист',
                    'ремен|ремін|пояс' => 'ремені',
                    'патч|шеврон|нашивк' => 'патчі/шеврони',
                    'медик|аптечк|турнікет|бандаж|ifak' => 'медицина',
                    'ліхтар' => 'ліхтарі',
                    'ніж|мультитул' => 'ножі',
                    'кепк|бейсболк|панам|шапк' => 'головні убори',
                ];
                foreach ($categoryPatterns as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $products)) {
                        $productCategories[] = $category;
                    }
                }
            }

            // Extract sizes (including numeric for shoes)
            if (preg_match_all('/\b(XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|[3-4]\d)\b/i', $content, $sizeMatches)) {
                foreach ($sizeMatches[1] as $size) {
                    $sizes[] = strtoupper($size);
                }
            }

            // Extract colors (expanded list)
            $colorPatterns = [
                'чорн' => 'чорний',
                'олив' => 'олива',
                'мультикам|multicam' => 'мультикам',
                'койот|coyote' => 'койот',
                'піксель' => 'піксель',
                'хакі|khaki' => 'хакі',
                'ranger green|рейнджер грін' => 'Ranger Green',
                'коричнев' => 'коричневий',
                'сір|grey|gray' => 'сірий',
                'біл|white' => 'білий',
                'зелен|green' => 'зелений',
                'синій|синя|blue' => 'синій',
                'атакс|a-tacs' => 'A-TACS',
            ];
            foreach ($colorPatterns as $pattern => $color) {
                if (preg_match("/($pattern)/ui", $content)) {
                    $colors[] = $color;
                }
            }

            // Extract brands
            $brandPatterns = ['M-TAC', 'Helikon', 'Pentagon', 'Velmet', '5.11', 'UF PRO', 'Condor', 'Direct Action', 
                'Crye', 'Ops-Core', 'Emerson', 'Wartech', 'Архангел', 'P1G', 'A-TAC', 'HRT'];
            foreach ($brandPatterns as $brand) {
                if (stripos($content, $brand) !== false) {
                    $brands[] = $brand;
                }
            }

            // Extract price preferences
            if (preg_match('/(бюджетн|недорог|дешев)/ui', $content)) {
                $priceRange[] = 'бюджетний';
            }
            if (preg_match('/(преміум|дорог|якісн|топов)/ui', $content)) {
                $priceRange[] = 'преміум';
            }
            if (preg_match('/до\s*(\d+)\s*(грн|₴)/ui', $content, $priceMatch)) {
                $priceRange[] = 'до ' . $priceMatch[1] . ' грн';
            }
        }

        // Build rich context
        if (!empty($productCategories)) {
            $contextParts[] = 'Шукає: ' . implode(', ', array_unique($productCategories));
        }
        if (!empty($shownProducts)) {
            // Only last 2 shown product sets
            $recentShown = array_slice(array_unique($shownProducts), -2);
            $contextParts[] = 'Вже показано: ' . implode(' | ', $recentShown);
        }
        if (!empty($brands)) {
            $contextParts[] = 'Бренди: ' . implode(', ', array_unique($brands));
        }
        if (!empty($sizes)) {
            $contextParts[] = 'Розміри: ' . implode(', ', array_unique($sizes));
        }
        if (!empty($colors)) {
            $contextParts[] = 'Кольори: ' . implode(', ', array_unique($colors));
        }
        if (!empty($priceRange)) {
            $contextParts[] = 'Ціна: ' . implode(', ', array_unique($priceRange));
        }
        if (!empty($userQuestions)) {
            $recentQuestions = array_slice($userQuestions, -3);
            $contextParts[] = 'Останні питання: ' . implode(' → ', $recentQuestions);
        }

        return implode('; ', $contextParts);
    }

    /**
     * Load conversation history from DB.
     */
    protected function loadConversationHistory(?string $sessionId): array
    {
        if (!$sessionId) return [];

        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (!$session) return [];

            $messages = ChatMessage::where('chat_session_id', $session->id)
                ->orderBy('created_at', 'asc')
                ->take(20)
                ->get();

            return $messages->map(fn($m) => [
                'role' => $m->role,
                'content' => $m->content,
            ])->toArray();
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to load history', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Extract shown product IDs from session history.
     */
    protected function extractShownProductIds(?string $sessionId): array
    {
        if (!$sessionId) return [];

        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (!$session) return [];

            $messages = ChatMessage::where('chat_session_id', $session->id)
                ->where('role', 'assistant')
                ->get();

            $ids = [];
            foreach ($messages as $msg) {
                $meta = $msg->meta ?? [];
                if (!empty($meta['product_ids'])) {
                    $ids = array_merge($ids, $meta['product_ids']);
                }
            }

            return array_unique(array_map('intval', $ids));
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to extract shown product IDs', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Load detailed product info from recent assistant messages for follow-up questions.
     * Returns formatted string with product details (description, attributes, sizes).
     */
    protected function loadRecentProductDetails(?string $sessionId): string
    {
        if (!$sessionId) return '';

        try {
            // Bypass TenantScope - sessions are identified by session_id
            $session = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('session_id', $sessionId)->first();
            if (!$session) return '';

            // Get last 3 assistant messages with product details
            // Also bypass TenantScope for ChatMessage query
            $messages = ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('chat_session_id', $session->id)
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get();

            $allDetails = [];
            foreach ($messages as $msg) {
                $meta = $msg->meta ?? [];
                if (!empty($meta['product_details'])) {
                    $allDetails = array_merge($allDetails, $meta['product_details']);
                }
            }

            if (empty($allDetails)) {
                return '';
            }

            // Format for GPT context - only take last 5 products to not bloat context
            $formatted = [];
            $count = 0;
            foreach ($allDetails as $id => $detail) {
                if ($count >= 5) break;
                
                $lines = [];
                $lines[] = "- **{$detail['title']}** (арт. {$detail['article']})";
                
                if (!empty($detail['price'])) {
                    $lines[] = "  Ціна: {$detail['price']} грн";
                }
                
                if (!empty($detail['brand'])) {
                    $lines[] = "  Бренд: {$detail['brand']}";
                }
                
                if (!empty($detail['sizes'])) {
                    $lines[] = "  Доступні розміри: " . implode(', ', $detail['sizes']);
                }
                
                if (!empty($detail['attributes'])) {
                    $attrStr = [];
                    foreach ($detail['attributes'] as $name => $value) {
                        $attrStr[] = "{$name}: {$value}";
                    }
                    if ($attrStr) {
                        $lines[] = "  Характеристики: " . implode('; ', array_slice($attrStr, 0, 8));
                    }
                }
                
                if (!empty($detail['description'])) {
                    $lines[] = "  Опис: " . mb_substr($detail['description'], 0, 200);
                }
                
                $formatted[] = implode("\n", $lines);
                $count++;
            }

            return implode("\n\n", $formatted);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to load product details', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Log user message to DB.
     */
    protected function logUserMessage(?string $sessionId, string $message): void
    {
        if (!$sessionId) return;

        try {
            $tenantId = $this->searchTool->getCurrentTenantId();
            
            $session = ChatSession::firstOrCreate(
                ['session_id' => $sessionId],
                [
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            
            // Update tenant_id if session exists but has null tenant_id
            if ($session->tenant_id === null && $tenantId !== null) {
                $session->update(['tenant_id' => $tenantId]);
            }

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'user',
                'content' => $message,
            ]);
            
            // Update last_message_at for proper sorting in dashboard
            $session->update([
                'last_message_at' => now(),
                'messages_count' => ($session->messages_count ?? 0) + 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to log user message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Log assistant message to DB.
     */
    protected function logAssistantMessage(?string $sessionId, string $text, array $products, string $intent): void
    {
        if (!$sessionId) return;

        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (!$session) return;

            // Build content with product markers
            $content = $text;
            if (!empty($products)) {
                $productMarkers = array_map(fn($p) => ($p['title'] ?? '') . ' (арт. ' . ($p['article'] ?? '') . ')', array_slice($products, 0, 3));
                $content .= "\n[Показані товари: " . implode(', ', $productMarkers) . "]";
            }

            // Build detailed product info for follow-up questions
            $productDetails = $this->buildProductDetailsForStorage($products);

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $content,
                'meta' => [
                    'intent' => $intent,
                    'product_ids' => array_column($products, 'id'),
                    'product_articles' => array_column($products, 'article'),
                    'product_details' => $productDetails, // Full details for follow-up questions
                ],
            ]);
            
            // Update last_message_at for proper sorting in dashboard
            $session->update([
                'last_message_at' => now(),
                'messages_count' => ($session->messages_count ?? 0) + 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to log assistant message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Build detailed product info for storage in meta.
     * Includes description, attributes, sizes, variants - everything GPT might need.
     */
    protected function buildProductDetailsForStorage(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_column($products, 'id');
        
        try {
            // Load full product data from DB
            $dbProducts = Product::whereIn('id', $productIds)->get()->keyBy('id');
            
            $details = [];
            foreach ($products as $p) {
                $id = $p['id'] ?? null;
                if (!$id) continue;
                
                $dbProduct = $dbProducts->get($id);
                if (!$dbProduct) continue;
                
                // Extract description and attributes from raw
                $raw = $dbProduct->raw ?? [];
                $parentRaw = [];
                
                // Try to get parent raw if this is a variant
                if ($dbProduct->parent_article) {
                    $parent = Product::where('article', $dbProduct->parent_article)->first();
                    $parentRaw = $parent?->raw ?? [];
                }
                
                $description = \App\Support\ProductRawExtractor::description($raw, 'ua', $parentRaw);
                $attributes = \App\Support\ProductRawExtractor::attributes($raw, 'ua', $parentRaw);
                
                // Extract available sizes/variants - also get current product size from DB
                $sizes = $this->extractSizesFromProduct($dbProduct);
                
                $details[$id] = [
                    'title' => $p['title'] ?? $dbProduct->title,
                    'article' => $p['article'] ?? $dbProduct->article,
                    'price' => $p['price'] ?? $dbProduct->price,
                    'brand' => $p['brand'] ?? $dbProduct->brand,
                    'description' => mb_substr($description, 0, 500), // Limit to 500 chars
                    'attributes' => array_slice($attributes, 0, 15), // Max 15 attributes
                    'sizes' => $sizes,
                    'category' => $dbProduct->category_path,
                ];
            }
            
            return $details;
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to build product details', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Extract available sizes from product - checks DB column, raw data, and sibling products.
     */
    protected function extractSizesFromProduct(Product $product): array
    {
        $sizes = [];
        $raw = $product->raw ?? [];
        
        // 1. First check the product's own size column
        if (!empty($product->size)) {
            $sizes[] = $product->size;
        }
        
        // 2. Check variants array in raw
        if (!empty($raw['variants']) && is_array($raw['variants'])) {
            foreach ($raw['variants'] as $variant) {
                $size = $variant['size'] ?? ($variant['select']['size'] ?? null);
                if ($size && is_string($size)) {
                    $sizes[] = $size;
                }
            }
        }
        
        // 3. Check select.size in raw
        if (!empty($raw['select']['size'])) {
            $sizeData = $raw['select']['size'];
            if (is_string($sizeData)) {
                $sizes[] = $sizeData;
            } elseif (is_array($sizeData)) {
                foreach ($sizeData as $s) {
                    if (is_string($s)) {
                        $sizes[] = $s;
                    } elseif (is_array($s) && isset($s['value'])) {
                        $sizes[] = $s['value'];
                    }
                }
            }
        }
        
        // 4. Check characteristics.size in raw
        if (!empty($raw['characteristics']['size'])) {
            $sizeChar = $raw['characteristics']['size'];
            if (is_string($sizeChar)) {
                $sizes[] = $sizeChar;
            } elseif (is_array($sizeChar) && isset($sizeChar['value'])) {
                $val = $sizeChar['value'];
                if (is_string($val)) {
                    $sizes[] = $val;
                } elseif (is_array($val)) {
                    $sizes = array_merge($sizes, array_filter($val, 'is_string'));
                }
            }
        }
        
        // 5. Look for sibling products with same parent_article
        if (!empty($product->parent_article)) {
            $siblingsSizes = Product::where('parent_article', $product->parent_article)
                ->whereNotNull('size')
                ->where('size', '!=', '')
                ->limit(20)
                ->pluck('size')
                ->filter()
                ->toArray();
            $sizes = array_merge($sizes, $siblingsSizes);
        }
        
        // 6. If no parent_article, look for products with SAME title (size variants often have identical titles)
        if (empty($product->parent_article) && count(array_unique($sizes)) <= 1) {
            $titleSiblings = Product::where('title', $product->title)
                ->where('id', '!=', $product->id)
                ->where('tenant_id', $product->tenant_id)
                ->whereNotNull('size')
                ->where('size', '!=', '')
                ->limit(20)
                ->pluck('size')
                ->filter()
                ->toArray();
            $sizes = array_merge($sizes, $titleSiblings);
        }
        
        return array_values(array_unique(array_filter($sizes)));
    }

    /**
     * Fallback response when API is unavailable.
     */
    protected function fallbackResponse(string $message): array
    {
        Log::warning('BaseAgent: using fallback response');

        // Try simple keyword search
        $results = $this->searchTool->search($message, [], 3);

        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $tenantId = $this->searchTool->getCurrentTenantId();
            $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
            if (!empty($cards)) $results = $cards;

            return [
                'message' => 'Ось що я знайшов за вашим запитом:',
                'products' => $results,
                'messages' => [
                    ['type' => 'text', 'content' => 'Ось що я знайшов за вашим запитом:'],
                    ['type' => 'products', 'products' => $results],
                ],
                'meta' => ['intent' => 'product_search', 'agent' => 'fallback'],
            ];
        }

        return [
            'message' => 'Вибачте, наразі у мене технічні труднощі. Спробуйте пізніше або зверніться до менеджера.',
            'products' => [],
            'messages' => [['type' => 'text', 'content' => 'Вибачте, наразі у мене технічні труднощі. Спробуйте пізніше або зверніться до менеджера.']],
            'meta' => ['intent' => 'error', 'agent' => 'fallback'],
        ];
    }
}
