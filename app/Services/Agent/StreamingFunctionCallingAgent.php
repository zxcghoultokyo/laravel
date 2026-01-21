<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Generator;

/**
 * Streaming version of FunctionCallingAgent.
 * Uses OpenAI's streaming API to deliver responses chunk by chunk.
 * Extends BaseAgent for shared logic.
 *
 * Usage:
 * ```php
 * $agent = app(StreamingFunctionCallingAgent::class);
 * foreach ($agent->stream($message, $sessionId) as $event) {
 *     echo "data: " . json_encode($event) . "\n\n";
 *     flush();
 * }
 * ```
 */
class StreamingFunctionCallingAgent extends BaseAgent
{
    public function __construct(
        MeiliProductSearchTool $searchTool,
        ProductDetailsTool $detailsTool,
        OrderSearchService $orderSearchService
    ) {
        parent::__construct($searchTool, $detailsTool, $orderSearchService);
    }

    /**
     * Stream response as a generator.
     * Yields events that can be sent to the client via SSE.
     *
     * @return Generator<array{type: string, data: array}>
     */
    public function stream(string $message, ?string $sessionId = null): Generator
    {
        // Set tenant context for tone service
        $tenantId = $this->searchTool->getCurrentTenantId();
        $this->toneService->setTenantId($tenantId);

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

        // Load shown product IDs to exclude from subsequent searches
        $this->shownProductIds = $this->extractShownProductIds($sessionId);

        Log::info('StreamingAgent: loaded history', [
            'session_id' => $sessionId,
            'history_count' => count($history),
            'context' => $conversationContext,
            'shown_product_ids' => count($this->shownProductIds),
        ]);

        // Build conversation with history
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt()],
        ];

        // Detect trigger query
        $isTriggerQuery = $this->detectTriggerQuery($message);
        if ($isTriggerQuery) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->getTriggerSystemMessage(),
            ];
        }

        // Add context hint if we have one
        if ($conversationContext) {
            $messages[] = [
                'role' => 'system',
                'content' => <<<CONTEXT
=== КОНТЕКСТ ПОПЕРЕДНЬОЇ РОЗМОВИ ===
{$conversationContext}

ПРАВИЛА ВИКОРИСТАННЯ КОНТЕКСТУ:
1. НЕ питай "що ви шукаєте" якщо в контексті вже є категорія товару!
2. Якщо користувач уточнює (розмір, колір, бренд) — КОМБІНУЙ з попереднім контекстом!
3. "Ще" або "інші" = показати НОВІ товари тієї ж категорії (exclude shown products)
4. Якщо є показані товари — НЕ показуй їх повторно!
5. Короткі слова типу "так", "ні", "добре" — це підтвердження, не новий запит!
CONTEXT
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

                // For popular products, filter out already shown products
                if ($functionName === 'get_popular_products' && !empty($result['products']) && !empty($this->shownProductIds)) {
                    $result['products'] = array_filter(
                        $result['products'],
                        fn($p) => !in_array((int)($p['id'] ?? 0), $this->shownProductIds)
                    );
                    $result['products'] = array_values($result['products']);
                    $result['count'] = count($result['products']);
                }

                // Collect products
                if (in_array($functionName, ['search_products', 'get_popular_products']) && !empty($result['products'])) {
                    $allProducts = array_merge($allProducts, $result['products']);
                }
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

            yield ['type' => 'status', 'data' => ['text' => 'Готую відповідь...', 'phase' => 'generating']];

            // Collect full response (GPT returns JSON, we need to parse it)
            $collectedText = '';
            foreach ($this->streamGptResponse($messages) as $chunk) {
                if ($chunk['type'] === 'content') {
                    $collectedText .= $chunk['text'];
                }
            }

            // Parse the collected response
            $structured = $this->parseStructuredResponse($collectedText, $allProducts);

            $responseText = $structured['intro'] ?? $collectedText;
            $responseProducts = $structured['products'] ?? [];
            $responseIntent = 'product_search';

            // Send intro text with typing effect
            if (!empty($structured['intro'])) {
                $introChunks = mb_str_split($structured['intro'], 3);
                foreach ($introChunks as $chunk) {
                    yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                    usleep(10000);
                }
            }

            // Send products
            if (!empty($structured['products'])) {
                yield ['type' => 'products', 'data' => [
                    'products' => $structured['products'],
                    'count' => count($structured['products']),
                ]];
            }

            // Generate outro for trigger queries if needed
            $outro = $structured['outro'] ?? null;
            if ($isTriggerQuery && !empty($allProducts) && empty($outro)) {
                $outro = $this->generateTriggerOutro($allProducts);
            }

            if (!empty($outro)) {
                yield ['type' => 'chunk', 'data' => ['text' => "\n\n" . $outro]];
                $responseText .= "\n\n" . $outro;
            }

        } else {
            // No tool calls - GPT responded with text directly
            $content = $assistantMessage['content'] ?? '';
            $responseText = $content;
            $responseIntent = 'general';

            // Check if response contains JSON with products
            $structured = $this->parseStructuredResponse($responseText, []);

            if (!empty($structured['products'])) {
                Log::info('StreamingAgent: found products in direct response', [
                    'count' => count($structured['products']),
                ]);

                if (!empty($structured['intro'])) {
                    $introChunks = mb_str_split($structured['intro'], 3);
                    foreach ($introChunks as $chunk) {
                        yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                        usleep(10000);
                    }
                }

                yield ['type' => 'products', 'data' => [
                    'products' => $structured['products'],
                    'count' => count($structured['products']),
                ]];

                if (!empty($structured['outro'])) {
                    yield ['type' => 'chunk', 'data' => ['text' => "\n\n" . $structured['outro']]];
                }

                $responseText = $structured['intro'] . ($structured['outro'] ? "\n\n" . $structured['outro'] : '');
                $responseProducts = $structured['products'];
                $responseIntent = 'product_search';
            } else {
                // Stream as plain text
                $textChunks = mb_str_split($responseText, 3);
                foreach ($textChunks as $chunk) {
                    yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                    usleep(10000);
                }
            }
        }

        // Log assistant message to DB
        Log::info('StreamingAgent: about to log message', [
            'session_id' => $sessionId,
            'intent' => $responseIntent,
            'products_count' => count($responseProducts),
        ]);
        $this->logAssistantMessage($sessionId, $responseText, $responseProducts, $responseIntent);

        yield ['type' => 'done', 'data' => ['session_id' => $sessionId]];
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
            "2. Дай КОРОТКУ але ВПЕВНЕНУ відповідь (1-2 речення)\n" .
            "3. Закінчи КОНКРЕТНИМ CTA:\n" .
            "   - Одяг/взуття → 'Який розмір вам потрібен?'\n" .
            "   - Аксесуари → 'Оформлюємо? Або є питання?'\n" .
            "НЕ питай 'що саме потрібно?' — ДІЙ ВПЕВНЕНО!";
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
                    'temperature' => 0.1,
                ]);

            $body = $response->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $buffer .= $body->read(1024);

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, 'data: ')) continue;

                    $data = substr($line, 6);
                    if ($data === '[DONE]') return;

                    $json = json_decode($data, true);
                    if (!$json) continue;

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
                    'temperature' => 0.1,
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
     * Fallback stream when API is unavailable.
     */
    private function fallbackStream(string $message): Generator
    {
        $fallback = $this->fallbackResponse($message);

        yield ['type' => 'chunk', 'data' => ['text' => $fallback['message']]];

        if (!empty($fallback['products'])) {
            yield ['type' => 'products', 'data' => [
                'products' => $fallback['products'],
                'count' => count($fallback['products']),
            ]];
        }

        yield ['type' => 'done', 'data' => ['session_id' => null]];
    }
}
