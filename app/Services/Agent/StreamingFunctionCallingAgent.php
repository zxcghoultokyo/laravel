<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
use App\Models\WidgetSettings;
use App\Models\ProductSynonym;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Generator;

/**
 * Streaming version of FunctionCallingAgent.
 * Uses OpenAI's streaming API to deliver responses chunk by chunk.
 * 
 * Usage:
 * ```php
 * $agent = app(StreamingFunctionCallingAgent::class);
 * foreach ($agent->stream($message, $sessionId) as $event) {
 *     // $event = ['type' => 'chunk|products|status|done|error', 'data' => [...]]
 *     echo "data: " . json_encode($event) . "\n\n";
 *     flush();
 * }
 * ```
 */
class StreamingFunctionCallingAgent
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private MeiliProductSearchTool $searchTool;
    private ProductDetailsTool $detailsTool;
    private OrderSearchService $orderSearchService;

    public function __construct(
        MeiliProductSearchTool $searchTool,
        ProductDetailsTool $detailsTool,
        OrderSearchService $orderSearchService
    ) {
        $this->apiKey = config('services.openai.key', '');
        $this->model = config('services.openai.model', 'gpt-4.1');
        $this->baseUrl = rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $this->searchTool = $searchTool;
        $this->detailsTool = $detailsTool;
        $this->orderSearchService = $orderSearchService;
    }

    /**
     * Stream response as a generator.
     * Yields events that can be sent to the client via SSE.
     * 
     * @return Generator<array{type: string, data: array}>
     */
    public function stream(string $message, ?string $sessionId = null): Generator
    {
        if (empty($this->apiKey)) {
            Log::warning('StreamingAgent: no API key, using fallback');
            yield from $this->fallbackStream($message);
            return;
        }

        // Build conversation
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt()],
            ['role' => 'user', 'content' => $message],
        ];

        yield ['type' => 'status', 'data' => ['text' => 'Аналізую запит...', 'phase' => 'thinking']];

        // First call: may need tool calls
        $response = $this->callGptWithTools($messages);
        
        if (!$response) {
            yield from $this->fallbackStream($message);
            return;
        }

        $assistantMessage = $response['choices'][0]['message'] ?? null;
        
        // Check if GPT wants to call tools
        if (!empty($assistantMessage['tool_calls'])) {
            yield ['type' => 'status', 'data' => ['text' => 'Шукаю товари...', 'phase' => 'searching']];
            
            // Execute tools
            $toolResults = [];
            $allProducts = [];
            
            foreach ($assistantMessage['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'];
                $args = json_decode($toolCall['function']['arguments'], true) ?? [];
                
                Log::info('StreamingAgent: executing tool', [
                    'function' => $functionName,
                    'args' => $args,
                ]);
                
                $result = $this->executeTool($functionName, $args);
                
                // Collect products
                if (in_array($functionName, ['search_products', 'get_popular_products']) && !empty($result['products'])) {
                    $allProducts = array_merge($allProducts, $result['products']);
                }
                
                $toolResults[] = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
            
            // Build final messages array
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => $assistantMessage['tool_calls'],
            ];
            
            foreach ($toolResults as $result) {
                $messages[] = $result;
            }
            
            // Dedupe products
            $allProducts = $this->dedupeProducts($allProducts);
            
            // Stream final response
            yield ['type' => 'status', 'data' => ['text' => 'Готую відповідь...', 'phase' => 'generating']];
            
            $collectedText = '';
            $sentChunks = false;
            
            foreach ($this->streamGptResponse($messages) as $chunk) {
                if ($chunk['type'] === 'content') {
                    $collectedText .= $chunk['text'];
                    yield ['type' => 'chunk', 'data' => ['text' => $chunk['text']]];
                    $sentChunks = true;
                }
            }
            
            // Parse the collected response for structured output
            $structured = $this->parseStructuredResponse($collectedText, $allProducts);
            
            // If no text was streamed but we have intro, send it
            if (!$sentChunks && !empty($structured['intro'])) {
                yield ['type' => 'chunk', 'data' => ['text' => $structured['intro']]];
            }
            
            // Send products
            if (!empty($structured['products'])) {
                yield ['type' => 'products', 'data' => [
                    'products' => $structured['products'],
                    'count' => count($structured['products']),
                ]];
            }
            
            // Send outro if exists
            if (!empty($structured['outro'])) {
                yield ['type' => 'chunk', 'data' => ['text' => "\n\n" . $structured['outro']]];
            }
            
        } else {
            // No tool calls - just stream the text response
            $content = $assistantMessage['content'] ?? '';
            
            if (!empty($content)) {
                // Already have the full response from non-streaming call
                // Let's do a streaming call for better UX
                foreach ($this->streamGptResponse($messages) as $chunk) {
                    if ($chunk['type'] === 'content') {
                        yield ['type' => 'chunk', 'data' => ['text' => $chunk['text']]];
                    }
                }
            }
        }
        
        yield ['type' => 'done', 'data' => ['session_id' => $sessionId]];
    }

    /**
     * Stream GPT response with OpenAI streaming API.
     * 
     * @return Generator<array{type: string, text?: string}>
     */
    private function streamGptResponse(array $messages): Generator
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->withOptions(['stream' => true])
                ->timeout(60)
                ->post($this->baseUrl . '/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'stream' => true,
                    'temperature' => 0.3,
                ]);

            $body = $response->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $buffer .= $body->read(1024);
                
                // Process complete SSE messages
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    
                    $line = trim($line);
                    
                    if (empty($line)) {
                        continue;
                    }
                    
                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }
                    
                    $data = substr($line, 6);
                    
                    if ($data === '[DONE]') {
                        return;
                    }
                    
                    $json = json_decode($data, true);
                    
                    if (!$json) {
                        continue;
                    }
                    
                    $delta = $json['choices'][0]['delta'] ?? [];
                    
                    if (isset($delta['content'])) {
                        yield ['type' => 'content', 'text' => $delta['content']];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('StreamingAgent: streaming error', ['error' => $e->getMessage()]);
            yield ['type' => 'error', 'text' => 'Помилка генерації'];
        }
    }

    /**
     * Call GPT with tools (non-streaming for tool detection).
     */
    private function callGptWithTools(array $messages): ?array
    {
        try {
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
            
            if (isset($data['error'])) {
                Log::error('StreamingAgent: OpenAI error', ['error' => $data['error']]);
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('StreamingAgent: API error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get system prompt (same as FunctionCallingAgent).
     */
    private function getSystemPrompt(): string
    {
        $faqInfo = $this->loadFaqInfo();
        
        return <<<PROMPT
Ти — AI-продавець магазину "Contractor" (contractor.kiev.ua). Твоя мета — допомогти клієнту КУПИТИ товар з каталогу.

ВАЖЛИВО: ВІДПОВІДАЙ КОРОТКО (2-3 речення максимум)!

ГОЛОВНЕ ПРАВИЛО:
- НІКОЛИ не радь і не згадуй товари яких НЕМАЄ в каталозі!
- Якщо клієнт питає про товар якого немає (павербанк, зброя, боєприпаси тощо) — скажи "цього немає в нашому асортименті" і запропонуй те що Є
- Ти працюєш НА МАГАЗИН — твоя задача продавати ТЕ ЩО Є, а не давати загальні поради

КОЛИ ОДРАЗУ ПОКАЗУВАТИ ТОВАРИ (search_products):
- "я військовий", "що потрібно на фронті", "базовий набір" → search_products("тактичне спорядження") і показуй реальні товари
- Будь-який запит про категорію товарів → одразу search_products()
- НЕ давай "загальні поради" типу "вам потрібен павербанк" — показуй тільки ТЕ ЩО МОЖЕШ ПРОДАТИ

МУЛЬТИМОВНІСТЬ:
- ЗАВЖДИ відповідай МОВОЮ КОРИСТУВАЧА
- При пошуку (search_products) ЗАВЖДИ використовуй УКРАЇНСЬКУ для query

АЛГОРИТМ:
1. Запит про товар → search_products() → JSON {"intro": "...", "products": [...]}
2. "Топ товари", "популярне" → get_popular_products()
3. Замовлення → get_order_status()
4. Загальне питання про магазин → короткий текст з FAQ

ФОРМАТ ВІДПОВІДІ ПІСЛЯ search_products:
{"intro": "Короткий опис (1 речення)", "products": [{"article": "...", "comment": "чому підходить"}], "outro": "Опційно"}

ПРАВИЛА:
- НЕ вигадуй товари — ТІЛЬКИ результати search_products
- Показуй 3-5 РІЗНИХ моделей
- Якщо товару немає в результатах — НЕ згадуй його взагалі!

ІНФОРМАЦІЯ ПРО МАГАЗИН:
{$faqInfo}
PROMPT;
    }

    /**
     * Load FAQ info from WidgetSettings.
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

        if (!empty($settings->shop_phone)) {
            $info[] = "ТЕЛЕФОН: {$settings->shop_phone}";
        }
        if (!empty($settings->faq_contacts_text)) {
            $info[] = "КОНТАКТИ:\n{$settings->faq_contacts_text}";
        }
        if (!empty($settings->faq_payment_delivery_text)) {
            $info[] = "ОПЛАТА ТА ДОСТАВКА:\n{$settings->faq_payment_delivery_text}";
        }

        return empty($info) ? "Актуальну інформацію дивіться на сайті contractor.kiev.ua" : implode("\n\n", $info);
    }

    /**
     * Get tools definition.
     */
    private function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products',
                    'description' => 'Пошук товарів в каталозі.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Пошуковий запит'],
                            'product_type' => ['type' => 'string', 'description' => 'Тип товару'],
                            'brand' => ['type' => 'string', 'description' => 'Бренд'],
                            'price_min' => ['type' => 'number', 'description' => 'Мін. ціна'],
                            'price_max' => ['type' => 'number', 'description' => 'Макс. ціна'],
                            'color' => ['type' => 'string', 'description' => 'Колір'],
                            'exclude' => ['type' => 'string', 'description' => 'Виключити слово'],
                            'limit' => ['type' => 'integer', 'description' => 'Кількість результатів'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_popular_products',
                    'description' => 'Отримати популярні товари.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'Категорія'],
                            'limit' => ['type' => 'integer', 'description' => 'Кількість'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_product_details',
                    'description' => 'Детальна інформація про товар.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => ['type' => 'string', 'description' => 'Артикул'],
                        ],
                        'required' => ['article'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_order_status',
                    'description' => 'Перевірити статус замовлення.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id' => ['type' => 'string', 'description' => 'Номер замовлення'],
                            'phone' => ['type' => 'string', 'description' => 'Телефон'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Execute a tool.
     */
    private function executeTool(string $name, array $args): array
    {
        return match ($name) {
            'search_products' => $this->toolSearchProducts($args),
            'get_popular_products' => $this->toolGetPopularProducts($args),
            'get_product_details' => $this->toolGetProductDetails($args),
            'get_order_status' => $this->toolGetOrderStatus($args),
            default => ['error' => 'Unknown tool'],
        };
    }

    /**
     * Search products tool.
     */
    private function toolSearchProducts(array $args): array
    {
        $query = $args['query'] ?? '';
        $limit = $args['limit'] ?? 20; // Increased to show more variety
        
        $filters = [];
        if (!empty($args['price_min'])) $filters['price_min'] = (float) $args['price_min'];
        if (!empty($args['price_max'])) $filters['price_max'] = (float) $args['price_max'];
        if (!empty($args['brand'])) $filters['brand'] = $args['brand'];

        $results = $this->searchTool->search($query, $filters, $limit * 3);

        // Filter by exclude
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
            $results = array_filter($results, function ($p) use ($color) {
                $searchText = mb_strtolower(($p['title'] ?? '') . ' ' . ($p['color'] ?? ''));
                return str_contains($searchText, $color);
            });
            $results = array_values($results);
        }

        $results = array_slice($results, 0, $limit);

        // Get full product cards
        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $cards = $this->detailsTool->getCards($ids);
            if (!empty($cards)) $results = $cards;
        }

        return ['products' => $results, 'count' => count($results), 'query' => $query];
    }

    /**
     * Get popular products tool.
     */
    private function toolGetPopularProducts(array $args): array
    {
        $category = $args['category'] ?? null;
        $limit = $args['limit'] ?? 5;
        $cacheKey = 'popular_products_v4:' . ($category ?? 'all') . ':' . $limit;
        
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
                
                // Exclude expensive items (>15k) - not mass market
                if ($price > 15000) {
                    return false;
                }
                
                // Must be in stock
                if (!($p['in_stock'] ?? false)) {
                    return false;
                }
                
                return true;
            };

            if ($category) {
                $results = $this->searchTool->search($category, [], $limit * 3);
                $results = array_filter($results, $filterProduct);
                usort($results, fn($a, $b) => 
                    (($b['popularity'] ?? 0) + (($b['orders_count'] ?? 0) * 10)) <=> 
                    (($a['popularity'] ?? 0) + (($a['orders_count'] ?? 0) * 10))
                );
                $products = array_slice($results, 0, $limit);
            } else {
                // Curated popular queries - affordable, common items
                $popularQueries = [
                    'плитоноска НАТО',    // Basic plate carrier
                    'підсумок магазин',   // Magazine pouches
                    'рукавички тактичні', // Tactical gloves
                    'аптечка ІФАК',       // First aid
                ];
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
                        $products[] = array_values($results)[0];
                    }
                    if (count($products) >= $limit) break;
                }
            }

            if (!empty($products)) {
                $ids = array_column($products, 'id');
                $cards = $this->detailsTool->getCards($ids);
                if (!empty($cards)) $products = $cards;
            }

            return ['products' => array_slice($products, 0, $limit), 'count' => count($products)];
        });
    }

    /**
     * Get product details tool.
     */
    private function toolGetProductDetails(array $args): array
    {
        $article = $args['article'] ?? '';
        if (empty($article)) return ['error' => 'Article required'];

        $product = Product::where('article', $article)->first();
        if (!$product) return ['error' => 'Product not found'];

        return [
            'product' => [
                'title' => $product->title,
                'article' => $product->article,
                'price' => $product->price,
                'brand' => $product->brand,
                'in_stock' => $product->in_stock,
                'link' => $product->link,
            ],
        ];
    }

    /**
     * Get order status tool.
     */
    private function toolGetOrderStatus(array $args): array
    {
        if (!$this->orderSearchService->isAvailable()) {
            return ['error' => 'Пошук замовлень тимчасово недоступний'];
        }
        
        $criteria = [];
        if (!empty($args['order_id'])) $criteria['order_id'] = $args['order_id'];
        if (!empty($args['phone'])) {
            $normalized = preg_replace('/[\s\-\(\)]+/', '', $args['phone']);
            if (!str_starts_with($normalized, '+38')) {
                $normalized = str_starts_with($normalized, '38') ? '+' . $normalized : '+38' . $normalized;
            }
            $criteria['phone'] = $normalized;
        }
        
        if (empty($criteria)) return ['error' => 'Потрібен номер замовлення або телефон'];

        try {
            $result = $this->orderSearchService->search($criteria);
            return ['orders' => $result['orders'] ?? [], 'found' => $result['total'] ?? 0];
        } catch (\Throwable $e) {
            return ['error' => 'Не вдалось знайти замовлення'];
        }
    }

    /**
     * Deduplicate products.
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
     * Get product type synonyms.
     */
    private function getProductTypeSynonyms(string $productType): array
    {
        $cacheKey = 'product_type_synonyms:' . md5($productType);
        
        return Cache::remember($cacheKey, 3600, function () use ($productType) {
            $searchTerms = [$productType];
            
            $matchedType = ProductSynonym::where('is_active', true)
                ->where(fn($q) => $q->where('synonym', $productType)->orWhere('product_type', $productType))
                ->value('product_type');
            
            if ($matchedType) {
                $synonyms = ProductSynonym::where('is_active', true)
                    ->where('product_type', $matchedType)
                    ->pluck('synonym')
                    ->toArray();
                $searchTerms = array_merge($searchTerms, [$matchedType], $synonyms);
            }
            
            return array_unique(array_filter($searchTerms));
        });
    }

    /**
     * Parse structured response from GPT.
     */
    private function parseStructuredResponse(string $responseText, array $allProducts): array
    {
        $json = null;
        if (preg_match('/\{[\s\S]*\}/u', $responseText, $matches)) {
            $json = json_decode($matches[0], true);
        }

        $productsByArticle = [];
        foreach ($allProducts as $p) {
            $productsByArticle[$p['article']] = $p;
        }

        if ($json && isset($json['products']) && is_array($json['products'])) {
            $orderedProducts = [];
            foreach ($json['products'] as $item) {
                $article = $item['article'] ?? '';
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
                    $product['comment'] = $item['comment'] ?? '';
                    $orderedProducts[] = $product;
                }
            }
            
            return [
                'intro' => $json['intro'] ?? '',
                'outro' => $json['outro'] ?? null,
                'products' => !empty($orderedProducts) ? $orderedProducts : array_slice($allProducts, 0, 5),
            ];
        }
        
        return [
            'intro' => $responseText,
            'outro' => null,
            'products' => array_slice($allProducts, 0, 5),
        ];
    }

    /**
     * Fallback stream when API unavailable.
     */
    private function fallbackStream(string $message): Generator
    {
        $results = $this->searchTool->search($message, [], 5);
        
        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $cards = $this->detailsTool->getCards($ids);
            
            yield ['type' => 'chunk', 'data' => ['text' => 'Ось що я знайшов:']];
            yield ['type' => 'products', 'data' => [
                'products' => $cards ?: $results,
                'count' => count($results),
            ]];
        } else {
            yield ['type' => 'chunk', 'data' => ['text' => 'Вибачте, не вдалося знайти товари. Спробуйте іншу назву.']];
        }
        
        yield ['type' => 'done', 'data' => []];
    }
}
