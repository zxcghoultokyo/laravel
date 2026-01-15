<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Ai\ToneService;
use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductSynonym;
use App\Models\WidgetSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Simple GPT Agent with Function Calling
 * 
 * Instead of 2000 lines of hardcoded rules, we let GPT decide what to do.
 * GPT has access to tools and calls them as needed.
 */
class FunctionCallingAgent
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private ToneService $toneService;

    public function __construct(
        private MeiliProductSearchTool $searchTool,
        private ProductDetailsTool $detailsTool,
        private OrderSearchService $orderSearchService,
    ) {
        $config = config('services.openai', []);
        $this->apiKey = $config['key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4o';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->toneService = app(ToneService::class);
    }

    /**
     * Main entry point - let GPT handle the conversation
     */
    public function handle(string $message, array $context = []): array
    {
        $sessionId = $context['session_id'] ?? null;
        
        Log::info('FunctionCallingAgent: processing', ['message' => $message, 'session_id' => $sessionId]);

        // Build conversation history
        $messages = $this->buildMessages($message, $context);

        // Call GPT with tools
        $response = $this->callGptWithTools($messages);

        if (!$response) {
            return $this->fallbackResponse($message);
        }

        // Process tool calls if any
        $toolCalls = $response['choices'][0]['message']['tool_calls'] ?? null;
        
        if ($toolCalls) {
            return $this->handleToolCalls($toolCalls, $messages, $message, $sessionId);
        }

        // Direct text response (small talk, FAQ, follow-up questions)
        $text = $response['choices'][0]['message']['content'] ?? '';
        
        // Check if GPT returned JSON (sometimes it does for follow-ups)
        // If so, just extract the intro as plain text and save context
        if (preg_match('/^\s*\{/u', $text)) {
            $json = json_decode($text, true);
            if ($json) {
                // Save context summary if present
                if ($sessionId) {
                    $this->extractAndSaveContext($sessionId, $json);
                }
                
                if (isset($json['intro'])) {
                    $text = $json['intro'];
                    // If it has product comments, append them
                    if (!empty($json['products'])) {
                        foreach ($json['products'] as $p) {
                            if (!empty($p['comment'])) {
                                $text .= "\n• " . $p['comment'];
                            }
                        }
                    }
                } elseif (isset($json['text'])) {
                    $text = $json['text'];
                }
            }
        }
        
        return [
            'message' => $text,
            'products' => [],
            'messages' => [['type' => 'text', 'content' => $text]],
            'meta' => ['intent' => 'text', 'agent' => 'function_calling'],
        ];
    }

    /**
     * Build messages array with system prompt
     * Includes enhanced context for follow-up queries
     */
    private function buildMessages(string $message, array $context): array
    {
        $systemPrompt = $this->getSystemPrompt();
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add conversation history if available
        $history = $context['history'] ?? [];
        
        // Extract conversation context from history (more reliable than GPT-generated _context)
        $conversationContext = $this->extractConversationContext($history);
        
        Log::info('FunctionCallingAgent: extracted context', [
            'context' => $conversationContext,
            'history_count' => count($history),
        ]);
        
        if ($conversationContext) {
            $messages[] = [
                'role' => 'system', 
                'content' => "[КОНТЕКСТ РОЗМОВИ: {$conversationContext}]\nПАМ'ЯТАЙ ЦЕЙ КОНТЕКСТ! Не питай користувача що він шукає якщо це вже відомо з контексту!"
            ];
        }
        
        // Also try saved context summary as backup
        $sessionId = $context['session_id'] ?? null;
        $savedContext = $sessionId ? $this->loadContextSummary($sessionId) : null;
        if ($savedContext && !$conversationContext) {
            $messages[] = [
                'role' => 'system', 
                'content' => "[КОНТЕКСТ РОЗМОВИ: {$savedContext}]"
            ];
        }

        foreach ($history as $msg) {
            $messages[] = $msg;
        }
        
        // Detect if this is a follow-up size/color/filter query
        $lowerMessage = mb_strtolower(trim($message));
        $isFollowUp = $this->detectFollowUpQuery($lowerMessage, $history);
        
        // If follow-up, add context hint for GPT
        if ($isFollowUp && !empty($history)) {
            $lastAssistant = null;
            foreach (array_reverse($history) as $msg) {
                if ($msg['role'] === 'assistant' && str_contains($msg['content'], '[Показані товари:')) {
                    $lastAssistant = $msg['content'];
                    break;
                }
            }
            
            if ($lastAssistant) {
                // Extract product context
                if (preg_match('/\[Показані товари: (.+?)\]/', $lastAssistant, $matches)) {
                    $productContext = $matches[1];
                    $message = "{$message}\n[Контекст: користувач запитує про {$productContext}]";
                }
            }
        }

        // Add current message
        $messages[] = ['role' => 'user', 'content' => $message];

        return $messages;
    }
    
    /**
     * Detect if message is a follow-up query (size, color, price filter)
     */
    private function detectFollowUpQuery(string $message, array $history): bool
    {
        // Short messages that look like follow-ups
        $followUpPatterns = [
            '/^(в |у )?(розмір|размер)/ui', // size queries
            '/^(в |у )?(кольор|цвет|color)/ui', // color queries  
            '/^(які|які є|що є|а є|є ).{0,20}(L|M|S|XL|XXL|\d{2})/ui', // "які є в L"
            '/^(дешевш|дорожч|до \d|від \d|бюджет)/ui', // price
            '/^(ще|більше|інші|інш|варіант)/ui', // more options
            '/^(чорн|біл|олив|мультикам|піксель|коричнев)/ui', // colors
            '/^(L|M|S|XL|XXL|\d{2})$/ui', // just size
        ];
        
        foreach ($followUpPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        
        // Also follow-up if message is very short and we have history
        if (mb_strlen($message) < 30 && count($history) >= 2) {
            // Check if last message was a product search
            $lastAssistant = null;
            foreach (array_reverse($history) as $msg) {
                if ($msg['role'] === 'assistant') {
                    $lastAssistant = $msg;
                    break;
                }
            }
            if ($lastAssistant && str_contains($lastAssistant['content'], '[Показані товари:')) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * System prompt - the brain of the agent
     */
    private function getSystemPrompt(): string
    {
        // Load FAQ info and tone settings
        $faqInfo = $this->loadFaqInfo();
        $toneSection = $this->toneService->getFullPromptSection();
        
        $basePrompt = <<<PROMPT
Ти — AIntento, AI-консультант магазину тактичного спорядження "Contractor".

МУЛЬТИМОВНІСТЬ:
- ЗАВЖДИ відповідай МОВОЮ КОРИСТУВАЧА (якщо пише англійською — відповідай англійською, польською — польською і т.д.)
- При пошуку товарів (search_products) загальні слова — українською, але НАЗВИ БРЕНДІВ/МОДЕЛЕЙ — залишай оригінальними!
- Приклад: "футболка трайдент" → search_products(query: "футболка TRIDENT") — TRIDENT не перекладаємо!
- Приклад: "plate carrier multicam" → search_products(query: "плитоноска мультикам")
- Картки товарів залишаться українською — це нормально, клієнт бачить оригінальні назви
- Коментарі до товарів пиши МОВОЮ КОРИСТУВАЧА!

ГОЛОВНЕ ПРАВИЛО: ЗАВЖДИ ШУКАЙ ЧЕРЕЗ search_products!
Не кажи "цього немає" поки не перевіриш пошуком. В магазині є багато товарів: плитоноски, шоломи, берці, бронеплити, рюкзаки, підсумки, аптечки, рукавиці, форма, фарби, та інше.

АВТОВИПРАВЛЕННЯ (виправляй помилки і шукай):
- плитноска, плейткерієр → плитоноска
- опс кор, опскор → Ops-Core
- сестан буш → SESTAN BUSCH
- берци, ботінки → берці
- шлем, каска, helmet → шолом (ШУК АЙ ВСІ ВАРІАНТИ: "шолом OR каска")
- разгрузка → плитоноска
- подсумок → підсумок

СИНОНІМИ ПРИ ПОШУКУ (використовуй OR):
- шолом → search_products(query="шолом OR каска OR helmet")
- сорочка → search_products(query="сорочка OR shirt")
- штани → search_products(query="штани OR брюки")
- кросівки → search_products(query="кросівки OR кроси")

СЛЕНГ І СКОРОЧЕННЯ (розумій контекст):
- "балістика в/для напашник" → вставка в напашник
- "балістика в пояс/рпс" → вставка в пояс рпс
- "плита в напашник" → вставка в напашник
- "м'яка/мягка балістика" → мяка балістична вставка
- "засіб від москітів/комарів" → просочення від москітів
- "засіб від комах" → просочення від комах
- "репелент" → просочення від москітів

ОСОБЛИВІ ВИПАДКИ ПОШУКУ:
- "плити" (без "бокові") → search_products(query="бронеплита", exclude="бокова")
- "бокові плити" → search_products(query="бокова бронеплита")

FOLLOW-UP (розрізняй типи):
МОДИФІКАЦІЯ попереднього пошуку (додай фільтр до того ж запиту):
- "дешевше?" → той самий пошук + price_max
- "в чорному" → + color="чорний"
- "ще варіанти" → той самий пошук
- "більший розмір" → + size

СЕЗОННІ ЗАПИТИ - ОБОВ'ЯЗКОВО ПОШУК:
- "що беруть зимою/взимку" → search_products("зимовий одяг куртка термобілизна") - НЕ get_popular_products!
- "що беруть влітку" → search_products("літній одяг футболка")
- "що актуально зараз" → search_products з урахуванням сезону (грудень-лютий = зима)
- Сезонні питання = ПОШУК конкретних товарів для сезону, а не загальний топ!

НОВИЙ ЗАПИТ (шукай ТІЛЬКИ новий товар, НЕ повторюй попередній!):
- "і хочу кавер" → search_products(query="кавер") - ТІЛЬКИ кавери!
- "а ще берці" → search_products(query="берці") - ТІЛЬКИ берці!
- "також потрібен рюкзак" → search_products(query="рюкзак")
НЕ ВИКЛИКАЙ search_products для товарів що вже показані!

ЗГОДА/ПІДТВЕРДЖЕННЯ (КРИТИЧНО!):
Коли користувач каже "давай", "ок", "добре", "так", "покажи" — це ЗГОДА на дію!
- Якщо запитали уточнення і отримали "давай" → ПОКАЖИ ТОВАРИ з контексту розмови!
- НЕ питай знову "що саме вас цікавить" — це помилка!
- Приклад: "подарунок для дружини" → бот: "чим цікавиться?" → "давай" → search_products(query="топ товари для жінок") або search_products(query="подарунок")
- Коротка відповідь = згода на попередню пропозицію

ФОРМАТ ВІДПОВІДІ:
1. ПІСЛЯ search_products → JSON: {"intro": "...", "products": [{"article": "xxx", "comment": "..."}], "_context": "короткий опис контексту"}
2. Текстові питання → JSON: {"text": "...", "_context": "короткий опис контексту"}
3. Нічого не знайдено → {"text": "На жаль, не знайшов. Спробуй інакше сформулювати.", "_context": "..."}

_context (ОБОВ'ЯЗКОВО!):
- Завжди додавай "_context" з коротким описом поточного контексту розмови (5-15 слів)
- Приклад: "шукає вогнестійку сорочку, зріст 172, питає про розмір M/L"
- Приклад: "вибирає плитоноску Crye, бюджет до 30000, показали 3 варіанти"
- Це допоможе тобі пам'ятати контекст у наступних повідомленнях!

СТИЛІСТИКА:
- Пиши природно, як жива людина, НЕ як робот
- Уникай повторення одного слова — використовуй займенники (вона, він, це, ця)
- НЕ починай з назви товару, якщо вона вже є в питанні

ЛАКОНІЧНІСТЬ:
- Максимум 2-3 речення
- НЕ питай бюджет/розмір без потреби
- НЕ читай лекції

ПАМ'ЯТЬ КОНТЕКСТУ (КРИТИЧНО!):
- НІКОЛИ не питай "що хочеш купити" якщо в історії розмови вже є товар (сорочка, шолом, берці...)
- Якщо обговорювали конкретний товар — ПАМ'ЯТАЙ ЦЕ через всю розмову!
- Коли користувач каже "немає замірів" або "не знаю розмір" → ДАЙ ПОРАДУ на основі того що знаєш (зріст, вага) + ПОКАЖИ ТОВАРИ
- Приклад: "сорочка вогнестійка балістична, зріст 172, який розмір M чи L?" → розмір + search_products("сорочка вогнестійка балістична")
- ЗАВЖДИ шукай товари паралельно з консультацією!

ПОКАЗАНІ ТОВАРИ - ДУЖЕ ВАЖЛИВО:
- В історії чату є маркери [Показані товари: ... (арт. XXX)] з артикулами!
- Якщо клієнт каже "розкажи про нього/це/останнє/перше" → шукай артикул в [Показані товари:]
- "Аптечка" = набір з турнікета, бандажа, пластира якщо вони були в показаних товарах
- При "розкажи детальніше" → використай get_product_details(article) з контексту!
- НІКОЛИ не кажи "я не пропонував" якщо товари є в [Показані товари:]!
- Посилання/деталі → get_product_details(article) де article береш з маркера [Показані товари:]

КОНСУЛЬТАЦІЯ + ПОКАЗ ТОВАРІВ:
- При питаннях про розмір/колір/вибір → ДАЙ ПОРАДУ + ПОКАЖИ ТОВАРИ одразу
- НЕ чекай додаткових питань — клієнт хоче бачити товари!
- Якщо немає точних замірів — рекомендуй по зросту/вазі та ПОКАЖИ варіанти
- Формула: Консультація (2-3 речення) + search_products → товари

ЗАМОВЛЕННЯ:
- Коли показуєш деталі замовлення - показуй ВСЕ одразу (товари, статус, доставку)
- НЕ пропонуй "можу скинути посилання" - ти не маєш прямого посилання на товар з замовлення!
- Якщо клієнт хоче товар з замовлення в каталозі - ОДРАЗУ шукай через search_products по назві товару
- Не пропонуй те, що не можеш зробити!

ВАЖЛИВО: ПОСИЛАННЯ = КАРТКА ТОВАРУ!
- Коли клієнт просить "посилання", "купити", "замовити" конкретний товар з контексту → get_product_details(article)
- НІКОЛИ не пиши URL текстом! Завжди показуй КАРТКУ ТОВАРУ через get_product_details!
- Артикул бери з попередньої відповіді (products[].article)
- Приклад: "дай посилання на плитоноску" → get_product_details(article="se6-4lj-2i9")

{$toneSection}

ІНФОРМАЦІЯ ПРО МАГАЗИН (використовуй для відповідей на питання):
{$faqInfo}
PROMPT;

        return $basePrompt;
    }

    /**
     * Load FAQ info from WidgetSettings
     */
    private function loadFaqInfo(): string
    {
        $settings = Cache::remember('widget_settings_faq', 300, function () {
            return WidgetSettings::first();
        });

        if (!$settings) {
            return "Актуальну інформацію дивіться на сайті contractor.kiev.ua";
        }

        $info = [];

        // Phone
        if (!empty($settings->shop_phone)) {
            $info[] = "ТЕЛЕФОН: {$settings->shop_phone}";
        }

        // Contacts
        if (!empty($settings->faq_contacts_text)) {
            $info[] = "КОНТАКТИ:\n{$settings->faq_contacts_text}";
        }

        // Payment & Delivery
        if (!empty($settings->faq_payment_delivery_text)) {
            $info[] = "ОПЛАТА ТА ДОСТАВКА:\n{$settings->faq_payment_delivery_text}";
        }

        // Returns
        if (!empty($settings->faq_returns_text)) {
            $info[] = "ПОВЕРНЕННЯ ТА ОБМІН:\n{$settings->faq_returns_text}";
        }

        // About
        if (!empty($settings->faq_about_text)) {
            $info[] = "ПРО МАГАЗИН:\n{$settings->faq_about_text}";
        }

        if (empty($info)) {
            return "Актуальну інформацію дивіться на сайті contractor.kiev.ua";
        }

        return implode("\n\n", $info);
    }

    /**
     * Define tools for GPT
     */
    private function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products',
                    'description' => 'Пошук товарів в каталозі. Використовуй для будь-якого запиту про товари.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Пошуковий запит (назва товару, бренд, характеристики). Приклади: "плитоноска", "шолом Ops-Core", "берці 43"',
                            ],
                            'product_type' => [
                                'type' => 'string',
                                'description' => 'Тип товару для фільтрації (плитоноска, шолом, берці, рюкзак, підсумок, ремінь). Використовуй щоб виключити аксесуари.',
                            ],
                            'brand' => [
                                'type' => 'string',
                                'description' => 'Бренд товару (Ops-Core, SESTAN BUSCH, Salomon, FirstSpear, Crye Precision)',
                            ],
                            'price_min' => [
                                'type' => 'number',
                                'description' => 'Мінімальна ціна в гривнях',
                            ],
                            'price_max' => [
                                'type' => 'number',
                                'description' => 'Максимальна ціна в гривнях',
                            ],
                            'color' => [
                                'type' => 'string',
                                'description' => 'Колір (чорний, мультикам, піксель, койот, олива)',
                            ],
                            'exclude' => [
                                'type' => 'string',
                                'description' => 'Виключити товари що містять це слово в назві (наприклад "бокова" для виключення бокових плит)',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Кількість результатів (за замовчуванням 5)',
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
                    'description' => 'Отримати найпопулярніші товари за кількістю продажів. Використовуй ТІЛЬКИ для загальних питань "подарунок", "топ", "що порадиш", "популярне", "хіт продажів" БЕЗ згадки сезону. Для сезонних питань ("що беруть зимою") - використовуй search_products замість цього!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => [
                                'type' => 'string',
                                'description' => 'Категорія (плитоноски, шоломи, берці, рюкзаки). Якщо не вказано — з різних категорій.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Кількість товарів (за замовчуванням 5)',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_product_details',
                    'description' => 'Детальна інформація про конкретний товар за артикулом або ID.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => [
                                'type' => 'string',
                                'description' => 'Артикул товару',
                            ],
                        ],
                        'required' => ['article'],
                    ],
                ],
            ],
            [
                'type' => 'function', 
                'function' => [
                    'name' => 'get_order_status',
                    'description' => 'Перевірити статус замовлення за номером або знайти замовлення по телефону.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id' => [
                                'type' => 'string',
                                'description' => 'Номер замовлення (якщо відомий)',
                            ],
                            'phone' => [
                                'type' => 'string',
                                'description' => 'Номер телефону покупця для пошуку замовлень (формат: +380XXXXXXXXX)',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_categories',
                    'description' => 'Список категорій товарів в магазині.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object)[],  // Must be object, not array
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_brands',
                    'description' => 'Список брендів. Можна фільтрувати по категорії.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => [
                                'type' => 'string',
                                'description' => 'Категорія для фільтрації брендів',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Call OpenAI with function calling
     */
    private function callGptWithTools(array $messages): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('FunctionCallingAgent: no API key', [
                'config_key_exists' => !empty(config('services.openai.key')),
                'env_key_exists' => !empty(env('OPENAI_API_KEY')),
            ]);
            return null;
        }

        try {
            Log::info('FunctionCallingAgent: calling OpenAI', [
                'model' => $this->model,
                'base_url' => $this->baseUrl,
                'messages_count' => count($messages),
            ]);
            
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post($this->baseUrl . '/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'tools' => $this->getTools(),
                    'tool_choice' => 'auto',
                    'temperature' => 0.3,
                ]);

            $data = $response->json();
            
            Log::info('FunctionCallingAgent: GPT response', [
                'status' => $response->status(),
                'has_tool_calls' => isset($data['choices'][0]['message']['tool_calls']),
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
                'error' => $data['error'] ?? null,
            ]);

            if (isset($data['error'])) {
                Log::error('FunctionCallingAgent: OpenAI error', ['error' => $data['error']]);
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('FunctionCallingAgent: API error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Handle tool calls from GPT
     */
    private function handleToolCalls(array $toolCalls, array $messages, string $originalMessage, ?string $sessionId): array
    {
        $products = [];
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'];
            $args = json_decode($toolCall['function']['arguments'], true) ?? [];

            Log::info('FunctionCallingAgent: executing tool', [
                'function' => $functionName,
                'args' => $args,
            ]);

            $result = $this->executeTool($functionName, $args);
            
            // Collect products from search tools
            if (in_array($functionName, ['search_products', 'get_popular_products']) && !empty($result['products'])) {
                $products = array_merge($products, $result['products']);
            }
            // Handle single product from get_product_details
            if ($functionName === 'get_product_details' && !empty($result['product'])) {
                $products[] = $result['product'];
            }

            $toolResults[] = [
                'tool_call_id' => $toolCall['id'],
                'role' => 'tool',
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }

        // Add assistant message with tool calls
        $messages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $toolCalls,
        ];

        // Add tool results
        foreach ($toolResults as $result) {
            $messages[] = $result;
        }

        // Get final response from GPT
        $finalResponse = $this->callGptWithTools($messages);
        $responseText = $finalResponse['choices'][0]['message']['content'] ?? '';

        // Dedupe products
        $products = $this->dedupeProducts($products);

        // Parse GPT response as JSON for structured output
        $structuredResponse = $this->parseStructuredResponse($responseText, $products);

        // Save context summary from response
        if ($sessionId && $structuredResponse) {
            // Try to extract _context from parsed JSON
            $contextJson = null;
            if (preg_match('/\{[\s\S]*\}/u', $responseText, $matches)) {
                $contextJson = json_decode($matches[0], true);
            }
            if ($contextJson) {
                $this->extractAndSaveContext($sessionId, $contextJson);
            }
        }

        return [
            'message' => $structuredResponse['intro'] ?? 'Ось що я знайшов:',
            'products' => $structuredResponse['products'] ?? array_slice($products, 0, 5),
            'messages' => $structuredResponse['messages'] ?? [],
            'meta' => [
                'intent' => 'product_search',
                'agent' => 'function_calling',
                'tools_called' => array_map(fn($tc) => $tc['function']['name'], $toolCalls),
                'products_found' => count($products),
                'outro' => $structuredResponse['outro'] ?? null,
            ],
        ];
    }

    /**
     * Parse GPT structured JSON response and build messages array
     */
    private function parseStructuredResponse(string $responseText, array $allProducts): array
    {
        // Try to parse JSON from response
        $json = null;
        
        // Extract JSON from response (might be wrapped in markdown code block)
        if (preg_match('/\{[\s\S]*\}/u', $responseText, $matches)) {
            $json = json_decode($matches[0], true);
        }
        
        Log::info('FunctionCallingAgent: parsing response', [
            'raw_response' => $responseText,
            'parsed_json' => $json,
        ]);

        // Build products by article index
        $productsByArticle = [];
        foreach ($allProducts as $p) {
            $productsByArticle[$p['article']] = $p;
        }

        // If valid JSON with products
        if ($json && isset($json['products']) && is_array($json['products'])) {
            $messages = [];
            
            // Add intro message
            if (!empty($json['intro'])) {
                $messages[] = ['type' => 'text', 'content' => $json['intro']];
            }
            
            // Add product cards with comments
            $orderedProducts = [];
            foreach ($json['products'] as $item) {
                $article = $item['article'] ?? '';
                $comment = $item['comment'] ?? '';
                
                $product = $productsByArticle[$article] ?? null;
                if (!$product) {
                    // Try partial match
                    foreach ($productsByArticle as $a => $p) {
                        if (str_contains($a, $article) || str_contains($article, $a)) {
                            $product = $p;
                            break;
                        }
                    }
                }
                
                if ($product) {
                    $messages[] = [
                        'type' => 'product',
                        'product' => $product,
                        'comment' => $comment,
                    ];
                    $orderedProducts[] = $product;
                }
            }
            
            // Add outro message if exists
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
        
        // Handle JSON with 'text' key (no products found response)
        if ($json && isset($json['text'])) {
            $textContent = $json['text'];
            $messages = [['type' => 'text', 'content' => $textContent]];
            
            // Still show available products if any
            foreach (array_slice($allProducts, 0, 5) as $product) {
                $messages[] = ['type' => 'product', 'product' => $product, 'comment' => ''];
            }
            
            return [
                'intro' => $textContent,
                'outro' => null,
                'products' => array_slice($allProducts, 0, 5),
                'messages' => $messages,
            ];
        }
        
        // Fallback: plain text response
        $messages = [];
        if ($responseText) {
            $messages[] = ['type' => 'text', 'content' => $responseText];
        }
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
     * Execute a tool and return result
     */
    private function executeTool(string $name, array $args): array
    {
        return match ($name) {
            'search_products' => $this->toolSearchProducts($args),
            'get_popular_products' => $this->toolGetPopularProducts($args),
            'get_product_details' => $this->toolGetProductDetails($args),
            'get_order_status' => $this->toolGetOrderStatus($args),
            'get_categories' => $this->toolGetCategories(),
            'get_brands' => $this->toolGetBrands($args),
            default => ['error' => 'Unknown tool'],
        };
    }

    /**
     * Tool: Search products
     */
    private function toolSearchProducts(array $args): array
    {
        $query = $args['query'] ?? '';
        $limit = $args['limit'] ?? 20; // Increased to show more variety
        
        Log::info('toolSearchProducts: args', ['args' => $args]);
        
        // Build filters
        $filters = [];
        if (!empty($args['price_min'])) {
            $filters['price_min'] = (float) $args['price_min'];
        }
        if (!empty($args['price_max'])) {
            $filters['price_max'] = (float) $args['price_max'];
        }
        if (!empty($args['brand'])) {
            $filters['brand'] = $args['brand'];
        }

        // Search in Meilisearch (request more to account for filtering)
        $searchLimit = $limit * 3;
        $results = $this->searchTool->search($query, $filters, $searchLimit);
        
        $initialCount = count($results);

        // Exclude products by keyword in title
        if (!empty($args['exclude']) && !empty($results)) {
            $exclude = mb_strtolower($args['exclude']);
            $beforeCount = count($results);
            $results = array_filter($results, function ($p) use ($exclude) {
                $title = mb_strtolower($p['title'] ?? '');
                return !str_contains($title, $exclude);
            });
            $results = array_values($results);
            Log::info('toolSearchProducts: after exclude filter', [
                'exclude' => $exclude,
                'before' => $beforeCount,
                'after' => count($results),
            ]);
        }

        // Filter by product_type if specified
        // Check ai_product_type, title, and category_path (ai_product_type is often __unknown__)
        if (!empty($args['product_type']) && !empty($results)) {
            $productType = mb_strtolower($args['product_type']);
            $beforeCount = count($results);
            
            // Get synonyms from DB (cached)
            $searchTerms = $this->getProductTypeSynonyms($productType);
            
            $results = array_filter($results, function ($p) use ($searchTerms) {
                $aiType = mb_strtolower($p['ai_product_type'] ?? '');
                $title = mb_strtolower($p['title'] ?? '');
                $category = mb_strtolower($p['category_path'] ?? '');
                $searchText = $aiType . ' ' . $title . ' ' . $category;
                
                // Match if any search term is found
                foreach ($searchTerms as $term) {
                    if (str_contains($searchText, $term)) {
                        return true;
                    }
                }
                return false;
            });
            $results = array_values($results);
            Log::info('toolSearchProducts: after product_type filter', [
                'product_type' => $productType,
                'search_terms' => $searchTerms,
                'before' => $beforeCount,
                'after' => count($results),
            ]);
        }

        // Filter by color if specified
        if (!empty($args['color']) && !empty($results)) {
            $color = mb_strtolower($args['color']);
            $beforeCount = count($results);
            $results = array_filter($results, function ($p) use ($color) {
                $title = mb_strtolower($p['title'] ?? '');
                $attrs = mb_strtolower($p['color'] ?? '');
                return str_contains($title, $color) || str_contains($attrs, $color);
            });
            $results = array_values($results);
            Log::info('toolSearchProducts: after color filter', [
                'color' => $color,
                'before' => $beforeCount,
                'after' => count($results),
            ]);
        }

        // Limit results after filtering
        $results = array_slice($results, 0, $limit);
        
        Log::info('toolSearchProducts: final results', [
            'initial_from_meili' => $initialCount,
            'final_count' => count($results),
        ]);

        // Get full product cards with images
        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $cards = $this->detailsTool->getCards($ids);
            if (!empty($cards)) {
                $results = $cards;
            }
        }

        return [
            'products' => $results,
            'count' => count($results),
            'query' => $query,
        ];
    }

    /**
     * Tool: Get popular products
     * Uses real orders_count when available, falls back to curated queries.
     */
    private function toolGetPopularProducts(array $args): array
    {
        $category = $args['category'] ?? null;
        $limit = $args['limit'] ?? 5;

        // Cache key based on category and limit
        $cacheKey = 'popular_products_v5:' . ($category ?? 'all') . ':' . $limit;
        
        // Cache popular products for 5 minutes
        return Cache::remember($cacheKey, 300, function () use ($category, $limit) {
            $products = [];

            // Filter function to exclude rare/expensive items
            $filterProduct = function($p) {
                $size = strtolower($p['size'] ?? '');
                $title = strtolower($p['title'] ?? '');
                $price = (float) ($p['price'] ?? 0);
                
                // Exclude very large/rare sizes
                if (preg_match('/\b(50|51|52|53|54|55|xxxl|xxxxl|us\s*1[4-9]|us\s*16)\b/i', $size . ' ' . $title)) {
                    return false;
                }
                
                // Exclude expensive items (>20k) - not mass market
                // Note: Aegis plates ~16k are popular, so limit is 20k
                if ($price > 20000) {
                    return false;
                }
                
                // Must be in stock
                if (!($p['in_stock'] ?? false)) {
                    return false;
                }
                
                return true;
            };
            
            // Check if we have real sales data
            $hasOrdersData = Product::where('orders_count', '>', 0)->exists();
            
            if ($hasOrdersData) {
                // USE REAL SALES DATA - query products by orders_count
                $query = Product::where('in_stock', true)
                    ->where('orders_count', '>', 0)
                    ->where('quantity', '>', 0);
                
                if ($category) {
                    $query->where(function($q) use ($category) {
                        $q->where('category_path', 'like', "%{$category}%")
                          ->orWhere('title', 'like', "%{$category}%")
                          ->orWhere('search_index', 'like', "%{$category}%");
                    });
                }
                
                $topSellers = $query->orderBy('orders_count', 'desc')
                    ->take($limit * 3)
                    ->get();
                
                foreach ($topSellers as $p) {
                    $item = [
                        'id' => $p->id,
                        'article' => $p->article,
                        'title' => $p->title,
                        'price' => $p->price,
                        'in_stock' => $p->in_stock,
                        'size' => $p->size,
                        'orders_count' => $p->orders_count,
                        'popularity' => $p->popularity,
                    ];
                    if ($filterProduct($item)) {
                        $products[] = $item;
                    }
                    if (count($products) >= $limit) break;
                }
            }
            
            // Fallback: curated queries if no sales data or not enough products
            if (count($products) < $limit) {
                if ($category) {
                    $results = $this->searchTool->search($category, [], $limit * 3);
                    $results = array_filter($results, $filterProduct);
                    usort($results, fn($a, $b) => 
                        (($b['popularity'] ?? 0) + (($b['orders_count'] ?? 0) * 10)) <=> 
                        (($a['popularity'] ?? 0) + (($a['orders_count'] ?? 0) * 10))
                    );
                    $needed = $limit - count($products);
                    $existingIds = array_column($products, 'id');
                    foreach ($results as $r) {
                        if (!in_array($r['id'], $existingIds)) {
                            $products[] = $r;
                            if (count($products) >= $limit) break;
                        }
                    }
                } else {
                    // Curated popular queries - affordable, common items
                    $popularQueries = [
                        'плитоноска НАТО',    // Basic plate carrier
                        'підсумок магазин',   // Magazine pouches
                        'рукавички тактичні', // Tactical gloves
                        'аптечка ІФАК',       // First aid
                    ];
                    $existingIds = array_column($products, 'id');
                    foreach ($popularQueries as $query) {
                        $results = $this->searchTool->search($query, [], 10);
                        $results = array_filter($results, $filterProduct);
                        if (!empty($results)) {
                            // Sort by optimal price (mid-range preferred)
                            usort($results, function($a, $b) {
                                $priceA = (float) ($a['price'] ?? 0);
                                $priceB = (float) ($b['price'] ?? 0);
                                $scoreA = abs($priceA - 3000) + (10000 - ($a['popularity'] ?? 0));
                                $scoreB = abs($priceB - 3000) + (10000 - ($b['popularity'] ?? 0));
                                return $scoreA <=> $scoreB;
                            });
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

            // Get full product cards
            if (!empty($products)) {
                $ids = array_column($products, 'id');
                $cards = $this->detailsTool->getCards($ids);
                if (!empty($cards)) {
                    $products = $cards;
                }
            }

            return [
                'products' => array_slice($products, 0, $limit),
                'count' => count($products),
            ];
        });
    }

    /**
     * Tool: Get product details
     */
    private function toolGetProductDetails(array $args): array
    {
        $article = $args['article'] ?? '';
        
        if (empty($article)) {
            return ['error' => 'Article required'];
        }

        $product = Product::where('article', $article)->first();
        
        if (!$product) {
            return ['error' => 'Product not found'];
        }

        return [
            'product' => [
                'title' => $product->title,
                'article' => $product->article,
                'price' => $product->price,
                'brand' => $product->brand,
                'description' => $product->raw['description_ua'] ?? $product->raw['description_ru'] ?? '',
                'in_stock' => $product->in_stock,
                'link' => $product->link,
            ],
        ];
    }

    /**
     * Tool: Get order status by order_id or phone
     */
    private function toolGetOrderStatus(array $args): array
    {
        // Check if Horoshop is configured
        if (!$this->orderSearchService->isAvailable()) {
            return ['error' => 'Пошук замовлень тимчасово недоступний'];
        }
        
        $orderId = $args['order_id'] ?? '';
        $phone = $args['phone'] ?? '';
        
        // Build search criteria
        $criteria = [];
        
        if (!empty($orderId)) {
            $criteria['order_id'] = $orderId;
        }
        
        if (!empty($phone)) {
            // Normalize phone: remove spaces, dashes, parentheses
            $normalized = preg_replace('/[\s\-\(\)]+/', '', $phone);
            // Ensure +38 prefix
            if (!str_starts_with($normalized, '+38') && !str_starts_with($normalized, '38')) {
                $normalized = '+38' . $normalized;
            } elseif (str_starts_with($normalized, '38') && !str_starts_with($normalized, '+38')) {
                $normalized = '+' . $normalized;
            }
            $criteria['phone'] = $normalized;
        }
        
        if (empty($criteria)) {
            return ['error' => 'Потрібен номер замовлення або телефон'];
        }

        Log::info('toolGetOrderStatus: searching', $criteria);

        try {
            $result = $this->orderSearchService->search($criteria);
            
            Log::info('toolGetOrderStatus: result', [
                'found' => $result['total'] ?? 0,
                'orders_count' => count($result['orders'] ?? []),
            ]);
            
            return [
                'orders' => $result['orders'] ?? [],
                'found' => $result['total'] ?? 0,
                'search_type' => $result['search_type'] ?? 'unknown',
            ];
        } catch (\Throwable $e) {
            Log::error('toolGetOrderStatus: error', ['error' => $e->getMessage()]);
            return ['error' => 'Не вдалось знайти замовлення: ' . $e->getMessage()];
        }
    }

    /**
     * Tool: Get categories
     */
    private function toolGetCategories(): array
    {
        $categories = Cache::remember('agent:categories', 3600, function () {
            return Category::whereNotNull('name')
                ->where('products_count', '>', 0)
                ->orderByDesc('products_count')
                ->limit(20)
                ->pluck('name')
                ->toArray();
        });

        // Fallback
        if (empty($categories)) {
            $categories = ['Плитоноски', 'Шоломи', 'Бронеплити', 'Берці', 'Рюкзаки', 'Підсумки', 'Форма'];
        }

        return ['categories' => $categories];
    }

    /**
     * Tool: Get brands
     */
    private function toolGetBrands(array $args): array
    {
        $category = $args['category'] ?? null;

        $query = Brand::where('is_active', true)
            ->orderByDesc('product_count');

        // TODO: filter by category if needed

        $brands = $query->limit(20)->pluck('name')->toArray();

        // Fallback
        if (empty($brands)) {
            $brands = Product::whereNotNull('brand')
                ->select('brand')
                ->groupBy('brand')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(20)
                ->pluck('brand')
                ->toArray();
        }

        return ['brands' => $brands];
    }

    /**
     * Deduplicate products by parent_article
     */
    private function dedupeProducts(array $products): array
    {
        $seen = [];
        $result = [];

        foreach ($products as $product) {
            $key = $product['parent_article'] ?? $product['article'] ?? $product['id'] ?? uniqid();
            
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $product;
            }
        }

        return $result;
    }

    /**
     * Get all synonyms for a product type from DB
     * Returns array of terms to search for (including the original term)
     */
    private function getProductTypeSynonyms(string $productType): array
    {
        $cacheKey = 'product_type_synonyms:' . md5($productType);
        
        return Cache::remember($cacheKey, 3600, function () use ($productType) {
            // Start with original term
            $searchTerms = [$productType];
            
            // First, find what product_type this term belongs to
            $matchedType = ProductSynonym::where('is_active', true)
                ->where(function ($q) use ($productType) {
                    $q->where('synonym', $productType)
                      ->orWhere('product_type', $productType);
                })
                ->value('product_type');
            
            if ($matchedType) {
                // Get all synonyms for this product_type
                $synonyms = ProductSynonym::where('is_active', true)
                    ->where('product_type', $matchedType)
                    ->pluck('synonym')
                    ->toArray();
                
                $searchTerms = array_merge($searchTerms, [$matchedType], $synonyms);
            }
            
            // Fallback: minimal hardcoded synonyms for critical cases (ua <-> ru spelling)
            $fallbackSynonyms = [
                'берці' => ['берці', 'берци', 'берцы'],
                'берци' => ['берці', 'берци', 'берцы'],
            ];
            
            foreach ($fallbackSynonyms as $key => $values) {
                if (str_contains($productType, $key) || in_array($productType, $values)) {
                    $searchTerms = array_merge($searchTerms, $values);
                    break;
                }
            }
            
            return array_unique(array_filter($searchTerms));
        });
    }

    /**
     * Fallback response when AI unavailable
     */
    private function fallbackResponse(string $message): array
    {
        // Simple keyword search
        $results = $this->searchTool->search($message, [], 5);
        
        if (!empty($results)) {
            $articles = array_column($results, 'article');
            $cards = $this->detailsTool->getCards($articles);
            
            return [
                'message' => 'Ось що я знайшов:',
                'products' => $cards ?: $results,
                'meta' => ['intent' => 'product_search', 'agent' => 'fallback', 'products_found' => count($results)],
            ];
        }

        return [
            'message' => 'Вибачте, не вдалося обробити запит. Спробуйте переформулювати.',
            'products' => [],
            'meta' => ['intent' => 'error', 'agent' => 'fallback', 'error' => 'No API key or API failed'],
        ];
    }

    /**
     * Load saved context summary for session
     */
    private function loadContextSummary(string $sessionId): ?string
    {
        $key = "chat_context_summary_{$sessionId}";
        return Cache::get($key);
    }

    /**
     * Save context summary for session
     * TTL: 2 hours (conversation lifespan)
     */
    private function saveContextSummary(string $sessionId, string $context): void
    {
        $key = "chat_context_summary_{$sessionId}";
        Cache::put($key, $context, 7200); // 2 hours
        
        Log::info('FunctionCallingAgent: saved context summary', [
            'session_id' => $sessionId,
            'context' => $context,
        ]);
    }

    /**
     * Extract and save _context from GPT response
     */
    private function extractAndSaveContext(string $sessionId, $response): void
    {
        if (!$sessionId) {
            return;
        }

        $context = null;

        // Try to extract from JSON response
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if ($decoded && isset($decoded['_context'])) {
                $context = $decoded['_context'];
            }
        } elseif (is_array($response) && isset($response['_context'])) {
            $context = $response['_context'];
        }

        if ($context && is_string($context) && mb_strlen($context) > 3) {
            $this->saveContextSummary($sessionId, $context);
        }
    }

    /**
     * Extract conversation context from message history
     * More reliable than GPT-generated _context
     */
    private function extractConversationContext(array $history): ?string
    {
        if (empty($history)) {
            return null;
        }

        $context = [];
        
        // Product patterns to detect what user is looking for
        $productPatterns = [
            'сорочк' => 'сорочку',
            'футболк' => 'футболку',
            'штан' => 'штани',
            'берц' => 'берці',
            'шолом' => 'шолом',
            'каск' => 'шолом/каску',
            'плитоноск' => 'плитоноску',
            'рюкзак' => 'рюкзак',
            'підсумок' => 'підсумок',
            'бронеплит' => 'бронеплиту',
            'форм' => 'форму',
            'куртк' => 'куртку',
            'взутт' => 'взуття',
            'рукавиц' => 'рукавиці',
            'окуляр' => 'окуляри',
            'термо' => 'термобілизну',
            'балістич' => 'балістичний захист',
            'вогнестійк' => 'вогнестійкий одяг',
            'подарун' => 'подарунок',
            'подар' => 'подарунок',
            'trident' => 'футболку TRIDENT',
            'ataka' => 'товар ATAKA',
            'кепк' => 'кепку',
            'шапк' => 'шапку',
            'кросівк' => 'кросівки',
            'черевик' => 'черевики',
            'жилет' => 'жилет',
            'бушлат' => 'бушлат',
            'парк' => 'парку',
            'худі' => 'худі',
            'світшот' => 'світшот',
        ];
        
        // Intent patterns (what user wants to do)
        $intentPatterns = [
            '/подарун\w*/ui' => 'шукає подарунок',
            '/для\s+(дружини|жінки|дівчини)/ui' => 'для жінки',
            '/для\s+(чоловіка|хлопця|друга)/ui' => 'для чоловіка',
            '/для\s+(сина|дочки|дитини)/ui' => 'для дитини',
            '/необмежен\w*\s*бюджет/ui' => 'бюджет необмежений',
            '/без\s*обмежень/ui' => 'бюджет необмежений',
            '/до\s*(\d+)\s*(грн|₴|тис)?/ui' => 'бюджет до $1',
            '/бюджет\s*(\d+)/ui' => 'бюджет $1',
            '/туризм/ui' => 'для туризму',
            '/полюванн/ui' => 'для полювання',
            '/служб/ui' => 'для служби',
            '/стріл/ui' => 'для стрільби',
        ];
        
        // Size patterns
        $sizePatterns = [
            '/розмір\s*(M|L|S|XL|XXL|\d{2,3})/ui' => 'розмір',
            '/зріст\s*(\d{2,3})/ui' => 'зріст',
            '/обхват\s*(грудей|талії)/ui' => 'обміри',
            '/вага\s*(\d{2,3})/ui' => 'вага',
        ];
        
        $foundProduct = null;
        $foundParams = [];
        $foundIntents = [];
        $shownProductCategory = null; // Track what category was shown
        
        foreach ($history as $msg) {
            $content = $msg['content'] ?? '';
            $contentLower = mb_strtolower($content);
            
            // Extract shown products from assistant messages (e.g., "[Показані товари: Футболка X, Футболка Y]")
            if ($msg['role'] === 'assistant') {
                // Check for shown products marker
                if (preg_match('/\[Показані товари:\s*(.+?)\]/ui', $content, $matches)) {
                    $shownProducts = $matches[1];
                    // Detect category from shown products
                    if (preg_match('/футболк/ui', $shownProducts)) {
                        $shownProductCategory = 'футболки';
                    } elseif (preg_match('/штан/ui', $shownProducts)) {
                        $shownProductCategory = 'штани';
                    } elseif (preg_match('/плитоноск/ui', $shownProducts)) {
                        $shownProductCategory = 'плитоноски';
                    } elseif (preg_match('/берц|черевик/ui', $shownProducts)) {
                        $shownProductCategory = 'взуття';
                    } elseif (preg_match('/рюкзак/ui', $shownProducts)) {
                        $shownProductCategory = 'рюкзаки';
                    } elseif (preg_match('/шолом|каск/ui', $shownProducts)) {
                        $shownProductCategory = 'шоломи';
                    } elseif (preg_match('/сорочк/ui', $shownProducts)) {
                        $shownProductCategory = 'сорочки';
                    } elseif (preg_match('/куртк/ui', $shownProducts)) {
                        $shownProductCategory = 'куртки';
                    }
                }
                
                // Also check assistant text for product mentions (when describing products)
                foreach ($productPatterns as $pattern => $name) {
                    if (mb_strpos($contentLower, $pattern) !== false) {
                        // Only set if user hasn't explicitly mentioned something else
                        if (!$foundProduct) {
                            $foundProduct = $name;
                        }
                    }
                }
            }
            
            // Look for product mentions in user messages
            if ($msg['role'] === 'user') {
                foreach ($productPatterns as $pattern => $name) {
                    if (mb_strpos($contentLower, $pattern) !== false) {
                        $foundProduct = $name;
                    }
                }
                
                // Look for intents
                foreach ($intentPatterns as $pattern => $intent) {
                    if (preg_match($pattern, $content, $matches)) {
                        // Replace $1 with captured group if exists
                        $intentValue = $intent;
                        if (isset($matches[1]) && strpos($intent, '$1') !== false) {
                            $intentValue = str_replace('$1', $matches[1], $intent);
                        }
                        $foundIntents[$intentValue] = true;
                    }
                }
                
                // Look for size/params
                foreach ($sizePatterns as $pattern => $paramType) {
                    if (preg_match($pattern, $content, $matches)) {
                        $foundParams[$paramType] = $matches[1] ?? $matches[0];
                    }
                }
                
                // Extract height specifically
                if (preg_match('/(\d{3})\s*(см)?/u', $content, $matches)) {
                    $height = (int) $matches[1];
                    if ($height >= 150 && $height <= 210) {
                        $foundParams['зріст'] = $height . ' см';
                    }
                }
            }
        }
        
        // Build context string
        if ($foundProduct) {
            $context[] = "шукає {$foundProduct}";
        } elseif ($shownProductCategory) {
            // If no explicit product mentioned but we showed products - use that category
            $context[] = "обговорюємо {$shownProductCategory}";
        }
        
        // Add shown product category as separate context if exists
        if ($shownProductCategory && $foundProduct) {
            $context[] = "показані {$shownProductCategory}";
        }
        
        // Add found intents
        if (!empty($foundIntents)) {
            $context = array_merge($context, array_keys($foundIntents));
        }
        
        if (!empty($foundParams)) {
            $paramsStr = implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($foundParams), array_values($foundParams)));
            $context[] = $paramsStr;
        }
        
        if (empty($context)) {
            return null;
        }
        
        return implode('; ', $context);
    }
}
