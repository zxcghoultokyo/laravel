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

СИНОНІМИ ПРИ ПОШУКУ (використовуй OR):
- шолом → search_products(query="шолом OR каска OR helmet")
- сорочка → search_products(query="сорочка OR shirt")
- plate carrier → search_products(query="plate carrier OR плитоноска")
- boots → search_products(query="boots OR берці OR черевики")

{$priceContext}

🚨 УТОЧНЮЮЧІ ПИТАННЯ — ЗАВЖДИ ВИКЛИКАЙ search_products!
Якщо користувач пише коротке слово/назву що уточнює попередній контекст — ЦЕ КОМАНДА НА ПОШУК!
Приклади:
- Попередньо: "хочу куртку" → Користувач: "softshell" → search_products("softshell куртка")
- Попередньо: "покажи плитоноски" → Користувач: "Архангел" → search_products("плитоноска Архангел")
НІКОЛИ не відповідай текстом на уточнення — ЗАВЖДИ шукай через search_products!

ПАМ'ЯТЬ КОНТЕКСТУ:
- НЕ питай "що хочеш купити" якщо в історії вже є товар
- Якщо обговорювали товар — ПАМ'ЯТАЙ через всю розмову
- В історії є маркери [Показані товари: ...] — використовуй їх!
- Якщо користувач уточнює — комбінуй контекст + уточнення в пошуку!
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

        return <<<PROMPT
Ти — AIntento, AI-консультант магазину тактичного спорядження "Contractor".

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

ГОЛОВНЕ ПРАВИЛО: ЗАВЖДИ ШУКАЙ ЧЕРЕЗ search_products!
Не кажи "цього немає" поки не перевіриш пошуком.

ЗАБОРОНА ГАЛЮЦИНАЦІЙ — КРИТИЧНО!
- НЕ ВИГАДУЙ факти про товари, кольори, матеріали, виробників!
- Якщо питають про характеристику якої НЕМАЄ в каталозі — кажи: "Точної інформації не маю, рекомендую уточнити у менеджера"
- НІКОЛИ не давай "загальних знань" про військове спорядження!
- Ти НЕ ЕКСПЕРТ з військового спорядження — ти ПРОДАВЕЦЬ який знає ТІЛЬКИ свій каталог!

ЗАБОРОНЕНО ВІДПОВІДАТИ НА:
- Історію брендів/технологій — кажи "я продавець, можу показати товари"
- Конкретні цифри (вага, розміри) якщо їх немає в каталозі — кажи "уточніть у менеджера"
- Військова історія, тактика — ти ПРОДАВЕЦЬ, не інструктор!

{$priceContext}

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
        return [
            'shop_name' => 'Contractor',
            'shop_domain' => 'contractor.kiev.ua',
            'shop_phone' => $this->getShopPhone(),
            'faq_info' => $this->loadFaqInfo(),
            'tone_section' => $this->toneService->getFullPromptSection(),
            'price_context' => $this->loadPriceContext(),
        ];
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
        return $settings?->shop_phone ?? '+380 63 631 9919';
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
            return "Актуальну інформацію дивіться на сайті contractor.kiev.ua";
        }

        $info = [];
        if (!empty($settings->shop_phone)) $info[] = "ТЕЛЕФОН: {$settings->shop_phone}";
        if (!empty($settings->faq_contacts_text)) $info[] = "КОНТАКТИ:\n{$settings->faq_contacts_text}";
        if (!empty($settings->faq_payment_delivery_text)) $info[] = "ОПЛАТА ТА ДОСТАВКА:\n{$settings->faq_payment_delivery_text}";
        if (!empty($settings->faq_returns_text)) $info[] = "ПОВЕРНЕННЯ ТА ОБМІН:\n{$settings->faq_returns_text}";
        if (!empty($settings->faq_about_text)) $info[] = "ПРО МАГАЗИН:\n{$settings->faq_about_text}";

        return empty($info) ? "Актуальну інформацію дивіться на сайті contractor.kiev.ua" : implode("\n\n", $info);
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
                    'description' => 'Перевірити статус замовлення за номером або телефоном.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id' => ['type' => 'string', 'description' => 'Номер замовлення'],
                            'phone' => ['type' => 'string', 'description' => 'Телефон покупця'],
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
                    'description' => 'Підібрати розмір за замірами клієнта (зріст, вага, обхват).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => ['type' => 'string', 'description' => 'Артикул товару'],
                            'height' => ['type' => 'integer', 'description' => 'Зріст в см'],
                            'weight' => ['type' => 'integer', 'description' => 'Вага в кг'],
                            'chest' => ['type' => 'integer', 'description' => 'Обхват грудей в см'],
                            'waist' => ['type' => 'integer', 'description' => 'Обхват талії в см'],
                        ],
                        'required' => ['article'],
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

        // Exclude already shown products
        if (!empty($this->shownProductIds) && !empty($results)) {
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

            // Fallback: curated queries
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
                    $popularQueries = ['плитоноска НАТО', 'підсумок магазин', 'рукавички тактичні', 'аптечка ІФАК'];
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

        if ($orderId) {
            $order = $this->orderSearchService->findByOrderId($orderId);
            if ($order) return ['order' => $order];
        }

        if ($phone) {
            $orders = $this->orderSearchService->findByPhone($phone);
            if (!empty($orders)) return ['orders' => $orders, 'count' => count($orders)];
        }

        return ['error' => 'Замовлення не знайдено. Перевірте номер або телефон.'];
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

        if (empty($article)) return ['error' => 'Article required'];

        // Get available sizes first
        $sizesResult = $this->toolGetAvailableSizes(['article' => $article]);
        if (isset($sizesResult['error'])) return $sizesResult;

        $availableSizes = $sizesResult['sizes'] ?? [];
        if (empty($availableSizes)) return ['error' => 'No sizes available', 'recommendation' => 'Товар відсутній в наявності'];

        // ECWCS size chart (US Army standard)
        $ecwcsSizes = [
            'XS/XS' => ['height' => [150, 157], 'chest' => [79, 86], 'waist' => [64, 71]],
            'XS/S' => ['height' => [157, 165], 'chest' => [79, 86], 'waist' => [64, 71]],
            'S/XS' => ['height' => [150, 157], 'chest' => [86, 94], 'waist' => [71, 79]],
            'S/S' => ['height' => [157, 165], 'chest' => [86, 94], 'waist' => [71, 79]],
            'S/R' => ['height' => [165, 175], 'chest' => [86, 94], 'waist' => [71, 79]],
            'M/S' => ['height' => [157, 165], 'chest' => [94, 102], 'waist' => [79, 86]],
            'M/R' => ['height' => [165, 175], 'chest' => [94, 102], 'waist' => [79, 86]],
            'M/L' => ['height' => [175, 183], 'chest' => [94, 102], 'waist' => [79, 86]],
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

        foreach ($ecwcsSizes as $size => $ranges) {
            $score = 0;
            $sizeMatches = false;

            // Check if this size is available
            $availableSize = collect($availableSizes)->first(fn($s) => stripos($s['size'], explode('/', $size)[0]) !== false);
            if (!$availableSize) continue;

            $sizeMatches = true;

            if ($height) {
                $mid = ($ranges['height'][0] + $ranges['height'][1]) / 2;
                $score += abs($height - $mid);
            }
            if ($chest) {
                $mid = ($ranges['chest'][0] + $ranges['chest'][1]) / 2;
                $score += abs($chest - $mid) * 2;
            }
            if ($waist) {
                $mid = ($ranges['waist'][0] + $ranges['waist'][1]) / 2;
                $score += abs($waist - $mid) * 2;
            }

            if ($sizeMatches && $score < $bestScore) {
                $bestScore = $score;
                $recommendation = $size;
            }
        }

        // Add warnings
        if ($chest && $chest > 130) {
            $warnings[] = 'Великий обхват грудей — рекомендуємо уточнити у менеджера';
        }
        if ($weight && $weight > 110) {
            $warnings[] = 'При вазі 110+ кг зверніть увагу на талію — вона визначає комфорт';
        }

        return [
            'product' => $sizesResult['product'],
            'recommended_size' => $recommendation ?? 'M/R',
            'available_sizes' => $availableSizes,
            'warnings' => $warnings,
            'note' => 'Американський крій часто великомірить — можна брати на розмір менше',
        ];
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
     */
    protected function generateTriggerOutro(array $products): string
    {
        if (empty($products)) return 'Є питання? Допоможу з вибором!';

        $firstProduct = $products[0];
        $quantity = $firstProduct['quantity'] ?? 0;

        $hasMultipleSizes = false;
        $hasMultipleColors = false;

        foreach ($products as $p) {
            if (!empty($p['size_variants']) && count($p['size_variants']) > 1) $hasMultipleSizes = true;
            if (!empty($p['color_variants']) && count($p['color_variants']) > 1) $hasMultipleColors = true;
        }

        if ($hasMultipleSizes) return 'Який розмір/варіант вам потрібен? Допоможу підібрати!';
        if ($hasMultipleColors) return 'Який колір вам більше підходить?';
        if ($quantity > 0 && $quantity <= 3) return "Залишилось лише {$quantity} шт. в наявності. Оформлюємо?";

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

        foreach ($history as $msg) {
            $content = $msg['content'] ?? '';
            $role = $msg['role'] ?? '';

            // Collect last 3 user questions for context
            if ($role === 'user' && mb_strlen($content) > 3) {
                $userQuestions[] = mb_substr($content, 0, 100);
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
     * Log user message to DB.
     */
    protected function logUserMessage(?string $sessionId, string $message): void
    {
        if (!$sessionId) return;

        try {
            $session = ChatSession::firstOrCreate(
                ['session_id' => $sessionId],
                ['created_at' => now(), 'updated_at' => now()]
            );

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'user',
                'content' => $message,
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

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $content,
                'meta' => [
                    'intent' => $intent,
                    'product_ids' => array_column($products, 'id'),
                    'product_articles' => array_column($products, 'article'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to log assistant message', ['error' => $e->getMessage()]);
        }
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
