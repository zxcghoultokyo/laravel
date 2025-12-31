<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
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

    public function __construct(
        private MeiliProductSearchTool $searchTool,
        private ProductDetailsTool $detailsTool,
        private OrderSearchService $orderSearchService,
    ) {
        $config = config('services.openai', []);
        $this->apiKey = $config['key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4o';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
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
        // If so, just extract the intro as plain text
        if (preg_match('/^\s*\{/u', $text)) {
            $json = json_decode($text, true);
            if ($json && isset($json['intro'])) {
                $text = $json['intro'];
                // If it has product comments, append them
                if (!empty($json['products'])) {
                    foreach ($json['products'] as $p) {
                        if (!empty($p['comment'])) {
                            $text .= "\n• " . $p['comment'];
                        }
                    }
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
     */
    private function buildMessages(string $message, array $context): array
    {
        $systemPrompt = $this->getSystemPrompt();
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add conversation history if available
        $history = $context['history'] ?? [];
        foreach ($history as $msg) {
            $messages[] = $msg;
        }

        // Add current message
        $messages[] = ['role' => 'user', 'content' => $message];

        return $messages;
    }

    /**
     * System prompt - the brain of the agent
     */
    private function getSystemPrompt(): string
    {
        // Load FAQ info from settings
        $faqInfo = $this->loadFaqInfo();
        
        $basePrompt = <<<PROMPT
Ти — AIntento, AI-консультант магазину тактичного спорядження "Contractor".

МУЛЬТИМОВНІСТЬ:
- ЗАВЖДИ відповідай МОВОЮ КОРИСТУВАЧА (якщо пише англійською — відповідай англійською, польською — польською і т.д.)
- При пошуку товарів (search_products) ЗАВЖДИ використовуй УКРАЇНСЬКУ для query, бо каталог українською
- Приклад: користувач пише "plate carrier multicam" → search_products(query: "плитоноска мультикам") → відповідь англійською
- Картки товарів залишаться українською — це нормально, клієнт бачить оригінальні назви
- Коментарі до товарів пиши МОВОЮ КОРИСТУВАЧА!

ГОЛОВНЕ ПРАВИЛО: ЗАВЖДИ ШУКАЙ ЧЕРЕЗ search_products!
Не кажи "цього немає" поки не перевіриш пошуком. В магазині є багато товарів: плитоноски, шоломи, берці, бронеплити, рюкзаки, підсумки, аптечки, рукавиці, форма, фарби, та інше.

АВТОВИПРАВЛЕННЯ (виправляй помилки і шукай):
- плитноска, плейткерієр → плитоноска
- опс кор, опскор → Ops-Core
- сестан буш → SESTAN BUSCH
- берци, ботінки → берці
- шлем, каска → шолом
- разгрузка → плитоноска
- подсумок → підсумок

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

НОВИЙ ЗАПИТ (шукай ТІЛЬКИ новий товар, НЕ повторюй попередній!):
- "і хочу кавер" → search_products(query="кавер") - ТІЛЬКИ кавери!
- "а ще берці" → search_products(query="берці") - ТІЛЬКИ берці!
- "також потрібен рюкзак" → search_products(query="рюкзак")
НЕ ВИКЛИКАЙ search_products для товарів що вже показані!

ФОРМАТ ВІДПОВІДІ:
1. ПІСЛЯ search_products → JSON: {"intro": "...", "products": [{"article": "xxx", "comment": "..."}]}
2. Текстові питання → КОРОТКИЙ текст (2-3 речення!)
3. Нічого не знайдено → "На жаль, не знайшов. Спробуй інакше сформулювати."

СТИЛІСТИКА:
- Пиши природно, як жива людина, НЕ як робот
- Уникай повторення одного слова — використовуй займенники (вона, він, це, ця)
- НЕ починай з назви товару, якщо вона вже є в питанні

ЛАКОНІЧНІСТЬ:
- Максимум 2-3 речення
- НЕ питай бюджет/розмір без потреби
- НЕ читай лекції

ЗАМОВЛЕННЯ:
- Коли показуєш деталі замовлення - показуй ВСЕ одразу (товари, статус, доставку)
- НЕ пропонуй "можу скинути посилання" - ти не маєш прямого посилання на товар з замовлення!
- Якщо клієнт хоче товар з замовлення в каталозі - ОДРАЗУ шукай через search_products по назві товару
- Не пропонуй те, що не можеш зробити!

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
                    'description' => 'Отримати популярні товари. Використовуй для "подарунок", "топ", "що порадиш", "популярне", "хіт продажів".',
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
        $limit = $args['limit'] ?? 10;
        
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
     * Cached for 5 minutes to reduce response time
     */
    private function toolGetPopularProducts(array $args): array
    {
        $category = $args['category'] ?? null;
        $limit = $args['limit'] ?? 5;

        // Cache key based on category and limit
        $cacheKey = 'popular_products:' . ($category ?? 'all') . ':' . $limit;
        
        // Cache popular products for 5 minutes
        return Cache::remember($cacheKey, 300, function () use ($category, $limit) {
            $products = [];

            if ($category) {
                // Search in specific category
                $results = $this->searchTool->search($category, [], $limit * 2);
                
                // Sort by popularity
                usort($results, function ($a, $b) {
                    $popA = ($a['popularity'] ?? 0) + (($a['orders_count'] ?? 0) * 10);
                    $popB = ($b['popularity'] ?? 0) + (($b['orders_count'] ?? 0) * 10);
                    return $popB <=> $popA;
                });
                
                $products = array_slice($results, 0, $limit);
            } else {
                // Get top from different categories
                $categories = ['плитоноска', 'шолом', 'берці', 'рюкзак'];
                
                foreach ($categories as $cat) {
                    $results = $this->searchTool->search($cat, [], 5);
                    if (!empty($results)) {
                        usort($results, function ($a, $b) {
                            $popA = ($a['popularity'] ?? 0) + (($a['orders_count'] ?? 0) * 10);
                            $popB = ($b['popularity'] ?? 0) + (($b['orders_count'] ?? 0) * 10);
                            return $popB <=> $popA;
                        });
                        $products[] = $results[0];
                    }
                    
                    if (count($products) >= $limit) {
                        break;
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
}
