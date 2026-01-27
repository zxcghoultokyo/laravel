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

        // PRE-PROCESS: Handle follow-up questions about previously shown products
        // These should NOT trigger search, but answer from context
        $followUpResult = $this->handleFollowUpQuestion($message, $sessionId);
        if ($followUpResult) {
            // Log and save to DB
            $this->logAssistantMessage(
                $sessionId, 
                $followUpResult['message'], 
                $followUpResult['products'], 
                $followUpResult['meta']['follow_up_type'] ?? 'follow_up'
            );
            return $followUpResult;
        }

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
        $sessionId = $context['session_id'] ?? null;

        // Check if this is a fresh/new query (not a follow-up)
        $isFreshQuery = $this->isFreshQuery($message, $history);
        $conversationContext = $isFreshQuery ? '' : $this->extractConversationContext($history);
        
        // Load detailed product info for follow-up questions
        $productDetails = $isFreshQuery ? '' : $this->loadRecentProductDetails($sessionId);

        Log::info('FunctionCallingAgent: extracted context', [
            'context' => $conversationContext,
            'history_count' => count($history),
            'is_trigger' => $isTriggerQuery,
            'is_fresh_query' => $isFreshQuery,
            'has_product_details' => !empty($productDetails),
        ]);

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
            
            $contextContent .= <<<CONTEXT
ПРАВИЛА ВИКОРИСТАННЯ КОНТЕКСТУ:
1. НЕ питай "що ви шукаєте" якщо в контексті вже є категорія товару!
2. Якщо користувач уточнює (розмір, колір, бренд) — КОМБІНУЙ з попереднім контекстом!
3. "Ще" або "інші" = показати НОВІ товари тієї ж категорії (exclude_shown=true)
4. ПОВТОРНИЙ ЗАПИТ (та сама категорія, наприклад "футболка" знову) = ПОКАЗУЙ ВСІ товари (exclude_shown=false)!
5. Короткі слова типу "так", "ні", "добре" — це підтвердження, не новий запит!
6. Якщо питають про РОЗМІРИ/ХАРАКТЕРИСТИКИ показаних товарів — використовуй ДЕТАЛЬНУ ІНФОРМАЦІЮ вище!

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
    private function callGptWithTools(array $messages, int $retryCount = 0): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('FunctionCallingAgent: no API key');
            return null;
        }

        $maxRetries = 2;
        
        try {
            Log::info('FunctionCallingAgent: calling OpenAI', [
                'model' => $this->model,
                'messages_count' => count($messages),
                'retry' => $retryCount,
            ]);

            $response = Http::withToken($this->apiKey)
                ->timeout(45) // Increased from 30 to 45 seconds
                ->connectTimeout(10) // Add connect timeout
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
                
                // Retry on rate limit or server errors
                if ($retryCount < $maxRetries && $this->isRetryableError($data['error'])) {
                    Log::info('FunctionCallingAgent: retrying after error', ['retry' => $retryCount + 1]);
                    usleep(500000 * ($retryCount + 1)); // 0.5s, 1s delay
                    return $this->callGptWithTools($messages, $retryCount + 1);
                }
                
                return null;
            }

            return $data;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('FunctionCallingAgent: Connection timeout', ['error' => $e->getMessage()]);
            
            // Retry on connection timeouts
            if ($retryCount < $maxRetries) {
                Log::info('FunctionCallingAgent: retrying after timeout', ['retry' => $retryCount + 1]);
                usleep(500000 * ($retryCount + 1)); // 0.5s, 1s delay
                return $this->callGptWithTools($messages, $retryCount + 1);
            }
            
            return null;
        } catch (\Throwable $e) {
            Log::error('FunctionCallingAgent: API error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Check if error is retryable.
     */
    private function isRetryableError(array $error): bool
    {
        $retryableCodes = ['rate_limit_exceeded', 'server_error', 'timeout', 'overloaded'];
        $errorType = $error['type'] ?? '';
        $errorCode = $error['code'] ?? '';
        
        return in_array($errorType, $retryableCodes) || in_array($errorCode, $retryableCodes);
    }

    /**
     * Handle tool calls from GPT.
     */
    private function handleToolCalls(array $toolCalls, array $messages, string $originalMessage, ?string $sessionId): array
    {
        $products = [];
        $toolResults = [];

        $isTriggerQuery = $this->detectTriggerQuery($originalMessage);
        
        // Extract last category from conversation context for follow-up queries
        $lastCategory = $this->extractLastCategoryFromMessages($messages);

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'];
            $args = json_decode($toolCall['function']['arguments'], true) ?? [];
            
            // CRITICAL: Inject category context for follow-up queries like "дешевше", "ще", "аналоги"
            if ($functionName === 'search_products' && $lastCategory) {
                $args = $this->injectCategoryContext($args, $originalMessage, $lastCategory);
            }

            Log::info('FunctionCallingAgent: executing tool', [
                'function' => $functionName,
                'args' => $args,
                'last_category' => $lastCategory,
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
        
        // Handle GPT failure - return products with basic intro
        if ($finalResponse === null) {
            Log::warning('GPT final response failed, returning products with basic intro', [
                'products_count' => count($products),
            ]);
            $products = $this->dedupeProducts($products);
            $intro = $this->personalizeIntro('', $originalMessage, $products);
            return [
                'message' => $intro ?: 'Ось результати пошуку:',
                'products' => array_slice($products, 0, 5),
                'messages' => [],
                'meta' => [
                    'intent' => 'product_search',
                    'agent' => 'function_calling',
                    'tools_called' => array_map(fn($tc) => $tc['function']['name'], $toolCalls),
                    'products_found' => count($products),
                    'gpt_final_failed' => true,
                ],
            ];
        }
        
        $responseText = $finalResponse['choices'][0]['message']['content'] ?? '';

        // Dedupe products
        $products = $this->dedupeProducts($products);

        // Parse GPT response as JSON
        $structuredResponse = $this->parseStructuredResponse($responseText, $products);

        // Generate outro for trigger queries if needed (pass GPT response to avoid duplication)
        $outro = $structuredResponse['outro'] ?? null;
        if ($isTriggerQuery && !empty($products) && empty($outro)) {
            $outro = $this->generateTriggerOutro($products, $responseText, $originalMessage);
            if (!empty($structuredResponse['messages'])) {
                $structuredResponse['messages'][] = ['type' => 'text', 'content' => $outro];
            }
        }

        // Personalize intro based on context
        $intro = $structuredResponse['intro'] ?? '';
        $intro = $this->personalizeIntro($intro, $originalMessage, $products);

        return [
            'message' => $intro,
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
        if (!$isGeneric) {
            foreach ($genericPatterns as $pattern) {
                if (preg_match($pattern, trim($intro))) {
                    $isGeneric = true;
                    break;
                }
            }
        }
        
        // If not generic, keep original
        if (!$isGeneric) {
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
        
        // Extract color
        $colors = ['олив', 'чорн', 'біл', 'мультикам', 'піксель', 'коричнев', 'coyote', 'койот', 'ranger green'];
        foreach ($colors as $color) {
            if (mb_stripos($lowerMsg, $color) !== false) {
                $colorName = mb_ucfirst($color);
                return "Ось варіанти в кольорі {$colorName}:";
            }
        }
        
        // Extract category from products or message
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
            'шевр' => 'шеврони',
            'бронежилет' => 'бронежилети',
            'тактич' => 'тактичне спорядження',
        ];
        
        foreach ($categoryPatterns as $pattern => $category) {
            if (mb_stripos($lowerMsg, $pattern) !== false) {
                return "Ось {$category}:";
            }
        }
        
        // Try to get category from first product
        if (!empty($products[0]['category_path'])) {
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
                ];
                
                foreach ($categoryKeywords as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $productText)) {
                        Log::info('FunctionCallingAgent: extracted category from [Показані товари]', [
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
                ];
                
                foreach ($userCategoryPatterns as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $lowerContent)) {
                        $foundCategory = $category;
                        Log::info('FunctionCallingAgent: extracted category from user message', [
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
        if (!$isFollowUp) {
            return $args;
        }
        
        $currentQuery = $args['query'] ?? '';
        
        // Check if query already contains category keywords
        $hasCategory = preg_match('/(навушник|куртк|берц|штан|шолом|рюкзак|плитонос)/ui', $currentQuery);
        
        if (!$hasCategory) {
            // Inject category into query
            $newQuery = trim($lastCategory . ' ' . $currentQuery);
            $args['query'] = $newQuery;
            
            Log::info('FunctionCallingAgent: injected category context', [
                'original_query' => $currentQuery,
                'new_query' => $newQuery,
                'last_category' => $lastCategory,
                'user_message' => $userMessage,
            ]);
        }
        
        // Add price sorting for "дешевше" / "дорожче" queries
        if ($isCheaper) {
            $args['sort_by'] = 'price_asc';
            Log::info('FunctionCallingAgent: added price_asc sorting for cheaper request');
        } elseif ($isMoreExpensive) {
            $args['sort_by'] = 'price_desc';
            Log::info('FunctionCallingAgent: added price_desc sorting for expensive request');
        }
        
        return $args;
    }
}