<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Ai\ToneService;
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
    private ToneService $toneService;

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
        $this->toneService = app(ToneService::class);
    }

    /**
     * Stream response as a generator.
     * Yields events that can be sent to the client via SSE.
     * 
     * @return Generator<array{type: string, data: array}>
     */
    public function stream(string $message, ?string $sessionId = null): Generator
    {
        // Log user message to DB
        $this->logUserMessage($sessionId, $message);
        
        // Track response data for logging
        $responseText = '';
        $responseProducts = [];
        $responseIntent = 'streaming';
        
        if (empty($this->apiKey)) {
            Log::warning('StreamingAgent: no API key, using fallback');
            yield from $this->fallbackStream($message);
            return;
        }

        // Load conversation history for context
        $history = $this->loadConversationHistory($sessionId);
        $conversationContext = $this->extractConversationContext($history);
        
        Log::info('StreamingAgent: loaded history', [
            'session_id' => $sessionId,
            'history_count' => count($history),
            'context' => $conversationContext,
        ]);

        // Build conversation with history
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt()],
        ];
        
        // Add context hint if we have one
        if ($conversationContext) {
            $messages[] = [
                'role' => 'system', 
                'content' => "[КОНТЕКСТ РОЗМОВИ: {$conversationContext}]\nПАМ'ЯТАЙ ЦЕЙ КОНТЕКСТ! Не питай користувача що він шукає якщо це вже відомо з контексту!"
            ];
        }
        
        // Add history messages
        foreach ($history as $msg) {
            $messages[] = $msg;
        }
        
        // Add current message
        $messages[] = ['role' => 'user', 'content' => $message];

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
                
                // Collect products from various tools
                if (in_array($functionName, ['search_products', 'get_popular_products']) && !empty($result['products'])) {
                    $allProducts = array_merge($allProducts, $result['products']);
                }
                // Handle single product from get_product_details
                if ($functionName === 'get_product_details' && !empty($result['product'])) {
                    $allProducts[] = $result['product'];
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
            
            // Collect full response first (don't stream JSON chunks to client)
            // GPT returns JSON like {"intro":"...", "products":[...], "outro":"..."}
            // We need to parse it and send intro as text, products as structured data
            $collectedText = '';
            
            foreach ($this->streamGptResponse($messages) as $chunk) {
                if ($chunk['type'] === 'content') {
                    $collectedText .= $chunk['text'];
                }
            }
            
            // Parse the collected response for structured output
            $structured = $this->parseStructuredResponse($collectedText, $allProducts);
            
            // Track for logging
            $responseText = $structured['intro'] ?? $collectedText;
            $responseProducts = $structured['products'] ?? [];
            $responseIntent = 'product_search';
            
            // Send intro text (NOT the raw JSON!)
            if (!empty($structured['intro'])) {
                // Stream intro character by character for typing effect
                $introChunks = mb_str_split($structured['intro'], 3);
                foreach ($introChunks as $chunk) {
                    yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                    usleep(10000); // 10ms delay for typing effect
                }
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
                $responseText .= "\n\n" . $structured['outro'];
            }
            
        } else {
            // No tool calls - just stream the text response
            $content = $assistantMessage['content'] ?? '';
            $collectedText = '';
            
            if (!empty($content)) {
                // Already have the full response from non-streaming call
                // Let's do a streaming call for better UX
                foreach ($this->streamGptResponse($messages) as $chunk) {
                    if ($chunk['type'] === 'content') {
                        $collectedText .= $chunk['text'];
                        yield ['type' => 'chunk', 'data' => ['text' => $chunk['text']]];
                    }
                }
            }
            
            $responseText = $collectedText ?: $content;
            $responseIntent = 'general';
        }
        
        // Log assistant message to DB
        $this->logAssistantMessage($sessionId, $responseText, $responseProducts, $responseIntent);
        
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
        $toneSection = $this->toneService->getFullPromptSection();
        $priceContext = $this->loadPriceContext();
        
        return <<<PROMPT
Ти — AI-продавець магазину "Contractor" (contractor.kiev.ua). Твоя мета — допомогти клієнту КУПИТИ товар з каталогу.

ВАЖЛИВО: ВІДПОВІДАЙ КОРОТКО (2-3 речення максимум)!

ГОЛОВНЕ ПРАВИЛО:
- НІКОЛИ не радь і не згадуй товари яких НЕМАЄ в каталозі!
- Якщо клієнт питає про товар якого немає — скажи "цього немає в нашому асортименті" і запропонуй те що Є
- Ти працюєш НА МАГАЗИН — твоя задача продавати ТЕ ЩО Є, а не давати загальні поради

КОНТЕКСТ РОЗМОВИ - ДУЖЕ ВАЖЛИВО:
- В історії чату є маркери [Показані товари: ...] з артикулами
- Якщо клієнт каже "розкажи про нього/це/останнє/перше" — ШУКай артикул в маркері [Показані товари:]
- "Аптечка" = набір з турнікета, бандажа, пластира якщо вони були в показаних товарах
- При "розкажи детальніше" — використай get_product_details(article) з контексту!
- НІКОЛИ не кажи "я не пропонував" якщо товари є в [Показані товари:]!

КОЛИ ОДРАЗУ ПОКАЗУВАТИ ТОВАРИ (search_products):
- "я військовий", "що потрібно на фронті", "базовий набір" → search_products("тактичне спорядження")
- Будь-який запит про категорію товарів → одразу search_products()
- НЕ давай "загальні поради" — показуй тільки ТЕ ЩО МОЖЕШ ПРОДАТИ

СЕЗОННІ ЗАПИТИ - ОБОВ'ЯЗКОВО ПОШУК:
- "що беруть зимою/взимку" → search_products("зимовий одяг куртка термобілизна") - НЕ get_popular_products!
- "що беруть влітку" → search_products("літній одяг футболка") 
- "що актуально зараз" → search_products з урахуванням сезону (грудень-лютий = зима)
- Сезонні питання = ПОШУК конкретних товарів для сезону, а не загальний топ!

КРИТИЧНО ВАЖЛИВО ДЛЯ ПОШУКУ:
- При search_products ЗБЕРІГАЙ ОРИГІНАЛЬНІ НАЗВИ моделей/брендів (TRIDENT, Mechanix, Ops-Core тощо) — НЕ перекладай їх!
- Приклад: "футболка трайдент" → search_products(query: "футболка TRIDENT") — TRIDENT залишаєш англійською!
- Загальні слова можна українською: "плитоноска", "шолом", "берці"

МУЛЬТИМОВНІСТЬ:
- ЗАВЖДИ відповідай МОВОЮ КОРИСТУВАЧА

РОЗМІРИ ТА ПАРАМЕТРИ КЛІЄНТА:
- БЕЗ ТАБЛИЦІ РОЗМІРІВ у картці товару — НЕ ДАВАЙ конкретних рекомендацій по розміру!
- Замість вигадування скажи: "Рекомендую уточнити розмір у менеджера за телефоном +380 63 631 9919"
- Якщо клієнт дає НЕРЕАЛІСТИЧНІ параметри (напр. обхват грудей 135 см при 85 кг) — ПЕРЕПИТАЙ: "Ви точно правильно заміряли? 135 см — це дуже великий обхват для ваги 85 кг"
- НЕ ВИГАДУЙ дані про розмірну сітку брендів!

ВАЛЮТИ ТА БЮДЖЕТ:
- 1 EUR ≈ 42-44 грн (2026 рік)
- Якщо клієнт вказує бюджет в € — перерахуй в грн і фільтруй: "200€ ≈ 8500 грн, шукаю в цьому бюджеті"
- Показуй ціни товарів в грн як є в базі

{$priceContext}

ПОРІВНЯННЯ ТА ЕКСПЕРТНІ ВІДПОВІДІ:
- При порівнянні товарів ("що краще", "чим відрізняється") — ЗАВЖДИ додавай конкретні приклади з каталогу через search_products!
- Після експертної відповіді (розміри, матеріали, як обрати) — ОБОВ'ЯЗКОВО запропонуй товари: "Ось варіанти з нашого каталогу:"
- НЕ давай "голих" порад — завжди додавай товари для покупки!

"ДАВАЙ", "ДОЗВОЛЯЮ", "ТАК" — ОЗНАЧАЄ БІЛЬШЕ ДЕТАЛЕЙ:
- Коли клієнт погоджується ("давай", "дозволяю", "покажи") — НЕ повторюй те саме!
- Виклич get_product_details(article) і дай НОВУ інформацію: повний опис, характеристики, наявність

АЛГОРИТМ:
1. Запит про товар → search_products() → JSON {"intro": "...", "products": [...]}
2. "Топ товари", "популярне" → get_popular_products()
3. Замовлення → get_order_status()
4. Загальне питання про магазин → короткий текст з FAQ
5. "дай посилання", "купити", "замовити цей товар" → get_product_details(article) з контексту розмови
6. "розкажи про нього", "деталі", "характеристики" → get_product_details(article) з [Показані товари:]

ВАЖЛИВО: ПОСИЛАННЯ = КАРТКА ТОВАРУ!
- Коли клієнт просить "посилання", "купити", "замовити" на товар з контексту — використай get_product_details(article) 
- НІКОЛИ не пиши URL текстом! Завжди показуй КАРТКУ ТОВАРУ через get_product_details!
- Артикул бери з попередньої відповіді (той що вказаний в products[].article або в [Показані товари: ... (арт. XXX)])

ФОРМАТ ВІДПОВІДІ ПІСЛЯ search_products:
{"intro": "Короткий опис (1 речення)", "products": [{"article": "...", "comment": "чому підходить"}], "outro": "Опційно"}

ПРАВИЛА:
- НЕ вигадуй товари — ТІЛЬКИ результати search_products
- Показуй 3-5 РІЗНИХ моделей
- Якщо товару немає в результатах — НЕ згадуй його взагалі!

{$toneSection}

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
        if (!empty($settings->faq_returns_text)) {
            $info[] = "ПОВЕРНЕННЯ ТА ОБМІН:\n{$settings->faq_returns_text}";
        }
        if (!empty($settings->store_hours)) {
            $info[] = "ГРАФІК РОБОТИ: {$settings->store_hours}";
        }

        return empty($info) ? "Актуальну інформацію дивіться на сайті contractor.kiev.ua" : implode("\n\n", $info);
    }

    /**
     * Load dynamic price context for prompt.
     */
    private function loadPriceContext(): string
    {
        try {
            $priceService = app(PriceStatsService::class);
            return $priceService->getPromptContext();
        } catch (\Throwable $e) {
            Log::warning('Failed to load price context', ['error' => $e->getMessage()]);
            return "ЦІНОВІ ПОРОГИ: бюджетний до 1500 грн, середній 1500-5000 грн, преміум від 5000 грн";
        }
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
                    'description' => 'Отримати найпопулярніші товари за кількістю продажів. Використовуй ТІЛЬКИ для загальних питань "що зараз популярне", "топ продажів". Для сезонних питань ("що беруть зимою") - використовуй search_products замість цього!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'Опційно: категорія товарів для фільтрації (наприклад "куртки", "рукавиці")'],
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
     * Uses real orders_count when available, falls back to curated queries.
     */
    private function toolGetPopularProducts(array $args): array
    {
        $category = $args['category'] ?? null;
        $limit = $args['limit'] ?? 5;
        $cacheKey = 'popular_products_v5:' . ($category ?? 'all') . ':' . $limit;
        
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
                
                // Try partial match in allProducts
                if (!$product) {
                    foreach ($productsByArticle as $a => $p) {
                        if (str_contains($a, $article) || str_contains($article, $a)) {
                            $product = $p;
                            break;
                        }
                    }
                }
                
                // Fallback: lookup directly in DB by article
                if (!$product && $article) {
                    $dbProduct = \App\Models\Product::where('article', $article)->first();
                    if ($dbProduct) {
                        $product = $this->detailsTool->getCards([$dbProduct->id])[0] ?? null;
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
        
        // Handle JSON with 'text' key (no products found response)
        if ($json && isset($json['text'])) {
            return [
                'intro' => $json['text'],
                'outro' => null,
                'products' => array_slice($allProducts, 0, 5),
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
    
    /**
     * Log user message to database.
     */
    private function logUserMessage(?string $sessionId, string $content): void
    {
        if (!$sessionId) return;
        
        try {
            $session = ChatSession::firstOrCreate(
                ['session_id' => $sessionId],
                [
                    'language' => 'uk',
                    'status' => 'open',
                    'meta' => [],
                ]
            );

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
                'meta' => [],
            ]);

            $session->increment('messages_count');
            $session->update([
                'last_user_query' => $content,
                'last_message_at' => now(),
            ]);
            
            Log::info('StreamingAgent: user message logged', ['session_id' => $sessionId]);
        } catch (\Exception $e) {
            Log::error('StreamingAgent: failed to log user message', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Log assistant message to database.
     */
    private function logAssistantMessage(?string $sessionId, string $content, array $products, string $intent): void
    {
        if (!$sessionId) return;
        
        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (!$session) return;

            $meta = [
                'intent' => $intent,
                'products_shown' => count($products),
                'products' => array_map(fn($p) => [
                    'id' => $p['id'] ?? null,
                    'article' => $p['article'] ?? null,
                    'title' => $p['title'] ?? null,
                    'price' => $p['price'] ?? null,
                    'image' => $p['image'] ?? $p['images'][0] ?? null,
                ], array_slice($products, 0, 10)),
            ];

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $content,
                'meta' => $meta,
            ]);

            $session->increment('messages_count');
            $session->update([
                'last_intent' => $intent,
                'last_message_at' => now(),
            ]);
            
            Log::info('StreamingAgent: assistant message logged', ['session_id' => $sessionId]);
        } catch (\Exception $e) {
            Log::error('StreamingAgent: failed to log assistant message', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Load conversation history from database.
     */
    private function loadConversationHistory(?string $sessionId, int $limit = 10): array
    {
        if (!$sessionId) return [];
        
        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (!$session) return [];

            $messages = ChatMessage::where('chat_session_id', $session->id)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();

            $history = [];
            
            foreach ($messages as $msg) {
                if (empty($msg->content)) continue;

                $role = $msg->role === 'user' ? 'user' : 'assistant';
                $content = $msg->content;
                
                // For assistant messages, add product context with articles
                if ($role === 'assistant') {
                    $meta = $msg->meta ?? [];
                    $products = $meta['products'] ?? [];
                    
                    if (!empty($products)) {
                        // Include article codes for better context recognition
                        $productDescriptions = array_map(function($p) {
                            $title = $p['title'] ?? '';
                            $article = $p['article'] ?? '';
                            return $article ? "{$title} (арт. {$article})" : $title;
                        }, $products);
                        $productList = implode(', ', array_filter($productDescriptions));
                        $textContent = is_string($content) ? $content : ($content['text'] ?? '');
                        $content = trim($textContent) . "\n[Показані товари: {$productList}]";
                    } elseif (is_array($content)) {
                        $content = $content['text'] ?? json_encode($content, JSON_UNESCAPED_UNICODE);
                    }
                }

                $history[] = [
                    'role' => $role,
                    'content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE),
                ];
            }

            return $history;
        } catch (\Exception $e) {
            Log::error('StreamingAgent: failed to load history', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
    
    /**
     * Extract conversation context from history.
     */
    private function extractConversationContext(array $history): ?string
    {
        if (empty($history)) return null;

        $productPatterns = [
            'футболк' => 'футболки',
            'штан' => 'штани',
            'плитоноск' => 'плитоноски',
            'берц' => 'взуття',
            'черевик' => 'взуття',
            'рюкзак' => 'рюкзаки',
            'шолом' => 'шоломи',
            'каск' => 'шоломи',
            'сорочк' => 'сорочки',
            'куртк' => 'куртки',
            'підсум' => 'підсумки',
            'рукавиц' => 'рукавиці',
            'рукавичк' => 'рукавиці',
            'кепк' => 'кепки',
            'кросівк' => 'кросівки',
        ];

        $context = [];
        $foundProduct = null;
        $shownProductCategory = null;
        $foundParams = [];

        foreach ($history as $msg) {
            $content = $msg['content'] ?? '';
            $contentLower = mb_strtolower($content);
            
            // Extract shown products from assistant messages
            if ($msg['role'] === 'assistant') {
                if (preg_match('/\[Показані товари:\s*(.+?)\]/ui', $content, $matches)) {
                    $shownProducts = $matches[1];
                    foreach ($productPatterns as $pattern => $name) {
                        if (mb_strpos(mb_strtolower($shownProducts), $pattern) !== false) {
                            $shownProductCategory = $name;
                            break;
                        }
                    }
                }
            }

            // Look for product mentions in user messages
            if ($msg['role'] === 'user') {
                foreach ($productPatterns as $pattern => $name) {
                    if (mb_strpos($contentLower, $pattern) !== false) {
                        $foundProduct = $name;
                        break;
                    }
                }
                
                // Look for size parameters
                if (preg_match('/(\d{2,3})\s*(см|кг|см)/ui', $content, $matches)) {
                    if (preg_match('/зріст\s*(\d+)/ui', $content, $m)) {
                        $foundParams['зріст'] = $m[1] . 'см';
                    }
                    if (preg_match('/ваг[аою]\s*(\d+)/ui', $content, $m)) {
                        $foundParams['вага'] = $m[1] . 'кг';
                    }
                }
                
                // XL, L, M sizes
                if (preg_match('/\b(XXL|XL|L|M|S)\b/ui', $content, $matches)) {
                    $foundParams['розмір'] = strtoupper($matches[1]);
                }
            }
        }

        // Build context
        if ($foundProduct) {
            $context[] = "шукає {$foundProduct}";
        } elseif ($shownProductCategory) {
            $context[] = "обговорюємо {$shownProductCategory}";
        }
        
        if ($shownProductCategory && $foundProduct) {
            $context[] = "показані {$shownProductCategory}";
        }
        
        if (!empty($foundParams)) {
            $paramsStr = implode(', ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($foundParams), array_values($foundParams)));
            $context[] = $paramsStr;
        }
        
        if (empty($context)) return null;
        
        return implode('; ', $context);
    }
}
