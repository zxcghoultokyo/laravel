<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Non-streaming GPT Agent with Function Calling.
 * Uses BaseAgent for shared logic.
 * 
 * Instead of 2000 lines of hardcoded rules, we let GPT decide what to do.
 * GPT has access to tools and calls them as needed.
 */
class FunctionCallingAgent extends BaseAgent
{
    public function __construct(
        MeiliProductSearchTool $searchTool,
        ProductDetailsTool $detailsTool,
        OrderSearchService $orderSearchService
    ) {
        parent::__construct($searchTool, $detailsTool, $orderSearchService);
    }

    /**
     * Main entry point - let GPT handle the conversation.
     */
    public function handle(string $message, array $context = []): array
    {
        $sessionId = $context['session_id'] ?? null;

        // Set tenant context for tone service
        $tenantId = $this->searchTool->getCurrentTenantId();
        $this->toneService->setTenantId($tenantId);

        Log::info('FunctionCallingAgent: processing', ['message' => $message, 'session_id' => $sessionId, 'tenant_id' => $tenantId]);

        // PRE-PROCESS: Detect implicit queries and search directly
        $implicitSearchResult = $this->handleImplicitQuery($message, $sessionId);
        if ($implicitSearchResult) {
            return $implicitSearchResult;
        }

        // Load shown product IDs to exclude from subsequent searches
        $this->shownProductIds = $this->extractShownProductIds($sessionId);

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

        // Check if GPT returned JSON (sometimes it does for follow-ups with products from history)
        if (preg_match('/^\s*\{/u', $text)) {
            $json = json_decode($text, true);
            if ($json) {
                // Use parseStructuredResponse to find real products in DB by article
                $structured = $this->parseStructuredResponse($text, []);

                if (!empty($structured['products'])) {
                    Log::info('FunctionCallingAgent: found products in JSON response without tool_calls', [
                        'product_count' => count($structured['products']),
                        'articles' => array_column($structured['products'], 'article'),
                    ]);

                    $introText = $structured['intro'] ?? '';
                    $outroText = $structured['outro'] ?? '';
                    $fullText = trim($introText . "\n\n" . $outroText);

                    return [
                        'message' => $fullText,
                        'products' => $structured['products'],
                        'messages' => array_filter([
                            $introText ? ['type' => 'text', 'content' => $introText] : null,
                            ['type' => 'products', 'products' => $structured['products']],
                            $outroText ? ['type' => 'text', 'content' => $outroText] : null,
                        ]),
                        'meta' => ['intent' => 'product_search', 'agent' => 'function_calling', 'source' => 'json_from_history'],
                    ];
                }

                // No products found - extract text only
                if (isset($json['intro'])) {
                    $text = $json['intro'];
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
     * Build messages array with system prompt.
     */
    private function buildMessages(string $message, array $context): array
    {
        $systemPrompt = $this->getSystemPrompt();

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Detect trigger query (from proactive triggers)
        $isTriggerQuery = $this->detectTriggerQuery($message);
        if ($isTriggerQuery) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->getTriggerSystemMessage(),
            ];
        }

        // Add conversation history if available
        $history = $context['history'] ?? [];

        // Check if this is a fresh/new query (not a follow-up)
        $isFreshQuery = $this->isFreshQuery($message, $history);
        $conversationContext = $isFreshQuery ? '' : $this->extractConversationContext($history);

        Log::info('FunctionCallingAgent: extracted context', [
            'context' => $conversationContext,
            'history_count' => count($history),
            'is_trigger' => $isTriggerQuery,
            'is_fresh_query' => $isFreshQuery,
        ]);

        if ($conversationContext) {
            $messages[] = [
                'role' => 'system',
                'content' => <<<CONTEXT
=== КОНТЕКСТ ПОПЕРЕДНЬОЇ РОЗМОВИ ===
{$conversationContext}

ПРАВИЛА ВИКОРИСТАННЯ КОНТЕКСТУ:
1. НЕ питай "що ви шукаєте" якщо в контексті вже є категорія товару!
2. Якщо користувач уточнює (розмір, колір, бренд) — КОМБІНУЙ з попереднім контекстом!
3. "Ще" або "інші" = показати НОВІ товари тієї ж категорії (exclude_shown=true)
4. ПОВТОРНИЙ ЗАПИТ (та сама категорія, наприклад "футболка" знову) = ПОКАЗУЙ ВСІ товари (exclude_shown=false)!
5. Короткі слова типу "так", "ні", "добре" — це підтвердження, не новий запит!
CONTEXT
            ];
        }

        foreach ($history as $msg) {
            $messages[] = $msg;
        }

        // Detect if this is a follow-up query
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

            if ($lastAssistant && preg_match('/\[Показані товари: (.+?)\]/', $lastAssistant, $matches)) {
                $productContext = $matches[1];
                $message = "{$message}\n[Контекст: користувач запитує про {$productContext}]";
            }
        }

        // Add current message
        $messages[] = ['role' => 'user', 'content' => $message];

        return $messages;
    }

    /**
     * Get trigger query system message.
     */
    private function getTriggerSystemMessage(): string
    {
        $shopPhone = $this->getShopPhone();

        return "🚨 УВАГА: Це ТРИГЕРНИЙ ЗАПИТ! Клієнт прийшов з підказки на сайті, він вже зацікавлений!\n" .
            "ТВОЯ ЗАДАЧА — ЗАКРИТИ ПРОДАЖ:\n" .
            "1. Знайди товар через search_products\n" .
            "2. Дай КОРОТКУ але ВПЕВНЕНУ відповідь (1-2 речення про особливості товару)\n" .
            "3. Закінчи КОНКРЕТНИМ CTA залежно від товару:\n" .
            "   - Одяг/взуття → 'Який розмір вам потрібен? Підкажіть зріст/вагу'\n" .
            "   - Аксесуари/рюкзаки/шоломи → 'Оформлюємо? Або є питання по характеристиках?'\n" .
            "   - Якщо мало в наявності → 'Залишилось X шт. Резервуємо?'\n" .
            "НЕ питай 'що саме потрібно?' — ДІЙ ВПЕВНЕНО!";
    }

    /**
     * Detect if message is a follow-up query.
     */
    private function detectFollowUpQuery(string $message, array $history): bool
    {
        $followUpPatterns = [
            '/^(в |у )?(розмір|размер)/ui',
            '/^(в |у )?(кольор|цвет|color)/ui',
            '/^(які|які є|що є|а є|є ).{0,20}(L|M|S|XL|XXL|\d{2})/ui',
            '/^(дешевш|дорожч|до \d|від \d|бюджет)/ui',
            '/^(ще|більше|інші|інш|варіант)/ui',
            '/^(чорн|біл|олив|мультикам|піксель|коричнев)/ui',
            '/^(L|M|S|XL|XXL|\d{2})$/ui',
        ];

        foreach ($followUpPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        // Short message with history = likely follow-up
        if (mb_strlen($message) < 30 && count($history) >= 2) {
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
     * Call OpenAI with function calling.
     */
    private function callGptWithTools(array $messages): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('FunctionCallingAgent: no API key');
            return null;
        }

        try {
            Log::info('FunctionCallingAgent: calling OpenAI', [
                'model' => $this->model,
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
            ]);

            if (isset($data['error'])) {
                Log::error('FunctionCallingAgent: OpenAI error', ['error' => $data['error']]);
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('FunctionCallingAgent: API error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Handle tool calls from GPT.
     */
    private function handleToolCalls(array $toolCalls, array $messages, string $originalMessage, ?string $sessionId): array
    {
        $products = [];
        $toolResults = [];

        $isTriggerQuery = $this->detectTriggerQuery($originalMessage);

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'];
            $args = json_decode($toolCall['function']['arguments'], true) ?? [];

            Log::info('FunctionCallingAgent: executing tool', [
                'function' => $functionName,
                'args' => $args,
            ]);

            $result = $this->executeTool($functionName, $args);

            // Filter out already shown products ONLY when explicitly requested (for "покажи ще" type requests)
            // Regular searches should show all matching products, even if shown before
            $excludeShown = $args['exclude_shown'] ?? false;
            if (in_array($functionName, ['search_products', 'get_popular_products']) 
                && !empty($result['products']) 
                && !empty($this->shownProductIds)
                && $excludeShown) {
                $beforeCount = count($result['products']);
                $result['products'] = array_filter(
                    $result['products'],
                    fn($p) => !in_array((int)($p['id'] ?? 0), $this->shownProductIds)
                );
                $result['products'] = array_values($result['products']);
                $result['count'] = count($result['products']);
                
                Log::info('FunctionCallingAgent: filtered shown products (exclude_shown=true)', [
                    'tool' => $functionName,
                    'before' => $beforeCount,
                    'after' => count($result['products']),
                ]);
            }

            // Collect products from search tools
            if (in_array($functionName, ['search_products', 'get_popular_products']) && !empty($result['products'])) {
                $products = array_merge($products, $result['products']);
            }
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

        // Parse GPT response as JSON
        $structuredResponse = $this->parseStructuredResponse($responseText, $products);

        // Generate outro for trigger queries if needed (pass GPT response to avoid duplication)
        $outro = $structuredResponse['outro'] ?? null;
        if ($isTriggerQuery && !empty($products) && empty($outro)) {
            $outro = $this->generateTriggerOutro($products, $responseText);
            if (!empty($structuredResponse['messages'])) {
                $structuredResponse['messages'][] = ['type' => 'text', 'content' => $outro];
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
                'outro' => $outro,
                'is_trigger' => $isTriggerQuery,
            ],
        ];
    }
}
