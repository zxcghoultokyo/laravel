<?php

namespace App\Services\Agent;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Ai\ToneService;
use App\Services\Ai\PromptPresetService;
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
    private PromptPresetService $promptPresetService;
    
    // Context for prompt preset matching
    private array $currentContext = [];

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
        $this->promptPresetService = app(PromptPresetService::class);
    }

    /**
     * Set context for prompt preset matching.
     * 
     * @param array $context ['language' => 'uk', 'tone' => 'official', 'campaign' => 'black_friday', 'categories' => ['одяг']]
     */
    public function setContext(array $context): self
    {
        $this->currentContext = $context;
        return $this;
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
        
        // Detect trigger query (from proactive triggers)
        $isTriggerQuery = $this->detectTriggerQuery($message);
        if ($isTriggerQuery) {
            $messages[] = [
                'role' => 'system',
                'content' => "🚨 УВАГА: Це ТРИГЕРНИЙ ЗАПИТ! Клієнт прийшов з підказки на сайті, він вже зацікавлений!\n" .
                    "ТВОЯ ЗАДАЧА — ЗАКРИТИ ПРОДАЖ:\n" .
                    "1. Знайди товар через search_products\n" .
                    "2. Дай КОРОТКУ але ВПЕВНЕНУ відповідь (1-2 речення про особливості товару)\n" .
                    "3. Закінчи КОНКРЕТНИМ CTA залежно від товару:\n" .
                    "   - Одяг/взуття → 'Який розмір вам потрібен? Підкажіть зріст/вагу'\n" .
                    "   - Аксесуари/рюкзаки/шоломи → 'Оформлюємо? Або є питання по характеристиках?'\n" .
                    "   - Якщо мало в наявності → 'Залишилось X шт. Резервуємо?'\n" .
                    "НЕ питай 'що саме потрібно?' — ДІЙ ВПЕВНЕНО!"
            ];
        }
        
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
            
            // Detect trigger query for CTA outro
            $isTriggerQuery = $this->detectTriggerQuery($message);
            
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
            
            // For trigger queries, use GPT outro if good, fallback to our generic
            $outro = $structured['outro'] ?? null;
            if ($isTriggerQuery && !empty($allProducts) && empty($outro)) {
                // GPT didn't provide outro - use our universal fallback
                $outro = $this->generateTriggerOutro($allProducts);
            }
            
            // Send outro if exists
            if (!empty($outro)) {
                yield ['type' => 'chunk', 'data' => ['text' => "\n\n" . $outro]];
                $responseText .= "\n\n" . $outro;
            }
            
        } else {
            // No tool calls - GPT responded with text directly
            // The content is already in assistantMessage['content'] from the first non-streaming call
            $content = $assistantMessage['content'] ?? '';
            
            // Use the content directly - no need for another streaming request
            $responseText = $content;
            $responseIntent = 'general';
            
            // Check if the response contains JSON with products
            // This happens when GPT knows the products from conversation history
            $structured = $this->parseStructuredResponse($responseText, []);
            
            if (!empty($structured['products'])) {
                Log::info('StreamingAgent: found products in direct response', [
                    'count' => count($structured['products']),
                ]);
                
                // Send intro text (not the raw JSON!)
                if (!empty($structured['intro'])) {
                    $introChunks = mb_str_split($structured['intro'], 3);
                    foreach ($introChunks as $chunk) {
                        yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                        usleep(10000);
                    }
                }
                
                // Send products
                yield ['type' => 'products', 'data' => [
                    'products' => $structured['products'],
                    'count' => count($structured['products']),
                ]];
                
                // Send outro if exists
                if (!empty($structured['outro'])) {
                    yield ['type' => 'chunk', 'data' => ['text' => "\n\n" . $structured['outro']]];
                }
                
                $responseText = $structured['intro'] . ($structured['outro'] ? "\n\n" . $structured['outro'] : '');
                $responseProducts = $structured['products'];
                $responseIntent = 'product_search';
            } else {
                // No JSON structure - stream as plain text
                $textChunks = mb_str_split($responseText, 3);
                foreach ($textChunks as $chunk) {
                    yield ['type' => 'chunk', 'data' => ['text' => $chunk]];
                    usleep(10000);
                }
            }
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
     * Detect if message is a trigger query (from proactive triggers).
     * These start with specific phrases like "Допоможіть з товаром" or "Цікавить товар".
     */
    private function detectTriggerQuery(string $message): bool
    {
        $triggerPhrases = [
            'допоможіть з товаром',
            'допоможи з товаром',
            'цікавить товар',
            'покажи топ товари в категорії',
            'хочу дізнатись більше про',
        ];
        
        $lowerMessage = mb_strtolower($message);
        
        foreach ($triggerPhrases as $phrase) {
            if (str_starts_with($lowerMessage, $phrase)) {
                Log::info('StreamingAgent: detected trigger query', ['message' => $message]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate appropriate CTA outro for trigger queries based on product type.
     */
    /**
     * Generate appropriate CTA outro for trigger queries based on product attributes.
     * Universal logic - works for any shop (tactical, plumbing, cosmetics, etc.)
     */
    private function generateTriggerOutro(array $products): string
    {
        if (empty($products)) {
            return 'Є питання? Допоможу з вибором!';
        }
        
        $firstProduct = $products[0];
        $quantity = $firstProduct['quantity'] ?? 0;
        
        // Check product attributes for smart CTA
        $hasMultipleSizes = false;
        $hasMultipleColors = false;
        
        foreach ($products as $p) {
            if (!empty($p['size_variants']) && count($p['size_variants']) > 1) {
                $hasMultipleSizes = true;
            }
            if (!empty($p['color_variants']) && count($p['color_variants']) > 1) {
                $hasMultipleColors = true;
            }
        }
        
        // Priority: size selection → color selection → low stock urgency → generic
        
        // Product has multiple sizes - ask which one
        if ($hasMultipleSizes) {
            return 'Який розмір/варіант вам потрібен? Допоможу підібрати!';
        }
        
        // Product has multiple colors - ask preference
        if ($hasMultipleColors) {
            return 'Який колір вам більше підходить?';
        }
        
        // Low stock urgency
        if ($quantity > 0 && $quantity <= 3) {
            return "Залишилось лише {$quantity} шт. в наявності. Оформлюємо?";
        }
        
        // Generic CTA - works for any product type
        return 'Оформлюємо замовлення? Або є питання?';
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
     * First checks for matching PromptPreset, then MERGES with core rules.
     */
    private function getSystemPrompt(): string
    {
        // Try to get custom prompt from PromptPresetService
        $customPrompt = $this->promptPresetService->getSystemPromptForContext(
            $this->currentContext,
            $this->getDefaultVariables()
        );
        
        // Get core rules (these ALWAYS apply)
        $coreRules = $this->getCoreRules();
        
        if ($customPrompt) {
            Log::debug('StreamingAgent: using custom prompt preset + core rules', [
                'context' => $this->currentContext,
            ]);
            // Custom prompt provides identity and expertise, core rules provide behavior
            return $customPrompt . "\n\n" . $coreRules;
        }
        
        // Fall back to default built-in prompt
        return $this->getDefaultSystemPrompt();
    }
    
    /**
     * Get core rules that ALWAYS apply regardless of custom preset.
     */
    private function getCoreRules(): string
    {
        $priceContext = $this->loadPriceContext();
        $shopPhone = $this->getShopPhone();
        
        return <<<RULES
=== ОБОВ'ЯЗКОВІ ПРАВИЛА (ЗАВЖДИ ЗАСТОСОВУЮТЬСЯ) ===

🚨 ТРИГЕРНІ ЗАПИТИ (ЛЮДИНА СУМНІВАЄТЬСЯ — ТРЕБА ДОТИСНУТИ!):
Якщо запит починається з "Допоможіть з товаром" або "Цікавить товар" — це клієнт з ТРИГЕРА!
Він вже зацікавлений, але сумнівається. Твоя задача — ЗАКРИТИ ПРОДАЖ:

1. ОДРАЗУ покажи ДЕТАЛЬНУ інформацію про товар (get_product_details)
2. Не питай "що саме потрібно" — дій ВПЕВНЕНО!
3. Дай конкретний CTA:
   - Якщо товар має розміри: "Який розмір вам підійде? Зріст/вага?"
   - Якщо мало розмірів в наявності: "Залишилось обмежено розмірів. Зателефонуйте {$shopPhone} щоб зарезервувати!"
   - Якщо товар унікальний/особливий: коротко про особливість + "Оформлюємо?"

Приклад тригерного запиту:
USER: "Допоможіть з товаром Комплект армії США ECWCS Gen III Level 7"
ПРАВИЛЬНО: 
- search_products("ECWCS Gen III Level 7") → знаходиш товар
- get_product_details(article) → показуєш деталі
- Коротка відповідь: "Це топовий зимовий комплект US Army для -40°C. Куртка + штани, мембрана PrimaLoft. Який розмір вам потрібен (S-XL)?"

НЕПРАВИЛЬНО: "Уточни, що саме потрібно: підібрати розмір чи порівняти з аналогами?"

ЛАКОНІЧНІСТЬ — КРИТИЧНО:
- Максимум 2-3 речення перед показом товарів
- НЕ пиши розлогих описів — клієнт хоче бачити ТОВАРИ!
- НЕ використовуй Markdown (**, ##, -, •) в текстових відповідях
- Емодзі — тільки 1-2 на повідомлення

ЛІМІТ КАРТОК: МАКСИМУМ 3!
- Завжди показуй НЕ БІЛЬШЕ 3 товарів за раз
- НЕ кажи "топ 5" або "покажу 5" — кажи "топ 3" або просто "ось найкращі варіанти"
- Якщо хочеш показати більше — спитай клієнта "показати ще?"

ГОЛОВНЕ ПРАВИЛО: ЗАВЖДИ ШУКАЙ ЧЕРЕЗ search_products!
Не кажи "цього немає" поки не перевіриш пошуком.

ФОРМАТ ВІДПОВІДІ:
1. ПІСЛЯ search_products → JSON: {"intro": "...", "products": [{"article": "xxx", "comment": "..."}], "_context": "..."}
2. Текстові питання → JSON: {"text": "...", "_context": "..."}
3. intro/text — максимум 2-3 речення!
4. products — максимум 3 товари!

АВТОВИПРАВЛЕННЯ:
- плитноска → плитоноска
- опс кор → Ops-Core
- шлем, каска → шолом

СИНОНІМИ ПРИ ПОШУКУ (використовуй OR):
- шолом → search_products(query="шолом OR каска")

{$priceContext}

ПАМ'ЯТЬ КОНТЕКСТУ:
- НЕ питай "що хочеш" якщо в історії вже є товар
- В історії є [Показані товари: ...] — використовуй їх!
RULES;
    }
    
    /**
     * Get default variables for prompt rendering.
     */
    private function getDefaultVariables(): array
    {
        return [
            'shop_name' => 'Contractor',
            'shop_domain' => 'contractor.kiev.ua',
            'shop_phone' => $this->getShopPhone(),
            'faq_info' => $this->loadFaqInfo(),
            'tone_section' => $this->toneService->getFullPromptSection(),
            'price_context' => $this->loadPriceContext(),
        ];
    }
    
    /**
     * Get shop phone from settings.
     */
    private function getShopPhone(): string
    {
        $settings = Cache::remember('widget_settings_faq', 300, function () {
            return WidgetSettings::first();
        });
        return $settings?->shop_phone ?? '+380 63 631 9919';
    }
    
    /**
     * Get the default built-in system prompt.
     */
    private function getDefaultSystemPrompt(): string
    {
        $faqInfo = $this->loadFaqInfo();
        $toneSection = $this->toneService->getFullPromptSection();
        $priceContext = $this->loadPriceContext();
        
        return <<<PROMPT
Ти — AI-продавець магазину "Contractor" (contractor.kiev.ua). Твоя мета — допомогти клієнту КУПИТИ товар з каталогу.

ВАЖЛИВО: ВІДПОВІДАЙ КОРОТКО (2-3 речення максимум)!

ОБРОБКА ОБРАЗ ТА НЕАДЕКВАТНИХ ПОВІДОМЛЕНЬ:
- Якщо користувач ображає, матюкається, пише нецензурну лексику — НЕ РЕАГУЙ на образу!
- НЕ повторюй образливі слова в своїй відповіді!
- НЕ шукай товари якщо повідомлення містить ТІЛЬКИ образи без товарного запиту!
- Спокійно відповідай: {"text": "Я тут щоб допомогти з вибором товарів. Чим можу бути корисний?", "_context": "ігнорування образи"}
- Якщо образа + товарний запит (наприклад "покажи сраний шолом") — ігноруй образу, шукай товар
- НІКОЛИ не вступай в конфлікт, не виправдовуйся, не пояснюй що тебе образили
- Будь професійним та ввічливим незалежно від тону користувача

ГОЛОВНЕ ПРАВИЛО:
- НІКОЛИ не радь і не згадуй товари яких НЕМАЄ в каталозі!
- Якщо клієнт питає про товар якого немає — скажи "цього немає в нашому асортименті" і запропонуй те що Є
- Ти працюєш НА МАГАЗИН — твоя задача продавати ТЕ ЩО Є, а не давати загальні поради

ЗАБОРОНА ГАЛЮЦИНАЦІЙ — КРИТИЧНО!
- НЕ ВИГАДУЙ факти про товари, кольори, матеріали, виробників!
- Якщо НЕ ЗНАЄШ точно — ЧЕСНО СКАЖИ: "Точної інформації не маю, рекомендую уточнити характеристики в описі товару або у менеджера"
- НІКОЛИ не давай "загальних знань" про військове спорядження якщо не впевнений на 100%!
- Приклад ECWCS Level 7: оригінальний US Army — ТІЛЬКИ сірий (Urban Gray). НЕ ВИГАДУЙ інші кольори!
- Якщо питають про характеристики конкретної моделі — СПОЧАТКУ знайди товар через search_products, потім відповідай ТІЛЬКИ на основі даних з каталогу!
- КРАЩЕ сказати "не знаю" ніж ВИГАДАТИ неправду!

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

СЕЗОННІ ЗАПИТИ — НЕ ВИКОРИСТОВУЙ get_popular_products!:
- "що беруть зимою/взимку", "чьто бэрут зимой" → ТІЛЬКИ search_products(query="куртка зимова OR флісова OR термобілизна", sort_by="popularity")!
- "що беруть влітку" → ТІЛЬКИ search_products(query="футболка OR сорочка літня", sort_by="popularity")!
- НІКОЛИ не викликай get_popular_products для сезонних питань!
- Сезон = ПОШУК товарів відповідного сезону!

КАТЕГОРІЇ ПО СЕЗОНАХ (для search_products):
- ЗИМА (грудень-лютий): куртка зимова, флісова, термобілизна, штани утеплені, шапка
- ЛІТО (червень-серпень): футболка, сорочка, шорти
- ВЕСНА/ОСІНЬ: софтшел, дощовик

КОЛИ ВИКОРИСТОВУВАТИ get_popular_products:
- ТІЛЬКИ для "топ продажів", "що найчастіше купують", "популярне" БЕЗ згадки сезону
- Якщо є слово "зима/літо/осінь/весна" → search_products!

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
2. "Топ товари", "популярне", "хіти" (БЕЗ категорії) → get_popular_products() БЕЗ параметра category = ТОП ВСЬОГО МАГАЗИНУ!
3. "Топ плитоносок", "популярні рюкзаки", "топ в цій категорії" → get_popular_products(category: "...") або search_products з сортуванням
4. Замовлення → get_order_status()
5. Загальне питання про магазин → короткий текст з FAQ
6. "дай посилання", "купити", "замовити цей товар" → get_product_details(article) з контексту розмови
7. "розкажи про нього", "деталі", "характеристики" → get_product_details(article) з [Показані товари:]

ВАЖЛИВО ПРО "ТОП ТОВАРИ":
- "покажи топ товари" / "що беруть" / "популярне" → get_popular_products() БЕЗ category = хіти ВСЬОГО магазину
- "топ плитоносок" / "популярні берці" → get_popular_products(category: "плитоноски") = топ КАТЕГОРІЇ
- НІКОЛИ не бери категорію з контексту розмови для простого "топ товари"!

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
                    'description' => 'Пошук товарів в каталозі. МАКСИМУМ 3 КАРТКИ! ВАЖЛИВО: для запитів з "недорого", "бюджетний", "дешевий" — ОБОВ\'ЯЗКОВО передавай price_max! Для "що беруть/хіти/топ" — ОБОВ\'ЯЗКОВО передавай sort_by="popularity"!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Пошуковий запит'],
                            'product_type' => ['type' => 'string', 'description' => 'Тип товару'],
                            'brand' => ['type' => 'string', 'description' => 'Бренд'],
                            'price_min' => ['type' => 'number', 'description' => 'Мін. ціна (для преміум/дорогих)'],
                            'price_max' => ['type' => 'number', 'description' => 'Макс. ціна (ОБОВ\'ЯЗКОВО для недорогих/бюджетних запитів!)'],
                            'color' => ['type' => 'string', 'description' => 'Колір'],
                            'exclude' => ['type' => 'string', 'description' => 'Виключити слово'],
                            'limit' => ['type' => 'integer', 'description' => 'Кількість результатів (максимум 3)'],
                            'sort_by' => [
                                'type' => 'string', 
                                'enum' => ['relevance', 'popularity', 'price_asc', 'price_desc'],
                                'description' => 'Сортування: "popularity" для "що беруть/хіти/топ", "price_asc" для дешевих, "price_desc" для дорогих. За замовчуванням relevance.'
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
                    'description' => 'Хіти продажів магазину. БЕЗ параметра category = ТОП ВСЬОГО МАГАЗИНУ. З category = топ конкретної категорії. ЗАБОРОНЕНО для сезонних питань — там search_products!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'ТІЛЬКИ якщо клієнт ЯВНО вказав категорію ("топ плитоносок", "популярні рюкзаки"). НЕ бери з контексту! Для "топ товари" / "популярне" — НЕ передавай category!'],
                            'limit' => ['type' => 'integer', 'description' => 'Кількість (максимум 3)'],
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
        $limit = min($args['limit'] ?? 3, 3); // Max 3 cards for display
        $sortBy = $args['sort_by'] ?? 'relevance';
        
        $filters = [];
        if (!empty($args['price_min'])) $filters['price_min'] = (float) $args['price_min'];
        if (!empty($args['price_max'])) $filters['price_max'] = (float) $args['price_max'];
        if (!empty($args['brand'])) $filters['brand'] = $args['brand'];
        
        // Add sort_by to filters for Meilisearch
        if ($sortBy !== 'relevance') {
            $filters['sort_by'] = $sortBy;
        }

        Log::info('toolSearchProducts (streaming): args', ['args' => $args, 'sort_by' => $sortBy]);

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
            $tenantId = $this->searchTool->getCurrentTenantId();
            $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
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
        $limit = min($args['limit'] ?? 3, 3); // Max 3 cards for display
        $tenantId = $this->searchTool->getCurrentTenantId();
        // v7: invalidate after adding images support
        $cacheKey = 'popular_products_v7:' . ($tenantId ?? 'all') . ':' . ($category ?? 'all') . ':' . $limit;
        
        return Cache::remember($cacheKey, 300, function () use ($category, $limit, $tenantId) {
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
            $salesQuery = Product::where('orders_count', '>', 0);
            if ($tenantId) {
                $salesQuery->where('tenant_id', $tenantId);
            }
            $hasOrdersData = $salesQuery->exists();
            
            if ($hasOrdersData) {
                // USE REAL SALES DATA - query products by orders_count
                $query = Product::where('in_stock', true)
                    ->where('orders_count', '>', 0)
                    ->where('quantity', '>', 0);
                
                // Filter by tenant
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }
                
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
                $tenantId = $this->searchTool->getCurrentTenantId();
                $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
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

        // Apply tenant filter to avoid cross-tenant data leakage
        $tenantId = $this->searchTool->getCurrentTenantId();
        $query = Product::where('article', $article);
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        $product = $query->first();
        if (!$product) return ['error' => 'Product not found'];

        // Extract images from product
        $images = $this->extractProductImages($product);

        return [
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'article' => $product->article,
                'price' => $product->price,
                'price_old' => $product->price_old,
                'brand' => $product->brand,
                'in_stock' => $product->in_stock,
                'link' => $product->link,
                'images' => $images,
                'category_path' => $product->category_path,
            ],
        ];
    }
    
    /**
     * Extract images from product (raw or images field).
     */
    private function extractProductImages(Product $product): array
    {
        $images = [];

        // 1. Try raw['pictures'] first (Horoshop format)
        if ($product->raw && is_array($product->raw) && !empty($product->raw['pictures'])) {
            $images = collect($product->raw['pictures'])
                ->map(fn($pic) => is_array($pic) ? ($pic['url'] ?? null) : $pic)
                ->filter()
                ->values()
                ->toArray();
        }

        // 2. Try raw['images']
        if (empty($images) && $product->raw && is_array($product->raw) && !empty($product->raw['images'])) {
            $imgs = $product->raw['images'];
            if (is_array($imgs)) {
                $images = collect($imgs)
                    ->map(fn($img) => is_array($img) ? ($img['url'] ?? $img['src'] ?? null) : $img)
                    ->filter()
                    ->values()
                    ->toArray();
            }
        }

        // 3. Fallback to images field
        if (empty($images) && $product->images) {
            $imgs = $product->images;
            if (is_string($imgs)) {
                $imgs = json_decode($imgs, true) ?: [$imgs];
            }
            if (is_array($imgs)) {
                $images = array_values(array_filter($imgs));
            }
        }

        // 4. Single image fallbacks
        if (empty($images) && $product->raw && is_array($product->raw)) {
            if (!empty($product->raw['image'])) {
                $images = [$product->raw['image']];
            } elseif (!empty($product->raw['main_image'])) {
                $images = [$product->raw['main_image']];
            }
        }

        return $images;
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
        Log::debug('StreamingAgent: parseStructuredResponse input', [
            'responseText_length' => strlen($responseText),
            'allProducts_count' => count($allProducts),
            'responseText_preview' => mb_substr($responseText, 0, 200),
        ]);
        
        $json = null;
        $introText = '';
        $outroText = '';
        
        // Try to extract JSON object like {"intro":"...", "products":[...]}
        if (preg_match('/\{[\s\S]*\}/u', $responseText, $matches)) {
            $json = json_decode($matches[0], true);
            Log::debug('StreamingAgent: parsed JSON object', ['json_keys' => $json ? array_keys($json) : null]);
        }
        
        // If no valid JSON object or no products in it, try to find JSON array [{"article":"..."}, ...]
        if ((!$json || !isset($json['products'])) && preg_match('/\[[\s\S]*\]/u', $responseText, $arrayMatches)) {
            $productsArray = json_decode($arrayMatches[0], true);
            if (is_array($productsArray) && !empty($productsArray) && isset($productsArray[0]['article'])) {
                Log::debug('StreamingAgent: found products array', ['count' => count($productsArray)]);
                $json = ['products' => $productsArray];
                
                // Extract intro text (everything before the JSON array)
                $arrayPos = strpos($responseText, $arrayMatches[0]);
                if ($arrayPos > 0) {
                    $introText = trim(substr($responseText, 0, $arrayPos));
                    // Clean up any trailing "products": or similar
                    $introText = preg_replace('/["\',\s:]+$/', '', $introText);
                    $introText = preg_replace('/\s*"?products"?\s*:?\s*$/i', '', $introText);
                    $json['intro'] = trim($introText);
                }
                
                // Extract outro (everything after JSON array)
                $afterArray = substr($responseText, $arrayPos + strlen($arrayMatches[0]));
                $afterArray = trim(preg_replace('/^[\s\}\],]+/', '', $afterArray));
                if (!empty($afterArray) && strlen($afterArray) > 10) {
                    $json['outro'] = $afterArray;
                }
            }
        }

        $productsByArticle = [];
        foreach ($allProducts as $p) {
            $productsByArticle[$p['article']] = $p;
        }

        if ($json && isset($json['products']) && is_array($json['products'])) {
            Log::info('StreamingAgent: processing structured products', [
                'products_count' => count($json['products']),
                'has_intro' => !empty($json['intro']),
            ]);
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
                    Log::info('StreamingAgent: looking up product by article in DB', ['article' => $article]);
                    
                    // Apply tenant filter to avoid cross-tenant data leakage
                    $tenantId = $this->searchTool->getCurrentTenantId();
                    $fallbackQuery = \App\Models\Product::where('article', $article);
                    if ($tenantId) {
                        $fallbackQuery->where('tenant_id', $tenantId);
                    }
                    $dbProduct = $fallbackQuery->first();
                    if ($dbProduct) {
                        Log::info('StreamingAgent: found product in DB', [
                            'article' => $article,
                            'id' => $dbProduct->id,
                            'title' => $dbProduct->title,
                        ]);
                        $tenantId = $this->searchTool->getCurrentTenantId();
                        $cards = $this->detailsTool->getCards([$dbProduct->id], 10, $tenantId);
                        $product = $cards[0] ?? null;
                        
                        if (!$product) {
                            // Direct fallback if getCards fails
                            $product = [
                                'id' => $dbProduct->id,
                                'article' => $dbProduct->article,
                                'title' => $dbProduct->title,
                                'price' => $dbProduct->price,
                                'link' => $dbProduct->link,
                                'in_stock' => $dbProduct->in_stock,
                                'images' => $this->extractProductImages($dbProduct),
                                'brand' => $dbProduct->brand,
                                'category_path' => $dbProduct->category_path,
                            ];
                            Log::info('StreamingAgent: used direct fallback for product', ['article' => $article]);
                        }
                    } else {
                        Log::warning('StreamingAgent: product not found in DB', ['article' => $article]);
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
            $tenantId = $this->searchTool->getCurrentTenantId();
            $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
            
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
            // Get tenant ID from search tool (set via request)
            $tenantId = $this->searchTool->getCurrentTenantId();
            
            // Bypass TenantScope to find existing session regardless of tenant
            $session = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('session_id', $sessionId)
                ->first();
            
            if (!$session) {
                // Create new session with tenant_id
                $session = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->create([
                        'session_id' => $sessionId,
                        'tenant_id' => $tenantId,
                        'language' => 'uk',
                        'status' => 'open',
                        'meta' => [],
                    ]);
            } elseif ($session->tenant_id === null && $tenantId !== null) {
                // Update tenant_id if session existed but had NULL tenant
                $session->update(['tenant_id' => $tenantId]);
            }

            // ChatMessage also has TenantScope - bypass it and set tenant_id explicitly
            ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)->create([
                'tenant_id' => $session->tenant_id ?? $tenantId,
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
            
            Log::info('StreamingAgent: user message logged', [
                'session_id' => $sessionId,
                'tenant_id' => $tenantId,
            ]);
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
            // Bypass TenantScope to find session regardless of tenant
            $session = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('session_id', $sessionId)
                ->first();
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

            // ChatMessage also has TenantScope - bypass it and set tenant_id explicitly
            ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)->create([
                'tenant_id' => $session->tenant_id,
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
            // Bypass TenantScope to find session regardless of tenant
            $session = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('session_id', $sessionId)
                ->first();
            if (!$session) return [];

            // Also bypass TenantScope for ChatMessage query
            $messages = ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('chat_session_id', $session->id)
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
