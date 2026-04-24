<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Chat\PipelineTracer;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Search\QueryPreprocessorService;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    protected QueryPreprocessorService $queryPreprocessor;

    public function __construct(
        MeiliProductSearchTool $searchTool,
        ProductDetailsTool $detailsTool,
        OrderSearchService $orderSearchService
    ) {
        parent::__construct($searchTool, $detailsTool, $orderSearchService);
        $this->queryPreprocessor = app(QueryPreprocessorService::class);
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
        $this->activeSessionId = $sessionId;

        // Log user message to DB
        $this->logUserMessage($sessionId, $message);

        // PRE-PROCESS: Normalize slang, brands, detect FAQ/greetings
        $preprocessed = $this->queryPreprocessor->preprocess($message);

        PipelineTracer::current()?->step('agent.preprocess', [
            'intercepted' => $preprocessed['intercepted'],
            'response_type' => $preprocessed['response_type'] ?? null,
            'detected_slang' => $preprocessed['detected_slang'] ?? null,
            'detected_brand' => $preprocessed['detected_brand'] ?? null,
            'normalized_query' => $preprocessed['query'] ?? $message,
        ]);

        if ($preprocessed['intercepted']) {
            Log::info('StreamingAgent: query intercepted by preprocessor', [
                'message' => $message,
                'type' => $preprocessed['response_type'],
            ]);

            yield ['type' => 'text', 'data' => ['text' => $preprocessed['response']]];

            // Log assistant response
            $this->logAssistantMessage($sessionId, $preprocessed['response'], [], $preprocessed['response_type']);

            yield ['type' => 'done', 'data' => ['meta' => [
                'intercepted' => true,
                'type' => $preprocessed['response_type'],
            ]]];

            return;
        }

        // Use normalized query for further processing
        $normalizedMessage = $preprocessed['query'];

        if ($normalizedMessage !== $message) {
            Log::info('StreamingAgent: query normalized', [
                'original' => $message,
                'normalized' => $normalizedMessage,
                'slang' => $preprocessed['detected_slang'],
                'brand' => $preprocessed['detected_brand'],
            ]);
        }

        // CRITICAL: Load shown product IDs FIRST - needed for all handlers
        // This must happen before handleImplicitQuery and handleFollowUpQuestion
        $this->shownProductIds = $this->extractShownProductIds($sessionId);

        Log::info('StreamingAgent: loaded shown product IDs early', [
            'session_id' => $sessionId,
            'shown_ids_count' => count($this->shownProductIds),
            'shown_ids' => array_slice($this->shownProductIds, 0, 10), // Log first 10
        ]);

        // PRE-PROCESS: Handle follow-up questions about previously shown products
        $followUpResult = $this->handleFollowUpQuestion($normalizedMessage, $sessionId);
        if ($followUpResult) {
            PipelineTracer::current()?->step('agent.follow_up_handled', [
                'handler' => 'handleFollowUpQuestion',
                'type' => $followUpResult['meta']['follow_up_type'] ?? null,
            ]);
            Log::info('StreamingAgent: follow-up question handled directly', [
                'message' => $message,
                'type' => $followUpResult['meta']['follow_up_type'] ?? null,
            ]);

            // Stream the text response
            if (! empty($followUpResult['message'])) {
                yield ['type' => 'text', 'data' => ['text' => $followUpResult['message']]];
            }

            // Log assistant response
            $this->logAssistantMessage($sessionId, $followUpResult['message'], [], $followUpResult['meta']['follow_up_type'] ?? 'follow_up');

            yield ['type' => 'done', 'data' => ['meta' => $followUpResult['meta'] ?? []]];

            return;
        }

        // PRE-PROCESS: Handle implicit queries directly
        $implicitResult = $this->handleImplicitQuery($normalizedMessage, $sessionId);
        if ($implicitResult) {
            PipelineTracer::current()?->step('agent.implicit_handled', [
                'handler' => 'handleImplicitQuery',
                'products_count' => count($implicitResult['products'] ?? []),
            ]);
            Log::info('StreamingAgent: implicit query handled directly', [
                'message' => $normalizedMessage,
                'products_found' => count($implicitResult['products'] ?? []),
            ]);

            // Save context for follow-ups ("дорожче", "дешевше") to preserve age/category
            if (! empty($implicitResult['products'])) {
                $this->saveLastProductContext($sessionId, $normalizedMessage, $implicitResult['meta']['source'] ?? 'implicit');
            }

            // Stream the intro text
            if (! empty($implicitResult['message'])) {
                yield ['type' => 'text', 'data' => ['text' => $implicitResult['message']]];
            }

            // Stream products
            if (! empty($implicitResult['products'])) {
                yield ['type' => 'products', 'data' => ['products' => $implicitResult['products']]];
            }

            // Log assistant response (always, even without products)
            $this->logAssistantMessage(
                $sessionId,
                $implicitResult['message'] ?? '',
                $implicitResult['products'] ?? [],
                ! empty($implicitResult['products']) ? 'product_search' : 'general',
            );

            // End stream
            yield ['type' => 'done', 'data' => []];

            return;
        }

        // Track response data for logging
        $responseText = '';
        $responseProducts = [];
        $responseIntent = 'streaming';

        if (empty($this->apiKey)) {
            PipelineTracer::current()?->step('agent.no_api_key', ['fallback' => true]);
            Log::warning('StreamingAgent: no API key, using fallback');
            yield from $this->fallbackStream($normalizedMessage, $sessionId);

            return;
        }

        // Load conversation history for context
        $history = $this->loadConversationHistory($sessionId);

        // Check if this is a fresh/new query (not a follow-up)
        $isFreshQuery = $this->isFreshQuery($normalizedMessage, $history);
        $conversationContext = $isFreshQuery ? '' : $this->extractConversationContext($history);

        // Load detailed product info for follow-up questions
        $productDetails = $isFreshQuery ? '' : $this->loadRecentProductDetails($sessionId);

        // NOTE: shownProductIds already loaded early (before handleImplicitQuery)

        PipelineTracer::current()?->step('agent.history_loaded', [
            'history_count' => count($history),
            'is_fresh_query' => $isFreshQuery,
            'has_context' => ! empty($conversationContext),
            'has_product_details' => ! empty($productDetails),
            'shown_ids_count' => count($this->shownProductIds),
        ]);

        // Set current message for modular prompt building
        $this->currentMessage = $normalizedMessage;
        $this->currentContext['has_history'] = ! empty($history);

        Log::info('StreamingAgent: loaded history', [
            'session_id' => $sessionId,
            'history_count' => count($history),
            'is_fresh_query' => $isFreshQuery,
            'context' => $conversationContext,
            'shown_product_ids' => count($this->shownProductIds),
            'has_product_details' => ! empty($productDetails),
        ]);

        // Build conversation with history
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt()],
        ];

        // Detect trigger query
        $isTriggerQuery = $this->detectTriggerQuery($normalizedMessage);
        if ($isTriggerQuery) {
            $this->currentContext['is_trigger'] = true;
            $messages[] = [
                'role' => 'system',
                'content' => $this->getTriggerSystemMessage(),
            ];
        }

        // Add context hint if we have one (including product details for follow-up questions)
        if ($conversationContext || $productDetails) {
            $contextContent = "=== КОНТЕКСТ ПОПЕРЕДНЬОЇ РОЗМОВИ ===\n";

            if ($conversationContext) {
                $contextContent .= "{$conversationContext}\n\n";
            }

            if ($productDetails) {
                $contextContent .= "=== ДЕТАЛЬНА ІНФОРМАЦІЯ ПРО ПОКАЗАНІ ТОВАРИ ===\n";
                $contextContent .= "Використовуй ці дані для відповідей на питання про характеристики, розміри, опис товарів:\n\n";
                $contextContent .= "{$productDetails}\n\n";
                $contextContent .= "ВАЖЛИВО: Якщо користувач питає про розміри, характеристики, опис — ВІДПОВІДАЙ на основі цих даних!\n";
                $contextContent .= "НЕ кажи \"не знаю\" або \"немає інформації\" якщо дані є вище!\n\n";
            }

            $contextContent .= <<<'CONTEXT'
ПРАВИЛА ВИКОРИСТАННЯ КОНТЕКСТУ:
1. НЕ питай "що ви шукаєте" якщо в контексті вже є категорія товару!
2. Якщо користувач уточнює (розмір, колір, бренд) — КОМБІНУЙ з попереднім контекстом!
3. "Ще" або "інші" = показати НОВІ товари тієї ж категорії (exclude_shown=true)
4. ПОВТОРНИЙ ЗАПИТ (та сама категорія, наприклад "футболка" знову) = ПОКАЗУЙ ВСІ товари (exclude_shown=false)!
5. Короткі слова типу "так", "ні", "добре" — це підтвердження, не новий запит!
6. Якщо питають про РОЗМІРИ/ХАРАКТЕРИСТИКИ показаних товарів — використовуй ДЕТАЛЬНУ ІНФОРМАЦІЮ вище!

🚨 КРИТИЧНО - СЛОВА ПІДТВЕРДЖЕННЯ:
"Дозволяю", "давай", "хочу", "можна", "покажи" без категорії = користувач погоджується на ПРОПОЗИЦІЮ БОТА!
Якщо бот запропонував "є варіанти термобілизни" і користувач каже "Дозволяю" — ШУКАЙ ТЕРМОБІЛИЗНУ!
Якщо бот запропонував "є куртки" і користувач каже "давай" — ШУКАЙ КУРТКИ!
Дивись що бот запропонував в ПОПЕРЕДНЬОМУ повідомленні!

🚨 КРИТИЧНО - КОРОТКІ ЗАПИТИ БЕЗ КАТЕГОРІЇ:
Якщо користувач пише короткий запит без явної категорії товару, наприклад:
- "наявності з таким же функціоналом?"
- "а є дешевше?"
- "щось подібне?"
- "аналоги?"
ТО ОБОВ'ЯЗКОВО використовуй категорію з контексту!
Якщо раніше обговорювали навушники — шукай НАВУШНИКИ!
Якщо раніше обговорювали куртки — шукай КУРТКИ!
НЕ шукай випадкові товари! Дивись на [Показані товари: ...] в історії!
CONTEXT;

            $messages[] = [
                'role' => 'system',
                'content' => $contextContent,
            ];
        }

        // Add history messages
        foreach ($history as $msg) {
            $messages[] = $msg;
        }

        // Add current message
        $messages[] = ['role' => 'user', 'content' => $normalizedMessage];

        yield ['type' => 'status', 'data' => ['text' => 'Аналізую запит...', 'phase' => 'thinking']];

        PipelineTracer::current()?->step('agent.gpt_call', [
            'model' => $this->model ?? 'unknown',
            'messages_count' => count($messages),
            'has_tools' => true,
        ]);

        // First call: may need tool calls
        $response = $this->callGptWithTools($messages);

        if (! $response) {
            PipelineTracer::current()?->step('agent.gpt_failed', ['fallback' => true]);
            yield from $this->fallbackStream($normalizedMessage, $sessionId);

            return;
        }

        $assistantMessage = $response['choices'][0]['message'] ?? null;

        // Check if GPT wants to call tools
        if (! empty($assistantMessage['tool_calls'])) {
            $toolNames = array_map(fn ($tc) => $tc['function']['name'], $assistantMessage['tool_calls']);
            PipelineTracer::current()?->step('agent.gpt_tool_calls', [
                'tools' => $toolNames,
                'count' => count($assistantMessage['tool_calls']),
            ]);

            yield ['type' => 'status', 'data' => ['text' => 'Шукаю товари...', 'phase' => 'searching']];

            // Execute tools
            $toolResults = [];
            $allProducts = [];

            // Extract last category from conversation context for follow-up queries
            $lastCategory = $this->extractLastCategoryFromMessages($messages);

            // Fallback: if no category from message history, check saved product context
            // This handles follow-ups after age queries (T20) or other non-tactical tenants
            $lastContextMessage = null;
            if (! $lastCategory && $sessionId) {
                $lastCtx = $this->loadLastProductContext($sessionId);
                if ($lastCtx) {
                    $lastContextMessage = $lastCtx['original_message'];
                    $detectedCat = $this->searchTool->detectAgeCategoryFromQuery($lastContextMessage);
                    if ($detectedCat) {
                        $lastCategory = $detectedCat;
                        Log::info('StreamingAgent: restored category from saved product context', [
                            'saved_message' => $lastContextMessage,
                            'detected_category' => $detectedCat,
                            'source' => $lastCtx['source'] ?? 'unknown',
                        ]);
                    }
                }
            }

            // Track if search_products found anything - to prevent irrelevant fallback
            $searchFoundProducts = false;
            $searchWasCalled = false;

            foreach ($assistantMessage['tool_calls'] as $toolCall) {
                $functionName = $toolCall['function']['name'];
                $args = json_decode($toolCall['function']['arguments'], true) ?? [];

                // CRITICAL: Inject category context for follow-up queries like "дешевше", "ще", "аналоги"
                if ($functionName === 'search_products' && $lastCategory) {
                    $args = $this->injectCategoryContext($args, $normalizedMessage, $lastCategory);
                }

                // Pass saved context message for age/boundary detection in MeiliProductSearchTool
                // Only for follow-up queries — prevents age filter leaking into unrelated queries
                if ($functionName === 'search_products' && $lastContextMessage && $this->isFollowUpMessage($normalizedMessage)) {
                    $args['_context_message'] = $lastContextMessage;
                }

                // Inject age-based category from original user message if GPT didn't pass one
                if ($functionName === 'search_products' && empty($args['category'])) {
                    $ageCategory = $this->searchTool->detectAgeCategoryFromQuery($normalizedMessage);
                    if ($ageCategory) {
                        $args['category'] = $ageCategory;
                        PipelineTracer::current()?->step('agent.age_category_injected', [
                            'source' => 'user_message',
                            'detected_category' => $ageCategory,
                            'gpt_query' => $args['query'] ?? '',
                        ]);
                        Log::info('StreamingAgent: injected age category from user message', [
                            'user_message' => $normalizedMessage,
                            'gpt_query' => $args['query'] ?? '',
                            'detected_category' => $ageCategory,
                        ]);
                    }
                }

                Log::info('StreamingAgent: executing tool', [
                    'function' => $functionName,
                    'args' => $args,
                    'last_category' => $lastCategory,
                ]);

                PipelineTracer::current()?->step('agent.tool_execute', [
                    'tool' => $functionName,
                    'query' => $args['query'] ?? null,
                    'category' => $args['category'] ?? null,
                    'brand' => $args['brand'] ?? null,
                    'price_min' => $args['price_min'] ?? null,
                    'price_max' => $args['price_max'] ?? null,
                ]);

                $result = $this->executeTool($functionName, $args);

                // RAG audit: record the retrieved context for this tool call
                $this->traceToolResult($functionName, $args, $result);

                // Track search results
                if ($functionName === 'search_products') {
                    $searchWasCalled = true;
                    $searchFoundProducts = ! empty($result['products']);
                }

                // Filter out already shown products ONLY when explicitly requested (for "покажи ще" type requests)
                // Regular searches should show all matching products, even if shown before
                $excludeShown = $args['exclude_shown'] ?? false;
                if (in_array($functionName, ['search_products', 'get_popular_products'])
                    && ! empty($result['products'])
                    && ! empty($this->shownProductIds)
                    && $excludeShown) {
                    $beforeCount = count($result['products']);
                    $result['products'] = array_filter(
                        $result['products'],
                        fn ($p) => ! in_array((int) ($p['id'] ?? 0), $this->shownProductIds)
                    );
                    $result['products'] = array_values($result['products']);
                    $result['count'] = count($result['products']);

                    Log::info('StreamingAgent: filtered shown products (exclude_shown=true)', [
                        'tool' => $functionName,
                        'before' => $beforeCount,
                        'after' => count($result['products']),
                        'excluded_ids' => $this->shownProductIds,
                    ]);
                }

                // Collect products from search tools
                // BUT: If search_products was called and found nothing, do NOT use get_popular_products as fallback
                // This prevents showing термобілизна when user asked for "набір для чищення зброї"
                if ($functionName === 'search_products' && ! empty($result['products'])) {
                    $allProducts = array_merge($allProducts, $result['products']);
                } elseif ($functionName === 'get_popular_products' && ! empty($result['products'])) {
                    // Only use popular products if:
                    // 1. search_products was NOT called (user asked for "популярне", "топ")
                    // 2. OR search_products DID find products (user asked "покажи ще популярних підсумків")
                    if (! $searchWasCalled || $searchFoundProducts) {
                        $allProducts = array_merge($allProducts, $result['products']);
                    } else {
                        Log::warning('StreamingAgent: BLOCKED get_popular_products fallback - search found nothing, not showing random products', [
                            'original_message' => $normalizedMessage,
                        ]);
                        // Clear products from tool result to not confuse GPT
                        $result['products'] = [];
                        $result['count'] = 0;
                        $result['blocked'] = true;
                        $result['reason'] = 'Search found no results, not showing random fallback products';
                    }
                }
                if ($functionName === 'get_product_details' && ! empty($result['product'])) {
                    $allProducts[] = $result['product'];
                }

                $toolResults[] = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'content' => json_encode($this->stripLinksForGpt($result), JSON_UNESCAPED_UNICODE),
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

            PipelineTracer::current()?->step('agent.tools_completed', [
                'products_count' => count($allProducts),
                'search_was_called' => $searchWasCalled,
                'search_found_products' => $searchFoundProducts,
                'product_categories' => array_unique(array_map(fn ($p) => $p['category_path'] ?? 'unknown', array_slice($allProducts, 0, 5))),
            ]);

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

            // Personalize intro text
            $introText = $structured['intro'] ?? '';
            $introText = $this->personalizeIntro($introText, $normalizedMessage, $allProducts);
            // Safety net: strip any URLs that GPT generated despite prompt prohibition
            $introText = $this->stripUrlsFromText($introText);

            $responseText = $introText ?: $collectedText;
            $responseProducts = $structured['products'] ?? [];
            $responseIntent = 'product_search';

            PipelineTracer::current()?->step('agent.response_parsed', [
                'has_intro' => ! empty($introText),
                'products_count' => count($responseProducts),
                'product_titles' => array_map(fn ($p) => mb_substr($p['title'] ?? '', 0, 40), array_slice($responseProducts, 0, 3)),
                'product_categories' => array_map(fn ($p) => $p['category_path'] ?? '', array_slice($responseProducts, 0, 3)),
            ]);

            // CRITICAL: If GPT says "not found"/"no products" in the text, don't show irrelevant products
            // This prevents showing термобілизна when GPT says "немає наборів для чищення"
            // NOTE: We trust GPT's judgment - if it says "no products", clear them even if search returned results
            // because search might return irrelevant products that GPT correctly identified as wrong
            $shouldShowProducts = true;
            if ($this->textIndicatesNoResults($introText)) {
                Log::warning('StreamingAgent: GPT text indicates no results, clearing products', [
                    'intro' => mb_substr($introText, 0, 100),
                    'original_message' => $normalizedMessage,
                    'was_products' => count($responseProducts),
                    'search_found_products' => $searchFoundProducts,
                ]);
                $shouldShowProducts = false;
                $responseProducts = [];
                $responseIntent = 'text';
            }

            // Send intro text with typing effect
            if (! empty($introText)) {
                $introChunks = mb_str_split($introText, 3);
                foreach ($introChunks as $chunk) {
                    yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                    usleep(10000);
                }
            }

            // Send products (only if not blocked by no-results detection)
            if ($shouldShowProducts && ! empty($structured['products'])) {
                yield ['type' => 'products', 'data' => [
                    'products' => $structured['products'],
                    'count' => count($structured['products']),
                ]];
            }

            // Generate outro for trigger queries if needed (pass responseText to avoid duplication)
            $outro = $structured['outro'] ?? null;
            if ($isTriggerQuery && ! empty($allProducts) && empty($outro)) {
                $outro = $this->generateTriggerOutro($allProducts, $responseText, $normalizedMessage);
            }

            if (! empty($outro)) {
                yield ['type' => 'chunk', 'data' => ['text' => "\n\n".$outro]];
                $responseText .= "\n\n".$outro;
            }

        } else {
            // No tool calls - GPT responded with text directly
            $content = $assistantMessage['content'] ?? '';
            $responseText = $this->stripUrlsFromText($content);
            $responseIntent = 'general';

            PipelineTracer::current()?->step('agent.gpt_no_tools', [
                'intent' => 'general',
                'response_length' => mb_strlen($content),
                'response_preview' => mb_substr($content, 0, 100),
            ]);

            // SAFETY NET: If GPT asks "Для якого віку?" instead of searching, force search
            $forceResult = $this->forceSearchOnAgeClarification($responseText, $normalizedMessage);
            if ($forceResult) {
                PipelineTracer::current()?->step('agent.force_search_on_age', [
                    'original_gpt_response' => mb_substr($responseText, 0, 100),
                    'products_count' => count($forceResult['products']),
                ]);

                $introChunks = mb_str_split($forceResult['intro'], 3);
                foreach ($introChunks as $chunk) {
                    yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                    usleep(10000);
                }

                yield ['type' => 'products', 'data' => [
                    'products' => $forceResult['products'],
                    'count' => count($forceResult['products']),
                ]];

                $responseText = $forceResult['intro'];
                $responseProducts = $forceResult['products'];
                $responseIntent = 'product_search';

                // Skip all other text processing — go straight to logging
                $this->logAssistantMessage($sessionId, $responseText, $responseProducts, $responseIntent);

                PipelineTracer::current()?->step('agent.response_parsed', [
                    'products_count' => count($responseProducts),
                    'source' => 'force_search_on_age_clarification',
                ]);

                return;
            }

            // Check if response contains JSON with products
            $structured = $this->parseStructuredResponse($responseText, []);

            if (! empty($structured['products'])) {
                Log::info('StreamingAgent: found products in direct response', [
                    'count' => count($structured['products']),
                ]);

                if (! empty($structured['intro'])) {
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

                if (! empty($structured['outro'])) {
                    yield ['type' => 'chunk', 'data' => ['text' => "\n\n".$structured['outro']]];
                }

                $responseText = $structured['intro'].($structured['outro'] ? "\n\n".$structured['outro'] : '');
                $responseProducts = $structured['products'];
                $responseIntent = 'product_search';
            } else {
                // Check if GPT text mentions products by article (follow-up from history)
                $extracted = $this->extractProductsFromTextResponse($responseText, $this->searchTool->getCurrentTenantId());
                if ($extracted && ! empty($extracted['products'])) {
                    // Stream text first, then product cards
                    $textChunks = mb_str_split($responseText, 3);
                    foreach ($textChunks as $chunk) {
                        yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                        usleep(10000);
                    }

                    yield ['type' => 'products', 'data' => [
                        'products' => $extracted['products'],
                        'count' => count($extracted['products']),
                    ]];

                    $responseProducts = $extracted['products'];
                    $responseIntent = 'product_search';
                } else {
                    // SAFETY NET: If GPT hallucinated products (listed items not in DB), force real search
                    $hallucinationResult = $this->forceSearchOnHallucinatedProducts($responseText, $normalizedMessage);
                    if ($hallucinationResult) {
                        PipelineTracer::current()?->step('agent.force_search_on_hallucination', [
                            'original_gpt_response' => mb_substr($responseText, 0, 200),
                            'products_count' => count($hallucinationResult['products']),
                        ]);

                        $introChunks = mb_str_split($hallucinationResult['intro'], 3);
                        foreach ($introChunks as $chunk) {
                            yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                            usleep(10000);
                        }

                        yield ['type' => 'products', 'data' => [
                            'products' => $hallucinationResult['products'],
                            'count' => count($hallucinationResult['products']),
                        ]];

                        $responseText = $hallucinationResult['intro'];
                        $responseProducts = $hallucinationResult['products'];
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

        return "🚨 УВАГА: Це ТРИГЕРНИЙ ЗАПИТ! Клієнт прийшов з підказки на сайті, він вже зацікавлений!\n".
            "ТВОЯ ЗАДАЧА — ДОПОМОГТИ КЛІЄНТУ:\n".
            "1. Знайди товар через search_products\n".
            "2. Дай КОРОТКУ але корисну відповідь (1-2 речення)\n".
            "3. Закінчи дружнім запитанням:\n".
            "   - Одяг/взуття → 'Який розмір вам потрібен? Підкажу!'\n".
            "   - Інше → 'Є питання? Із задоволенням допоможу!'\n".
            "НЕ питай 'що саме потрібно?' — ДІЙ ВПЕВНЕНО!\n".
            "ЗАБОРОНЕНО: 'Оформлюємо?', 'Резервуємо?', 'Закриваємо продаж'";
    }

    /**
     * Stream GPT response with OpenAI streaming API.
     *
     * @return Generator<array{type: string, text?: string}>
     */
    private function streamGptResponse(array $messages): Generator
    {
        try {
            $startTime = microtime(true);

            $response = Http::withToken($this->apiKey)
                ->withOptions(['stream' => true])
                ->timeout(60)
                ->post($this->baseUrl.'/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'stream' => true,
                    'stream_options' => ['include_usage' => true],
                    'temperature' => 0.1,
                ]);

            $body = $response->getBody();
            $buffer = '';
            $usageData = null;

            while (! $body->eof()) {
                $buffer .= $body->read(1024);

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $line = trim($line);
                    if (empty($line) || ! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = substr($line, 6);
                    if ($data === '[DONE]') {
                        // Track usage from the last chunk
                        if ($usageData) {
                            $elapsed = (int) ((microtime(true) - $startTime) * 1000);
                            $this->trackAiUsage('chat_stream', ['usage' => $usageData, 'model' => $this->model], null, $elapsed);
                        }

                        return;
                    }

                    $json = json_decode($data, true);
                    if (! $json) {
                        continue;
                    }

                    // Capture usage data from final chunk (sent when stream_options.include_usage is true)
                    if (isset($json['usage'])) {
                        $usageData = $json['usage'];
                    }

                    $delta = $json['choices'][0]['delta'] ?? [];
                    if (isset($delta['content'])) {
                        yield ['type' => 'content', 'text' => $delta['content']];
                    }
                }
            }

            // Track usage if stream ended without [DONE]
            if ($usageData) {
                $elapsed = (int) ((microtime(true) - $startTime) * 1000);
                $this->trackAiUsage('chat_stream', ['usage' => $usageData, 'model' => $this->model], null, $elapsed);
            }
        } catch (\Throwable $e) {
            Log::error('StreamingAgent: streaming error', ['error' => $e->getMessage()]);
            yield ['type' => 'error', 'text' => 'Помилка генерації'];
        }
    }

    /**
     * Call GPT with tools (non-streaming for tool detection).
     * Includes retry logic with exponential backoff for rate limits.
     */
    private function callGptWithTools(array $messages, int $retryCount = 0): ?array
    {
        $maxRetries = 3; // Increased from 2 for better rate limit handling

        try {
            $startTime = microtime(true);

            $response = Http::withToken($this->apiKey)
                ->timeout(45)
                ->connectTimeout(10)
                ->post($this->baseUrl.'/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'tools' => $this->getTools(),
                    'tool_choice' => 'auto',
                    'temperature' => 0.1,
                ]);

            $data = $response->json();
            $elapsed = (int) ((microtime(true) - $startTime) * 1000);

            // Track AI usage
            $this->trackAiUsage('chat_stream', $data, null, $elapsed, isset($data['error']));

            if (isset($data['error'])) {
                Log::error('StreamingAgent: OpenAI error', ['error' => $data['error']]);

                // Retry on rate limit or server errors with exponential backoff
                if ($retryCount < $maxRetries && $this->isRetryableError($data['error'])) {
                    // Exponential backoff: 1s, 2s, 4s
                    $delay = (int) pow(2, $retryCount) * 1000000; // microseconds
                    Log::info('StreamingAgent: retrying after error', [
                        'retry' => $retryCount + 1,
                        'delay_ms' => $delay / 1000,
                        'error_type' => $data['error']['type'] ?? 'unknown',
                    ]);
                    usleep($delay);

                    return $this->callGptWithTools($messages, $retryCount + 1);
                }

                return null;
            }

            return $data;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('StreamingAgent: connection timeout', ['error' => $e->getMessage(), 'retry' => $retryCount]);

            if ($retryCount < $maxRetries) {
                $delay = (int) pow(2, $retryCount) * 1000000;
                usleep($delay);

                return $this->callGptWithTools($messages, $retryCount + 1);
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('StreamingAgent: API error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Check if error is retryable (rate limit, server error).
     */
    private function isRetryableError($error): bool
    {
        if (is_array($error)) {
            $type = $error['type'] ?? '';
            $code = $error['code'] ?? '';

            return in_array($type, ['rate_limit_error', 'server_error', 'timeout'])
                || in_array($code, ['rate_limit_exceeded', '429', '500', '502', '503']);
        }

        return false;
    }

    /**
     * Fallback stream when API is unavailable.
     */
    private function fallbackStream(string $message, ?string $sessionId = null): Generator
    {
        $fallback = $this->fallbackResponse($message);

        yield ['type' => 'chunk', 'data' => ['text' => $fallback['message']]];

        if (! empty($fallback['products'])) {
            yield ['type' => 'products', 'data' => [
                'products' => $fallback['products'],
                'count' => count($fallback['products']),
            ]];
        }

        // Log assistant response to DB (was missing — caused bot responses not being saved)
        $this->logAssistantMessage($sessionId, $fallback['message'], $fallback['products'] ?? [], 'fallback');

        yield ['type' => 'done', 'data' => ['session_id' => $sessionId]];
    }

    /**
     * Check if GPT's text indicates that no relevant products were found.
     * Used to prevent showing irrelevant fallback products when GPT explicitly says "not found".
     */
    private function textIndicatesNoResults(string $text): bool
    {
        $noResultsPatterns = [
            '/на жаль.*(немає|нема|відсутн|не знайш)/ui',
            '/не (знайш|вдалося знайти)/ui',
            '/не маю (таких|подібних|відповідних)/ui',
            '/не мож[уе] знайти/ui',
            '/немає в наявності/ui',
            '/відсутні (в|у) наявності/ui',
            '/не (маємо|має)/ui',
            '/sorry.*(no|couldn\'t|can\'t find)/ui',
            '/не вдалося/ui',
            '/не маємо товарів/ui',
        ];

        foreach ($noResultsPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Personalize intro text based on user query context.
     * Replaces generic "Ось що я знайшов" with contextual intro.
     */
    private function personalizeIntro(string $intro, string $userMessage, array $products): string
    {
        // Check if intro is generic
        $genericPatterns = [
            '/^ось що я знайшов/ui',
            '/^ось що я знайшов за вашим запитом/ui',
            '/^here\'?s what i found/ui',
            '/^ось кілька варіантів/ui',
        ];

        $isGeneric = empty(trim($intro));
        if (! $isGeneric) {
            foreach ($genericPatterns as $pattern) {
                if (preg_match($pattern, trim($intro))) {
                    $isGeneric = true;
                    break;
                }
            }
        }

        // If not generic, keep original
        if (! $isGeneric) {
            return $intro;
        }

        // Try to extract category from user message
        $lowerMsg = mb_strtolower(trim($userMessage));

        // Check for follow-up patterns
        if (preg_match('/^(а є |є )?дешевш/ui', $lowerMsg)) {
            return 'Ось дешевші варіанти:';
        }
        if (preg_match('/^(а є |є )?дорожч/ui', $lowerMsg)) {
            return 'Ось преміум варіанти:';
        }
        if (preg_match('/покажи ще|ще варіант|інші/ui', $lowerMsg)) {
            return 'Ось ще варіанти:';
        }
        if (preg_match('/новинк|нов[іе] надходження|що нового/ui', $lowerMsg)) {
            return 'Ось новинки:';
        }

        // IMPORTANT: Check category FIRST before colors!
        // "термобілизна" contains "біл" which would match color "білий" incorrectly
        $categoryPatterns = [
            'куртк' => 'куртки',
            'берц' => 'берці',
            'штан' => 'штани',
            'футболк' => 'футболки',
            'навушник' => 'навушники',
            'шолом' => 'шоломи',
            'плитонос' => 'плитоноски',
            'рюкзак' => 'рюкзаки',
            'підсум' => 'підсумки',
            'термобіл' => 'термобілизна',
            'білизн' => 'термобілизна',
            'шевр' => 'шеврони',
            'бронежилет' => 'бронежилети',
            'тактич' => 'тактичне спорядження',
        ];

        foreach ($categoryPatterns as $pattern => $category) {
            if (mb_stripos($lowerMsg, $pattern) !== false) {
                return "Ось {$category}:";
            }
        }

        // Extract color (AFTER category check to avoid false positives like "термобілизна" -> "білий")
        $colors = ['олив', 'чорн', 'біл', 'мультикам', 'піксель', 'коричнев', 'coyote', 'койот', 'ranger green'];
        foreach ($colors as $color) {
            if (mb_stripos($lowerMsg, $color) !== false) {
                $colorName = mb_ucfirst($color);

                return "Ось варіанти в кольорі {$colorName}:";
            }
        }

        // Try to get category from first product
        if (! empty($products[0]['category_path'])) {
            $categoryPath = $products[0]['category_path'];
            $parts = explode(' > ', $categoryPath);
            $lastCategory = end($parts);
            if ($lastCategory) {
                return "Ось {$lastCategory}:";
            }
        }

        // Default fallback - still better than generic
        return 'Ось товари:';
    }

    /**
     * Extract last product category from conversation messages.
     * Used to maintain context for follow-up queries like "дешевше", "ще".
     */
    private function extractLastCategoryFromMessages(array $messages): ?string
    {
        $foundCategory = null;

        // Scan messages in reverse to find last mentioned category
        foreach (array_reverse($messages) as $msg) {
            $content = $msg['content'] ?? '';
            $role = $msg['role'] ?? '';

            // Skip system messages and current user message (last in array)
            if ($role === 'system') {
                continue;
            }

            // Look for [Показані товари: ...] marker in assistant messages
            if ($role === 'assistant' && preg_match('/\[Показані товари: (.+?)\]/u', $content, $matches)) {
                $productText = $matches[1];

                // Extract category keywords from product text
                $categoryKeywords = [
                    'peltor|пелтор' => 'навушники Peltor',
                    'earmor' => 'навушники Earmor',
                    'навушник|headset|comtac' => 'навушники',
                    'куртк|jacket' => 'куртки',
                    'берц|boots' => 'берці',
                    'штан|pants' => 'штани',
                    'футболк|shirt' => 'футболки',
                    'шолом|helmet' => 'шоломи',
                    'плитонос|plate.?carrier' => 'плитоноски',
                    'рюкзак|backpack' => 'рюкзаки',
                    'підсум|pouch' => 'підсумки',
                    'термо' => 'термобілизна',
                    'бронежилет|armor' => 'бронежилети',
                    // Children / toy-store age categories
                    'малюкам|для\s+малюк' => 'малюкам',
                    'тодлерам|для\s+тодлер' => 'тодлерам',
                    'дошкільнятам|дошкільн|дошколят' => 'дошкільнятам',
                    'школярам|для\s+школяр' => 'школярам',
                    'іграшк|toy' => 'іграшки',
                    'пазл|puzzle' => 'пазли',
                    'конструктор|lego|лего' => 'конструктори',
                ];

                foreach ($categoryKeywords as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $productText)) {
                        Log::info('StreamingAgent: extracted category from [Показані товари]', [
                            'category' => $category,
                            'from' => mb_substr($productText, 0, 100),
                        ]);

                        return $category;
                    }
                }
            }

            // Check user messages for explicit product mentions (but not current message)
            // We check ALL user messages and take the most recent category found
            if ($role === 'user' && $foundCategory === null) {
                $lowerContent = mb_strtolower($content);
                $userCategoryPatterns = [
                    'peltor|пелтор' => 'навушники Peltor',
                    'earmor' => 'навушники Earmor',
                    'comtac' => 'навушники',
                    'навушник' => 'навушники',
                    'куртк' => 'куртки',
                    'берц' => 'берці',
                    'штан' => 'штани',
                    'шолом' => 'шоломи',
                    'плитонос' => 'плитоноски',
                    'рюкзак' => 'рюкзаки',
                    'підсум' => 'підсумки',
                    // Children / toy-store age categories
                    'малюкам|для\s+малюк' => 'малюкам',
                    'тодлерам|для\s+тодлер' => 'тодлерам',
                    'дошкільнятам|дошкільн|дошколят' => 'дошкільнятам',
                    'школярам|для\s+школяр' => 'школярам',
                    'іграшк' => 'іграшки',
                    'пазл' => 'пазли',
                    'конструктор|lego|лего' => 'конструктори',
                ];

                foreach ($userCategoryPatterns as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $lowerContent)) {
                        $foundCategory = $category;
                        Log::info('StreamingAgent: extracted category from user message', [
                            'category' => $category,
                            'user_message' => mb_substr($content, 0, 100),
                        ]);
                        // Don't return yet - keep looking for [Показані товари] which has priority
                        break;
                    }
                }
            }
        }

        return $foundCategory;
    }

    /**
     * Inject category context into search args for follow-up queries.
     * Prevents "дешевше" from returning random products instead of same category.
     */
    private function injectCategoryContext(array $args, string $userMessage, string $lastCategory): array
    {
        $lowerMsg = mb_strtolower($userMessage);

        // Patterns that indicate follow-up without explicit category
        $followUpPatterns = [
            'дешевше', 'дешевший', 'дорожче', 'дорожчий',
            'ще ', 'інші', 'інш', 'аналог', 'подібн', 'такий же', 'такі ж',
            'більше варіант', 'ще варіант',
        ];

        $isFollowUp = false;
        $isCheaper = false;
        $isMoreExpensive = false;

        foreach ($followUpPatterns as $pattern) {
            if (mb_stripos($lowerMsg, $pattern) !== false) {
                $isFollowUp = true;
                if (in_array($pattern, ['дешевше', 'дешевший'])) {
                    $isCheaper = true;
                }
                if (in_array($pattern, ['дорожче', 'дорожчий'])) {
                    $isMoreExpensive = true;
                }
                break;
            }
        }

        // If not follow-up or query already has specific category, don't modify
        if (! $isFollowUp) {
            return $args;
        }

        $currentQuery = $args['query'] ?? '';

        // Check if query already contains category keywords
        $hasCategory = preg_match('/(навушник|куртк|берц|штан|шолом|рюкзак|плитонос|іграшк|пазл|конструктор|малюкам|тодлерам|дошкільн|школяр)/ui', $currentQuery);

        if (! $hasCategory) {
            // Inject category into query
            $newQuery = trim($lastCategory.' '.$currentQuery);
            $args['query'] = $newQuery;

            Log::info('StreamingAgent: injected category context', [
                'original_query' => $currentQuery,
                'new_query' => $newQuery,
                'last_category' => $lastCategory,
                'user_message' => $userMessage,
            ]);
        }

        // Add price sorting for "дешевше" / "дорожче" queries
        if ($isCheaper) {
            $args['sort_by'] = 'price_asc';
            Log::info('StreamingAgent: added price_asc sorting for cheaper request');
        } elseif ($isMoreExpensive) {
            $args['sort_by'] = 'price_desc';
            Log::info('StreamingAgent: added price_desc sorting for expensive request');
        }

        return $args;
    }
}
