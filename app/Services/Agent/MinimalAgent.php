<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Tenant\TenantContext;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal GPT Agent - експериментальна версія з простим промптом.
 * 
 * Філософія: Довіряємо GPT, не over-engineer'имо.
 * - Промпт ~50 рядків замість 2600
 * - Meilisearch робить ранжування
 * - GPT робить reasoning
 * - Мінімум PHP "помічників"
 */
class MinimalAgent
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private array $shownProductIds = [];
    private ?int $tenantId = null;

    public function __construct(
        private MeiliProductSearchTool $searchTool,
        private ProductDetailsTool $detailsTool,
        private ?TenantContext $tenantContext = null
    ) {
        $config = config('services.openai');
        $this->apiKey = $config['key'] ?? '';
        $this->baseUrl = $config['base_url'] ?? 'https://api.openai.com/v1';
        $this->model = $config['model'] ?? 'gpt-4o';
        $this->tenantContext = $tenantContext;
    }

    /**
     * Set tenant ID for this request.
     */
    public function setTenantId(?int $tenantId): self
    {
        $this->tenantId = $tenantId;
        // Set tenant context if available
        if ($tenantId && $this->tenantContext) {
            try {
                $this->tenantContext->setTenantId($tenantId);
            } catch (\Throwable $e) {
                Log::warning('MinimalAgent: failed to set tenant context', ['error' => $e->getMessage()]);
            }
        }
        return $this;
    }

    /**
     * Main entry point.
     */
    public function handle(string $message, array $context = []): array
    {
        $sessionId = $context['session_id'] ?? null;
        $tenantId = $context['tenant_id'] ?? null;
        $isTrigger = $context['is_trigger'] ?? false;

        // Set tenant context
        if ($tenantId) {
            $this->setTenantId($tenantId);
        }

        Log::info('MinimalAgent: processing', [
            'message' => $message,
            'session_id' => $sessionId,
            'tenant_id' => $tenantId,
            'is_trigger' => $isTrigger,
            'has_api_key' => !empty($this->apiKey),
        ]);

        try {
            // Load shown product IDs for "покажи ще" handling
            $this->shownProductIds = $this->loadShownProductIds($sessionId);

            // Load conversation history
            $history = $this->loadHistory($sessionId);

            // Build messages
            $messages = $this->buildMessages($message, $history, $isTrigger, $context);

            // Call GPT
            $response = $this->callGpt($messages);

            if (!$response) {
                Log::warning('MinimalAgent: GPT returned null, using fallback');
                return $this->fallbackSearch($message);
            }

            // Process response
            $result = $this->processResponse($response, $message, $sessionId);

            // Save to history (non-critical, don't fail on error)
            try {
                $this->saveToHistory($sessionId, $message, $result);
            } catch (\Throwable $e) {
                Log::warning('MinimalAgent: failed to save history', ['error' => $e->getMessage()]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('MinimalAgent: exception in handle()', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fallbackSearch($message);
        }
    }

    /**
     * Minimal system prompt - довіряємо GPT.
     */
    private function getSystemPrompt(bool $isTrigger, array $context): string
    {
        $shopName = $context['shop_name'] ?? 'магазин';
        $shopPhone = $context['shop_phone'] ?? '';
        
        $basePrompt = <<<PROMPT
Ти продавець інтернет-магазину "{$shopName}" (тактичне спорядження, військове обладнання).

ІНСТРУМЕНТИ:
- search_products(query, filters) — пошук товарів. Результати вже відсортовані за релевантністю.
- get_product_details(article) — детальна інформація про товар

ПРАВИЛА:
1. Шукай через search_products перед відповіддю на товарні запити
2. Показуй до 3 товарів за раз
3. Відповідай ТІЄЮ Ж МОВОЮ що й клієнт
4. Ціни і наявність — ТІЛЬКИ з результатів пошуку!
5. Не знаєш → "уточніть у менеджера" або зателефонуйте {$shopPhone}

ФОРМАТ ВІДПОВІДІ (JSON):
{"intro": "короткий текст", "products": [{"article": "xxx", "comment": "чому цей"}]}

Якщо клієнт просто спілкується (привіт, дякую) — відповідай текстом без пошуку:
{"text": "відповідь"}

ВИБІР ТОВАРІВ:
- Дивись на category_path — обирай товари з ПРАВИЛЬНОЇ категорії (Медицина ≠ Уніформа)
- "теплі/зимові рукавиці" = утеплені тактичні (Coldwork, Perun, утеплені), НЕ нітрилові медичні
- Результати вже відсортовані за релевантністю — перші товари зазвичай найкращі
- Якщо є товар з ключовим словом з запиту в назві (наприклад "утеплені") — він пріоритетний
PROMPT;

        // Trigger-specific additions
        if ($isTrigger) {
            $basePrompt .= <<<TRIGGER

🎯 ТРИГЕРНИЙ РЕЖИМ — клієнт прийшов з підказки на сайті, він вже зацікавлений!
- Дій впевнено, не питай "що саме потрібно"
- Покажи товар + коротко про переваги
- Закінчи конкретним CTA: "Який розмір?" або "Оформлюємо?"
TRIGGER;
        }

        return $basePrompt;
    }

    /**
     * Build messages array.
     */
    private function buildMessages(string $message, array $history, bool $isTrigger, array $context): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($isTrigger, $context)],
        ];

        // Add context about shown products for "покажи ще"
        if (!empty($this->shownProductIds)) {
            $messages[] = [
                'role' => 'system',
                'content' => "Вже показані товари (ID): " . implode(', ', array_slice($this->shownProductIds, -15)) . 
                    "\nЯкщо клієнт просить 'ще' — шукай з exclude_ids щоб не повторювати."
            ];
        }

        // Add history (last 10 messages)
        foreach (array_slice($history, -10) as $msg) {
            $messages[] = $msg;
        }

        // Add current message
        $messages[] = ['role' => 'user', 'content' => $message];

        return $messages;
    }

    /**
     * Get tools definition - simplified.
     */
    private function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products',
                    'description' => 'Пошук товарів. Результати відсортовані за популярністю (продажі, перегляди).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Пошуковий запит (назва, категорія, бренд)',
                            ],
                            'min_price' => [
                                'type' => 'number',
                                'description' => 'Мінімальна ціна',
                            ],
                            'max_price' => [
                                'type' => 'number',
                                'description' => 'Максимальна ціна',
                            ],
                            'in_stock' => [
                                'type' => 'boolean',
                                'description' => 'Тільки в наявності',
                            ],
                            'exclude_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer'],
                                'description' => 'ID товарів які не показувати (для "покажи ще")',
                            ],
                            'sort' => [
                                'type' => 'string',
                                'enum' => ['popularity', 'price_asc', 'price_desc', 'newest'],
                                'description' => 'Сортування (default: popularity)',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_product_details',
                    'description' => 'Отримати повну інформацію про товар (розміри, опис, характеристики)',
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
        ];
    }

    /**
     * Call GPT with tools.
     */
    private function callGpt(array $messages, int $retry = 0): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(45)
                ->post($this->baseUrl . '/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'tools' => $this->getTools(),
                    'tool_choice' => 'auto',
                    'temperature' => 0.3,
                ]);

            $data = $response->json();

            if (isset($data['error'])) {
                Log::error('MinimalAgent: GPT error', ['error' => $data['error']]);
                if ($retry < 2 && str_contains($data['error']['type'] ?? '', 'rate_limit')) {
                    usleep(pow(2, $retry) * 1000000);
                    return $this->callGpt($messages, $retry + 1);
                }
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('MinimalAgent: exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Process GPT response.
     */
    private function processResponse(array $response, string $originalMessage, ?string $sessionId): array
    {
        $assistant = $response['choices'][0]['message'] ?? null;
        if (!$assistant) {
            return $this->fallbackSearch($originalMessage);
        }

        // Handle tool calls
        if (!empty($assistant['tool_calls'])) {
            return $this->handleToolCalls($assistant['tool_calls'], $response, $originalMessage, $sessionId);
        }

        // Direct text response
        $content = $assistant['content'] ?? '';
        return $this->parseTextResponse($content);
    }

    /**
     * Handle tool calls.
     */
    private function handleToolCalls(array $toolCalls, array $initialResponse, string $originalMessage, ?string $sessionId): array
    {
        $products = [];
        $toolResults = [];
        $messages = [];

        // Re-build messages for continuation
        $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls];

        foreach ($toolCalls as $toolCall) {
            $name = $toolCall['function']['name'];
            $args = json_decode($toolCall['function']['arguments'], true) ?? [];

            Log::info('MinimalAgent: tool call', ['name' => $name, 'args' => $args]);

            $result = $this->executeTool($name, $args);

            // Handle "no more products" case
            if ($name === 'search_products' && empty($result['products']) && !empty($args['exclude_ids'])) {
                $result['message'] = 'Більше товарів цієї категорії немає. Спробуйте інший запит або інші фільтри.';
                $result['no_more'] = true;
            }

            if (!empty($result['products'])) {
                $products = array_merge($products, $result['products']);
            }

            $toolResults[] = [
                'tool_call_id' => $toolCall['id'],
                'role' => 'tool',
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }

        // Get final GPT response
        $finalMessages = array_merge(
            [['role' => 'system', 'content' => $this->getSystemPrompt(false, [])]],
            [['role' => 'user', 'content' => $originalMessage]],
            $messages,
            $toolResults
        );

        $finalResponse = $this->callGpt($finalMessages);
        
        if (!$finalResponse) {
            // Fallback: return products with simple intro
            return [
                'message' => 'Ось що знайшов:',
                'products' => array_slice($products, 0, 3),
                'meta' => ['agent' => 'minimal', 'fallback' => true],
            ];
        }

        $text = $finalResponse['choices'][0]['message']['content'] ?? '';
        $parsed = $this->parseStructuredResponse($text, $products);

        return [
            'message' => $parsed['intro'] ?? $parsed['text'] ?? '',
            'products' => $parsed['products'] ?? array_slice($products, 0, 3),
            'meta' => ['agent' => 'minimal', 'tools' => array_column($toolCalls, 'function')],
        ];
    }

    /**
     * Execute a tool.
     */
    private function executeTool(string $name, array $args): array
    {
        switch ($name) {
            case 'search_products':
                $query = $args['query'] ?? '';
                $filters = [];
                
                // Add tenant_id to filters
                if ($this->tenantId) {
                    $filters['tenant_id'] = $this->tenantId;
                }
                
                if (isset($args['min_price'])) $filters['price_min'] = $args['min_price'];
                if (isset($args['max_price'])) $filters['price_max'] = $args['max_price'];
                if (isset($args['in_stock'])) $filters['in_stock'] = $args['in_stock'];
                
                // sort_by goes into filters - MeiliProductSearchTool expects it there
                if (isset($args['sort']) && $args['sort'] !== 'popularity') {
                    $filters['sort_by'] = $args['sort'];
                }

                // searchTool->search returns array of products directly, NOT ['products' => [...]]
                $products = $this->searchTool->search($query, $filters, 15);
                
                // Filter out excluded IDs
                if (!empty($args['exclude_ids']) && !empty($products)) {
                    $excludeIds = array_map('intval', $args['exclude_ids']);
                    $products = array_values(array_filter(
                        $products,
                        fn($p) => !in_array((int)($p['id'] ?? 0), $excludeIds)
                    ));
                }

                // Return in expected format
                $result = [
                    'products' => $products,
                    'count' => count($products),
                ];

                // Add ranking info for GPT
                if (!empty($products)) {
                    $result['note'] = 'Товари відсортовані за популярністю (продажі + перегляди). Перші = найкращі.';
                }

                return $result;

            case 'get_product_details':
                return $this->detailsTool->getDetails($args['article'] ?? '');

            default:
                return ['error' => 'Unknown tool'];
        }
    }

    /**
     * Parse GPT text response.
     */
    private function parseTextResponse(string $content): array
    {
        // Try JSON first
        if (preg_match('/\{[\s\S]*\}/u', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) {
                return [
                    'message' => $json['intro'] ?? $json['text'] ?? $content,
                    'products' => $json['products'] ?? [],
                    'meta' => ['agent' => 'minimal', 'format' => 'json'],
                ];
            }
        }

        return [
            'message' => $content,
            'products' => [],
            'meta' => ['agent' => 'minimal', 'format' => 'text'],
        ];
    }

    /**
     * Parse structured response with products lookup.
     */
    private function parseStructuredResponse(string $text, array $foundProducts): array
    {
        $result = ['intro' => '', 'products' => [], 'text' => ''];

        if (preg_match('/\{[\s\S]*\}/u', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json) {
                $result['intro'] = $json['intro'] ?? '';
                $result['text'] = $json['text'] ?? '';

                // Map articles to real products
                if (!empty($json['products'])) {
                    $articleMap = [];
                    foreach ($foundProducts as $p) {
                        $art = mb_strtolower($p['article'] ?? '');
                        if ($art) $articleMap[$art] = $p;
                    }

                    foreach ($json['products'] as $jp) {
                        $art = mb_strtolower($jp['article'] ?? '');
                        if (isset($articleMap[$art])) {
                            $product = $articleMap[$art];
                            $product['comment'] = $jp['comment'] ?? '';
                            $result['products'][] = $product;
                        }
                    }
                }
            }
        }

        // Fallback to first 3 products
        if (empty($result['products']) && !empty($foundProducts)) {
            $result['products'] = array_slice($foundProducts, 0, 3);
        }

        return $result;
    }

    /**
     * Fallback search when GPT fails.
     */
    private function fallbackSearch(string $message): array
    {
        $filters = [];
        if ($this->tenantId) {
            $filters['tenant_id'] = $this->tenantId;
        }
        
        // searchTool->search returns array of products directly
        $products = $this->searchTool->search($message, $filters, 5);
        
        return [
            'message' => 'Ось що знайшов:',
            'products' => $products,
            'meta' => ['agent' => 'minimal', 'fallback' => true],
        ];
    }

    /**
     * Load shown product IDs from session.
     */
    private function loadShownProductIds(?string $sessionId): array
    {
        if (!$sessionId) return [];

        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (!$session) return [];

            $messages = ChatMessage::where('chat_session_id', $session->id)
                ->where('role', 'assistant')
                ->whereNotNull('meta')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $ids = [];
            foreach ($messages as $msg) {
                $meta = is_array($msg->meta) ? $msg->meta : json_decode($msg->meta, true);
                if (!empty($meta['shown_product_ids'])) {
                    $ids = array_merge($ids, $meta['shown_product_ids']);
                }
            }

            return array_unique(array_map('intval', $ids));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Load conversation history.
     */
    private function loadHistory(?string $sessionId): array
    {
        if (!$sessionId) return [];

        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (!$session) return [];

            $messages = ChatMessage::where('chat_session_id', $session->id)
                ->orderBy('created_at', 'asc')
                ->limit(20)
                ->get();

            $history = [];
            foreach ($messages as $msg) {
                $history[] = [
                    'role' => $msg->role,
                    'content' => $msg->content,
                ];
            }

            return $history;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Save message to history.
     */
    private function saveToHistory(?string $sessionId, string $userMessage, array $result): void
    {
        if (!$sessionId) return;

        try {
            $session = ChatSession::firstOrCreate(
                ['session_id' => $sessionId],
                ['tenant_id' => $this->tenantId ?? $this->tenantContext?->getTenantId() ?? 2]
            );

            // Save user message
            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'user',
                'content' => $userMessage,
            ]);

            // Save assistant message
            $shownIds = array_map(fn($p) => $p['id'] ?? 0, $result['products'] ?? []);
            
            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $result['message'] ?? '',
                'meta' => [
                    'shown_product_ids' => $shownIds,
                    'agent' => 'minimal',
                ],
            ]);

            $session->update(['last_message_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('MinimalAgent: save history error', ['error' => $e->getMessage()]);
        }
    }
}
