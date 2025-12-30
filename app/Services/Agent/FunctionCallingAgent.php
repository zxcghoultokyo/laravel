<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
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

        // Direct text response (small talk, FAQ)
        $text = $response['choices'][0]['message']['content'] ?? '';
        return [
            'message' => $text,
            'products' => [],
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
        return <<<PROMPT
Ти — AIntento, AI-консультант магазину тактичного спорядження "Contractor".

СЛОВНИК ТОВАРІВ:
- "плити", "бронеплити" → search_products(query="бронеплита ESAPI керамічна", exclude="бокова")
- "бокові плити" → search_products(query="бокова бронеплита")
- "плитоноска", "розгрузка", "plate carrier" → search_products(query="плитоноска plate carrier")
- "шолом сестан буш" → search_products(query="SESTAN BUSCH шолом")
- "опс коре" → search_products(query="Ops-Core")
- "салоні берці" → search_products(query="Salomon берці")
- "аптечка" → search_products(query="аптечка IFAK", exclude="скотч підсумок")

ВАЖЛИВО:
- "плити" = ОСНОВНІ грудні/спинні бронеплити (ESAPI, керамічні), НЕ бокові!
- "аптечка" = сама аптечка, не підсумок під аптечку і не скотч
- Якщо користувач відповідає на твоє питання (напр. "на плитоноску") - пам'ятай контекст розмови!

ТИ МАЄШ ІСТОРІЮ ЧАТУ - використовуй її для контексту!

КОЛИ ЯКИЙ ІНСТРУМЕНТ:
- Шукають конкретний товар → search_products з query
- "подарунок", "що порадиш", "топ" без контексту → get_popular_products
- Питають про замовлення → get_order_status

ФОРМАТ ВІДПОВІДІ (JSON):
Відповідай ТІЛЬКИ у форматі JSON:
{
  "intro": "Ось варіанти:",
  "products": [
    {"article": "xxx", "comment": "Коротко чому цей"},
    {"article": "yyy", "comment": "Коротко чому цей"}
  ]
}

ПРАВИЛА:
- intro: 1 коротке речення (5-10 слів), без зайвих питань
- comment: 1 коротке речення про товар
- НЕ пиши "напиши бюджет", "уточни розмір" - тільки якщо дійсно потрібно
- НЕ пиши характеристики - вони є в картці
PROMPT;
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
                    'description' => 'Перевірити статус замовлення за номером.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id' => [
                                'type' => 'string',
                                'description' => 'Номер замовлення',
                            ],
                        ],
                        'required' => ['order_id'],
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

        // Exclude products by keyword in title
        if (!empty($args['exclude']) && !empty($results)) {
            $exclude = mb_strtolower($args['exclude']);
            $results = array_filter($results, function ($p) use ($exclude) {
                $title = mb_strtolower($p['title'] ?? '');
                return !str_contains($title, $exclude);
            });
            $results = array_values($results);
        }

        // Filter by product_type if specified (using ai_product_type)
        if (!empty($args['product_type']) && !empty($results)) {
            $productType = mb_strtolower($args['product_type']);
            $results = array_filter($results, function ($p) use ($productType) {
                $aiType = mb_strtolower($p['ai_product_type'] ?? '');
                return str_contains($aiType, $productType);
            });
            $results = array_values($results);
        }

        // Filter by color if specified
        if (!empty($args['color']) && !empty($results)) {
            $color = mb_strtolower($args['color']);
            $results = array_filter($results, function ($p) use ($color) {
                $title = mb_strtolower($p['title'] ?? '');
                $attrs = mb_strtolower($p['color'] ?? '');
                return str_contains($title, $color) || str_contains($attrs, $color);
            });
            $results = array_values($results);
        }

        // Limit results after filtering
        $results = array_slice($results, 0, $limit);

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
     */
    private function toolGetPopularProducts(array $args): array
    {
        $category = $args['category'] ?? null;
        $limit = $args['limit'] ?? 5;

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
     * Tool: Get order status
     */
    private function toolGetOrderStatus(array $args): array
    {
        $orderId = $args['order_id'] ?? '';
        
        if (empty($orderId)) {
            return ['error' => 'Order ID required'];
        }

        try {
            $result = $this->orderSearchService->searchByOrderId($orderId);
            return [
                'orders' => $result['orders'] ?? [],
                'found' => $result['found'] ?? 0,
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Could not find order'];
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
