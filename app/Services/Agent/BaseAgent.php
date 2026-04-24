<?php

namespace App\Services\Agent;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Product;
use App\Models\ProductSynonym;
use App\Models\WidgetSettings;
use App\Services\Agent\Tools\KnowledgeLookupTool;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Ai\PromptModulesService;
use App\Services\Ai\PromptPresetService;
use App\Services\Ai\ToneService;
use App\Services\Catalog\CategoryPatternService;
use App\Services\Catalog\PriceStatsService;
use App\Services\Chat\PipelineTracer;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Usage\AiCostTrackingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Base Agent with shared functionality for both streaming and non-streaming agents.
 * Contains all common logic: prompts, tools, context extraction, product handling.
 */
abstract class BaseAgent
{
    protected string $apiKey;

    protected string $model;

    protected string $baseUrl;

    protected MeiliProductSearchTool $searchTool;

    protected ProductDetailsTool $detailsTool;

    protected OrderSearchService $orderSearchService;

    protected ToneService $toneService;

    protected PromptPresetService $promptPresetService;

    protected PromptModulesService $promptModulesService;

    protected CategoryPatternService $categoryPatternService;

    protected AiCostTrackingService $aiCostTracker;

    protected KnowledgeLookupTool $knowledgeLookupTool;

    /**
     * Service/info pages that are NOT product categories.
     * Users sometimes navigate to these and trigger auto-message.
     */
    protected const SERVICE_CATEGORIES = [
        'контактна інформація',
        'контактна информація',
        'контакти',
        'контакт',
        'про нас',
        'про компанію',
        'про магазин',
        'о нас',
        'о компании',
        'доставка і оплата',
        'доставка та оплата',
        'доставка',
        'оплата',
        'повернення',
        'гарантія',
        'умови',
        'політика конфіденційності',
        'угода користувача',
        'публічна оферта',
        'новини',
        'блог',
        'статті',
        'faq',
        'часті питання',
        'відгуки',
        'акції',
        'розпродаж',
        'знижки',
        'головна',
        'home',
        'contact',
        'about',
        'about us',
        'delivery',
        'payment',
        'terms',
        'privacy',
    ];

    // Context for prompt preset matching
    protected array $currentContext = [];

    // Track shown product IDs to exclude from subsequent searches
    protected array $shownProductIds = [];

    // Current user message (for modular prompt building)
    protected string $currentMessage = '';

    // Current session ID (set at stream/processMessage entry)
    protected ?string $activeSessionId = null;

    public function __construct(
        MeiliProductSearchTool $searchTool,
        ProductDetailsTool $detailsTool,
        OrderSearchService $orderSearchService
    ) {
        $config = config('services.openai', []);
        $this->apiKey = $config['key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4o';
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $this->searchTool = $searchTool;
        $this->detailsTool = $detailsTool;
        $this->orderSearchService = $orderSearchService;
        $this->toneService = app(ToneService::class);
        $this->promptPresetService = app(PromptPresetService::class);
        $this->promptModulesService = app(PromptModulesService::class);
        $this->categoryPatternService = app(CategoryPatternService::class);
        $this->aiCostTracker = app(AiCostTrackingService::class);
        $this->knowledgeLookupTool = app(KnowledgeLookupTool::class);
    }

    /**
     * Track OpenAI API usage from response data.
     */
    protected function trackAiUsage(string $source, ?array $data, ?string $sessionId = null, ?int $responseTimeMs = null, bool $isError = false): void
    {
        if (! $data) {
            return;
        }

        $usage = $data['usage'] ?? [];
        if (empty($usage) && ! $isError) {
            return;
        }

        $tenantId = $this->searchTool->getCurrentTenantId();
        $model = $data['model'] ?? $this->model;

        $this->aiCostTracker->log(
            source: $source,
            model: $model,
            usage: $usage,
            tenantId: $tenantId,
            sessionId: $sessionId,
            responseTimeMs: $responseTimeMs,
            isError: $isError,
        );
    }

    /**
     * Set context for prompt preset matching.
     */
    public function setContext(array $context): self
    {
        $this->currentContext = $context;

        return $this;
    }

    // ============================================================
    // LAST PRODUCT CONTEXT (for follow-up queries)
    // ============================================================

    /**
     * Save the last product context (query, source) so follow-up queries
     * ("дорожче", "дешевше") can preserve age/category context.
     */
    protected function saveLastProductContext(?string $sessionId, string $originalMessage, string $source): void
    {
        if (! $sessionId) {
            return;
        }

        Cache::put("last_product_ctx_{$sessionId}", [
            'original_message' => $originalMessage,
            'source' => $source,
        ], now()->addHours(6));
    }

    /**
     * Load the last product context for follow-up handling.
     */
    protected function loadLastProductContext(?string $sessionId): ?array
    {
        if (! $sessionId) {
            return null;
        }

        return Cache::get("last_product_ctx_{$sessionId}");
    }

    /**
     * Check if the message is a follow-up query (дорожче, дешевше, ще, інші, etc.)
     */
    protected function isFollowUpMessage(string $message): bool
    {
        $lower = mb_strtolower($message);
        $patterns = ['дешевше', 'дешевший', 'дорожче', 'дорожчий', 'ще ', 'інші', 'інш', 'аналог', 'подібн', 'такий же', 'такі ж', 'більше варіант', 'ще варіант'];

        foreach ($patterns as $pattern) {
            if (mb_stripos($lower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    // ============================================================
    // SERVICE CATEGORY HANDLER
    // ============================================================

    /**
     * Check if the query mentions a service/info page instead of product category.
     * Returns the matched service category name or null.
     */
    protected function detectServiceCategory(string $message): ?string
    {
        $lower = mb_strtolower(trim($message));

        // Check for exact match or "категорії X" pattern
        foreach (self::SERVICE_CATEGORIES as $serviceCategory) {
            if (mb_stripos($lower, $serviceCategory) !== false) {
                return $serviceCategory;
            }
        }

        return null;
    }

    /**
     * Handle queries that mention service/info pages.
     * Provides helpful response instead of "no products found".
     */
    protected function handleServiceCategoryQuery(string $message, string $serviceCategory): array
    {
        $lower = mb_strtolower($serviceCategory);

        // Determine appropriate response based on service type
        $response = match (true) {
            str_contains($lower, 'контакт') => 'Контактну інформацію можна знайти на сторінці сайту. Якщо у Вас є питання — напишіть їх тут, я з радістю допоможу! Або зателефонуйте за номером, вказаним на сайті.',
            str_contains($lower, 'доставк') || str_contains($lower, 'delivery') => 'Інформація про доставку є на відповідній сторінці сайту. Якщо коротко: ми доставляємо по всій Україні Новою Поштою. Чим можу допомогти з товарами?',
            str_contains($lower, 'оплат') || str_contains($lower, 'payment') => 'Ми приймаємо оплату: картою онлайн, накладений платіж, безготівковий розрахунок для ФОП/юридичних осіб. Що саме Ви шукаєте?',
            str_contains($lower, 'про нас') || str_contains($lower, 'про магазин') || str_contains($lower, 'про компан') || str_contains($lower, 'about') => 'Ми спеціалізуємось на тактичному спорядженні та військовому одязі. Якщо шукаєте конкретний товар — просто напишіть, і я підберу найкращі варіанти!',
            str_contains($lower, 'гарант') || str_contains($lower, 'повернен') => 'Гарантія та умови повернення описані на сайті. Загалом — 14 днів на повернення, гарантія залежить від товару. Чим можу допомогти?',
            str_contains($lower, 'акці') || str_contains($lower, 'знижк') || str_contains($lower, 'розпродаж') => 'Актуальні акції та знижки відображаються на картках товарів. Що саме Вас цікавить? Можу підібрати товари зі знижкою у потрібній категорії.',
            str_contains($lower, 'faq') || str_contains($lower, 'питан') => 'Задайте своє питання тут — я відповім одразу! Можу допомогти з підбором товару, розмірами, наявністю.',
            default => 'Це інформаційна сторінка сайту. Якщо шукаєте товари — напишіть що саме, і я підберу найкращі варіанти!',
        };

        Log::info('BaseAgent: handled service category query', [
            'message' => $message,
            'service_category' => $serviceCategory,
        ]);

        return [
            'message' => $response,
            'products' => [],
            'messages' => [
                ['type' => 'text', 'content' => $response],
            ],
            'meta' => [
                'intent' => 'faq',
                'agent' => 'function_calling',
                'source' => 'service_category_handler',
                'service_category' => $serviceCategory,
            ],
        ];
    }

    // ============================================================
    // IMPLICIT QUERY HANDLER
    // ============================================================

    /**
     * When GPT responds with age clarification instead of searching, force a direct search.
     * Returns product results if original message looks searchable, null otherwise.
     */
    protected function forceSearchOnAgeClarification(string $gptResponse, string $originalMessage): ?array
    {
        // Only intercept age-clarification responses
        $agePhrases = ['для якого віку', 'якого віку', 'вік дитини', 'скільки років', 'для кого шукаєте'];
        $responseLower = mb_strtolower($gptResponse);
        $isAgeClarification = false;
        foreach ($agePhrases as $phrase) {
            if (str_contains($responseLower, $phrase)) {
                $isAgeClarification = true;
                break;
            }
        }

        if (! $isAgeClarification) {
            return null;
        }

        // Strip filler words from original message to get search query
        $searchQuery = preg_replace('/\b(а|і|й|та|що|як|ну|ой|от|ось|це|той|ці|ті|яке|якесь|якийсь|якась|щось|щось|будь-що|ще|також|може|про|для|на|в|у|до|від|по|із|зі|або|чи|але|де|там|тут|от|ось|покажи|мені|будь\s+ласка|хочу|потрібно|потрібен|потрібна|шукаю|є|маєте|можна|знайти|знайди|підібрати|підбери|порадь|порадиш|підкажи|підкажіть|розкажи|розкажіть|дай|давай|скинь)\b/ui', '', $originalMessage);
        $searchQuery = preg_replace('/\s{2,}/u', ' ', trim($searchQuery));

        if (mb_strlen($searchQuery) < 2) {
            return null;
        }

        Log::info('BaseAgent: force search on age clarification', [
            'gpt_wanted_to_say' => mb_substr($gptResponse, 0, 100),
            'original_message' => $originalMessage,
            'search_query' => $searchQuery,
        ]);

        $products = $this->searchTool->search($searchQuery, [], 9);

        if (empty($products)) {
            return null; // Let GPT response through
        }

        // Shuffle for variety
        if (count($products) > 3) {
            $pool = array_slice($products, 0, min(count($products), 9));
            shuffle($pool);
            $products = $pool;
        }
        $products = array_slice($products, 0, 3);

        // Get full product cards
        $ids = array_column($products, 'id');
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cards = $this->detailsTool->getCards($ids, 3, $tenantId);
        if (! empty($cards)) {
            $products = $cards;
        }

        if (empty($products)) {
            return null;
        }

        return [
            'products' => $products,
            'intro' => "Ось що я знайшов за запитом «{$searchQuery}»:",
        ];
    }

    /**
     * When GPT responds with a list of hallucinated products (no tool_calls, no DB matches),
     * detect the pattern and force a real search using the original user message.
     */
    protected function forceSearchOnHallucinatedProducts(string $gptResponse, string $originalMessage): ?array
    {
        $responseLower = mb_strtolower($gptResponse);

        // Pattern 1: Numbered or bulleted product list (3+ items)
        $hasNumberedList = preg_match_all('/^\s*\d+[\.\)]\s*.{5,}/um', $gptResponse) >= 3;
        $hasBulletedList = preg_match_all('/^\s*[-•]\s*.{5,}/um', $gptResponse) >= 3;

        // Pattern 2: Recommendation phrases typical for hallucinated responses
        $hasRecommendPhrase = (bool) preg_match('/рекоменд|пропону|ось (деякі|кілька|варіант|що можу)|можу запропонувати|зверніть увагу на/ui', $gptResponse);

        // Must have a product list AND a recommendation phrase — reduces false positives
        if (! (($hasNumberedList || $hasBulletedList) && $hasRecommendPhrase)) {
            return null;
        }

        // Extra safety: skip if GPT response is clearly FAQ/informational (no product names)
        // Check that list items look like product names (start with uppercase Cyrillic or contain quotes)
        $listItemCount = 0;
        $productLikeCount = 0;
        if (preg_match_all('/^\s*(?:\d+[\.\)]|[-•])\s*\*{0,2}(.+)/um', $gptResponse, $listMatches)) {
            $listItemCount = count($listMatches[1]);
            foreach ($listMatches[1] as $item) {
                $item = trim($item);
                // Product-like: starts with uppercase, contains quotes, or has price-like patterns
                if (preg_match('/^[А-ЯІЇЄҐA-Z"«]/u', $item) || preg_match('/\d+\s*грн/u', $item)) {
                    $productLikeCount++;
                }
            }
        }

        // Need at least 2 product-like list items
        if ($productLikeCount < 2) {
            return null;
        }

        // Strip filler words from original message to get search query
        $searchQuery = preg_replace('/\b(покажи|мені|будь\s+ласка|хочу|потрібно|потрібен|потрібна|шукаю|є|маєте|можна|знайти|знайди|підібрати|порадь|порадити|порекомендуй|щось|підкажи|підкажіть)\b/ui', '', $originalMessage);
        $searchQuery = preg_replace('/\s{2,}/u', ' ', trim($searchQuery));

        if (mb_strlen($searchQuery) < 2) {
            return null;
        }

        Log::info('BaseAgent: force search on hallucinated products', [
            'gpt_response_preview' => mb_substr($gptResponse, 0, 200),
            'original_message' => $originalMessage,
            'search_query' => $searchQuery,
            'list_items' => $listItemCount,
            'product_like' => $productLikeCount,
        ]);

        $products = $this->searchTool->search($searchQuery, [], 9);

        if (empty($products)) {
            return null;
        }

        // Shuffle for variety
        if (count($products) > 3) {
            $pool = array_slice($products, 0, min(count($products), 9));
            shuffle($pool);
            $products = $pool;
        }
        $products = array_slice($products, 0, 3);

        // Get full product cards
        $ids = array_column($products, 'id');
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cards = $this->detailsTool->getCards($ids, 3, $tenantId);
        if (! empty($cards)) {
            $products = $cards;
        }

        if (empty($products)) {
            return null;
        }

        return [
            'products' => $products,
            'intro' => "Ось що я знайшов за запитом «{$searchQuery}»:",
        ];
    }

    /**
     * Detect implicit queries and search directly without GPT.
     * Returns array if handled, null if should continue to GPT.
     */
    protected function handleImplicitQuery(string $message, ?string $sessionId): ?array
    {
        // First check for service category queries
        $serviceCategory = $this->detectServiceCategory($message);
        if ($serviceCategory) {
            return $this->handleServiceCategoryQuery($message, $serviceCategory);
        }

        // REASONING QUERIES: force GPT path. Do NOT intercept with fast-path search.
        // Covers: "порівняй X і Y", "порадь 3 варіанти", "поясни чому", "який краще", "підбери за".
        if ($this->isReasoningQuery($message)) {
            PipelineTracer::current()?->step('agent.reasoning_query_detected', [
                'handler' => 'handleImplicitQuery',
                'decision' => 'skip_fast_path',
            ]);
            Log::info('BaseAgent: reasoning query detected, forcing GPT path', [
                'message' => $message,
            ]);

            return null;
        }

        // NEGATIVE FEEDBACK: "X не підходить" / "не треба X" → also force GPT
        // so it can re-plan instead of re-searching by the same keyword.
        if ($this->isNegativeFeedbackQuery($message)) {
            PipelineTracer::current()?->step('agent.negative_feedback_detected', [
                'handler' => 'handleImplicitQuery',
                'decision' => 'skip_fast_path',
            ]);
            Log::info('BaseAgent: negative feedback detected, forcing GPT path', [
                'message' => $message,
            ]);

            return null;
        }

        $lower = mb_strtolower(trim($message));

        // AGE/GIFT QUERY HANDLER — force search when user mentions age
        // GPT often asks clarifying questions instead of searching for "подарунок на 1 рік"
        $ageResult = $this->handleAgeQuery($lower, $message);
        if ($ageResult) {
            return $ageResult;
        }

        // Patterns that imply product need without naming the product
        $implicitPatterns = [
            // Calling/communication → smartphone
            '/\b(call|дзвонити|зателефонувати|позвонить)\b.*\b(mother|mom|мама|мамі|батько|друг|someone)\b/ui' => 'smartphone OR телефон OR phone',
            '/\b(make|робити)\s+(calls?|дзвінки)\b/ui' => 'smartphone OR телефон OR phone',
            '/\bneed\s+to\s+call\b/ui' => 'smartphone OR телефон OR phone',
            '/\bsomething\s+to\s+call\b/ui' => 'smartphone OR телефон OR phone',

            // Writing → pen
            '/\bsomething\s+to\s+write\b/ui' => 'pen OR ручка OR marker',
            '/\bписати\s+чимось\b/ui' => 'ручка OR pen',

            // Cutting → knife
            '/\bsomething\s+to\s+cut\b/ui' => 'knife OR ніж OR multitool',
            '/\bрізати\s+чимось\b/ui' => 'ніж OR knife OR мультитул',

            // Head protection → helmet
            '/\bhead\s+protection\b/ui' => 'шолом OR helmet OR каска',
            '/\bзахист\s+голови\b/ui' => 'шолом OR helmet OR каска',
            '/\bprotect\s+(my\s+)?head\b/ui' => 'шолом OR helmet OR каска',

            // Stay warm → jacket
            '/\bstay\s+warm\b/ui' => 'куртка OR jacket OR термобілизна',
            '/\bзігрітися\b/ui' => 'куртка OR jacket',
            '/\bcold\s+weather\b/ui' => 'термобілизна OR куртка OR Level 7',

            // Carry stuff → backpack/bag
            '/\bcarry\s+(stuff|things|gear)\b/ui' => 'рюкзак OR backpack OR сумка',
            '/\bneed\s+(a\s+)?bag\b/ui' => 'сумка OR рюкзак OR підсумок',
            '/\bнести\s+речі\b/ui' => 'рюкзак OR сумка',

            // Stop bleeding → tourniquet/medical
            '/\bstop\s+(the\s+)?bleeding\b/ui' => 'турнікет OR tourniquet OR бандаж',
            '/\bзупинити\s+кров\b/ui' => 'турнікет OR джгут OR бандаж',
            '/\bfirst\s+aid\b/ui' => 'аптечка OR турнікет OR медицина',

            // Body armor → plate carrier
            '/\bbody\s+(armor|armour|protection)\b/ui' => 'бронежилет OR plate carrier OR плитоноска',
            '/\bзахист\s+тіла\b/ui' => 'бронежилет OR плитоноска',
            '/\bbullet\s*proof\b/ui' => 'бронежилет OR плитоноска OR броня',
        ];

        foreach ($implicitPatterns as $pattern => $searchQuery) {
            if (preg_match($pattern, $lower)) {
                Log::info('BaseAgent: detected implicit query', [
                    'message' => $message,
                    'pattern' => $pattern,
                    'search_query' => $searchQuery,
                ]);

                // Execute search directly
                $products = $this->searchTool->search($searchQuery, [], 3);

                if (! empty($products)) {
                    // Determine language for response
                    $isEnglish = (bool) preg_match('/[a-zA-Z]{3,}/', $message);
                    $intro = $this->generateContextualIntro($message, $products, $isEnglish);

                    return [
                        'message' => $intro,
                        'products' => $products,
                        'messages' => [
                            ['type' => 'text', 'content' => $intro],
                            ['type' => 'products', 'products' => $products],
                        ],
                        'meta' => [
                            'intent' => 'product_search',
                            'agent' => 'function_calling',
                            'source' => 'implicit_query_handler',
                            'implicit_pattern' => $pattern,
                        ],
                    ];
                }

                // No products found - let GPT handle
                break;
            }
        }

        // CONFIRMATION WORDS HANDLER
        // If user says "дозволяю", "давай", "хочу", "можна" - extract category from history and search
        $confirmationWords = ['дозволяю', 'давай', 'хочу', 'можна', 'показуй', 'покажи', 'будь ласка', 'авжеж', 'гаразд', 'згода'];
        if (in_array($lower, $confirmationWords) || preg_match('/^(дозволяю|давай|хочу|показуй|покажи)$/ui', $lower)) {
            // Extract category from LAST user message that was a product query
            $history = $this->loadConversationHistory($sessionId);

            // Find the LAST user message that looks like a product query (skip confirmations)
            $lastProductQuery = null;
            foreach (array_reverse($history) as $msg) {
                if (($msg['role'] ?? '') === 'user') {
                    $content = mb_strtolower($msg['content'] ?? '');
                    // Skip if message itself is a confirmation word
                    if (in_array($content, $confirmationWords)) {
                        continue;
                    }
                    // Check if it contains a product category
                    $categoryPatterns = [
                        'куртк', 'берц', 'штан', 'футболк', 'шолом', 'навушник',
                        'плитонос', 'рюкзак', 'підсум', 'термобіл', 'білизн',
                        'шевр', 'бронежилет', 'бронеплат', 'рукавиц', 'балаклав',
                        'окуляр', 'ремен', 'пояс', 'панам', 'шапк', 'кепк', 'фліс',
                    ];
                    foreach ($categoryPatterns as $cat) {
                        if (mb_stripos($content, $cat) !== false) {
                            $lastProductQuery = $msg['content'];
                            break 2; // Exit both loops
                        }
                    }
                }
            }

            if ($lastProductQuery) {
                Log::info('BaseAgent: confirmation word detected, using last product query', [
                    'message' => $message,
                    'last_product_query' => $lastProductQuery,
                ]);

                // Map to canonical search term (same as handleShortProductQuery)
                $searchQuery = $lastProductQuery;
                $categoryMap = [
                    'куртк' => 'куртки',
                    'берц' => 'берці',
                    'штан' => 'штани',
                    'футболк' => 'футболки',
                    'шолом' => 'шоломи',
                    'навушник' => 'навушники',
                    'плитонос' => 'плитоноски',
                    'рюкзак' => 'рюкзаки',
                    'підсум' => 'підсумки',
                    'термобіл' => 'термобілизна',
                    'білизн' => 'термобілизна',
                    'шевр' => 'шеврони',
                    'бронежилет' => 'бронежилети',
                    'бронеплат' => 'бронеплати',
                    'рукавиц' => 'рукавиці',
                    'балаклав' => 'балаклави',
                    'окуляр' => 'окуляри',
                    'ремен' => 'ремені',
                    'пояс' => 'пояси',
                    'панам' => 'панами',
                    'шапк' => 'шапки',
                    'кепк' => 'кепки',
                    'фліс' => 'фліс',
                ];

                $lowerQuery = mb_strtolower($lastProductQuery);
                foreach ($categoryMap as $cat => $canonical) {
                    if (mb_stripos($lowerQuery, $cat) !== false) {
                        $searchQuery = $canonical;
                        break;
                    }
                }

                // Search for the canonical category
                $products = $this->searchTool->search($searchQuery, [], 3);

                if (! empty($products)) {
                    // Get full product cards
                    $ids = array_column($products, 'id');
                    $tenantId = $this->searchTool->getCurrentTenantId();
                    $cards = $this->detailsTool->getCards($ids, 3, $tenantId);
                    if (! empty($cards)) {
                        $products = $cards;
                    }

                    return [
                        'message' => "Ось {$searchQuery}:",
                        'products' => $products,
                        'messages' => [
                            ['type' => 'text', 'content' => "Ось {$searchQuery}:"],
                            ['type' => 'products', 'products' => $products],
                        ],
                        'meta' => [
                            'intent' => 'product_search',
                            'agent' => 'function_calling',
                            'source' => 'confirmation_context_handler',
                        ],
                    ];
                }
            }
        }

        // AGE-BASED QUERY INTERCEPTOR (for toy stores)
        // "для малюка", "для тодлера", "для дошкільняти" etc. → direct search with category
        $ageCategory = $this->searchTool->detectAgeCategoryFromQuery($message);
        if ($ageCategory) {
            $wordCount = count(preg_split('/\s+/u', trim($message)));
            if ($wordCount <= 3) {
                PipelineTracer::current()?->step('agent.age_query_interceptor', [
                    'message' => $message,
                    'age_category' => $ageCategory,
                ]);

                $products = $this->searchTool->search('', ['category' => $ageCategory], 3);
                if (! empty($products)) {
                    $ids = array_column($products, 'id');
                    $tenantId = $this->searchTool->getCurrentTenantId();
                    $cards = $this->detailsTool->getCards($ids, 3, $tenantId);
                    if (! empty($cards)) {
                        $products = $cards;
                    }

                    $catUpper = mb_strtoupper($ageCategory);

                    return [
                        'message' => "Ось товари з категорії {$catUpper}:",
                        'products' => $products,
                        'messages' => [
                            ['type' => 'text', 'content' => "Ось товари з категорії {$catUpper}:"],
                            ['type' => 'products', 'products' => $products],
                        ],
                        'meta' => [
                            'intent' => 'product_search',
                            'agent' => 'function_calling',
                            'source' => 'age_query_interceptor',
                        ],
                    ];
                }
            }
        }

        // UNIVERSAL SHORT QUERY HANDLER
        // If message is 1-3 words and looks like a product type/category, search directly.
        // This prevents GPT from asking "уточніть запит" for valid product queries like "підсумки".
        $shortQueryResult = $this->handleShortProductQuery($message);
        if ($shortQueryResult) {
            return $shortQueryResult;
        }

        return null;
    }

    /**
     * Handle queries mentioning specific age — bypass GPT and search directly.
     * GPT often asks clarifying questions instead of searching for "подарунок на 1 рік".
     * Only active for tenants with age-based categories (children's stores).
     */
    protected function handleAgeQuery(string $lower, string $originalMessage): ?array
    {
        // Only apply age handling for stores with age categories
        if (! $this->hasAgeCategories()) {
            return null;
        }

        // Detect age in years: "1 рік", "3 роки", "7 років", "на 2 роки"
        $hasDigitYear = (bool) preg_match('/(\d{1,2})\s*(?:рок|рік|річ|р\.)/ui', $lower);
        $hasDigitMonth = (bool) preg_match('/(\d{1,2})\s*(?:місяц|міс)/ui', $lower);
        // No-digit year references: "на рік", "на рочок", "на годик", "один рочок" → treat as 1 year
        $hasNoDigitYear = (bool) preg_match('/\b(?:на|у|в)\s+(?:один\s+)?(?:рік|рочок|годик|рочка)\b/ui', $lower)
            || (bool) preg_match('/\b(?:рочок|годик)\b/ui', $lower);

        if (! $hasDigitYear && ! $hasDigitMonth && ! $hasNoDigitYear) {
            return null;
        }

        // Extract product-related words from the query (strip age/filler phrases)
        $productQuery = preg_replace('/\d{1,2}\s*(?:рок\w*|рік|річ\w*|р\.|місяц\w*|міс\w*)/ui', '', $originalMessage);
        // Strip no-digit year phrases: "на рік", "на рочок", "на годик", "один рочок"
        $productQuery = preg_replace('/\b(?:на|у|в)\s+(?:один\s+)?(?:рік|рочок|годик|рочка)\b/ui', '', $productQuery);
        $productQuery = preg_replace('/\b(?:рочок|годик)\b/ui', '', $productQuery);
        // Use relaxed pattern for "дитин*" to handle typos like "дитттинві" (triple т)
        $productQuery = preg_replace('/\bди[тт]+ин\w*\b/ui', '', $productQuery);
        $productQuery = preg_replace('/\b(для|дитяч\w*|малюк\w*|на|від|до|підлітк\w*|хлопчик\w*|дівчинк\w*|покажи|мені|будь\s+ласка|подарунок|подарунки|а|і|й|та|що|як|ну|от|ось|це|той|ці|ті|щось|якщо|може|якийсь|якийс\w*|якась|якусь|якесь|яке|який|яка|про|ще|дуже|трохи|потрібн\w*|хоч\w*|порадь\w*|порекомендуй\w*|рекомендуй\w*|запропонуй\w*|підкажи\w*|підбер\w*|знайд\w*|скажи|скинь|просто|зовсім|взагалі|нібито|будь-що|будь-який|будь-яке|будь-яка|товар\w*|річ|речі|продукт\w*|вибер\w*|давай|дай|тепер|тепеp|зараз|розвиваюч\w*|розвивальн\w*|розвиваюч|розвиваючі|навчальн\w*|цікав\w*|гарн\w*|хорош\w*|корисн\w*|кращ\w*|крут\w*)\b/ui', '', $productQuery);
        // Remove standalone Ukrainian letters (e.g. "ь" left from split "якийс ь")
        $productQuery = preg_replace('/(?<=\s|^)[а-яіїєґь](?=\s|$)/ui', '', $productQuery);
        $productQuery = preg_replace('/\s{2,}/u', ' ', trim($productQuery));

        // Normalize Ukrainian seasonal word forms to nominative case for better Meili matching
        // "весну/весною/весни" → "весна", "зиму/зимою/зими" → "зима" etc.
        $seasonNormalization = [
            '/\bвесн[уіиою]\b/ui' => 'весна',
            '/\bзим[уіиою]\b/ui' => 'зима',
            '/\bліт[уоаі]\b/ui' => 'літо',
            '/\bосен[іию]\b/ui' => 'осінь',
            '/\bосінн[юія]\b/ui' => 'осінь',
        ];
        foreach ($seasonNormalization as $pattern => $replacement) {
            $productQuery = preg_replace($pattern, $replacement, $productQuery);
        }
        $productQuery = trim($productQuery);

        Log::info('BaseAgent: age query detected, bypassing GPT', [
            'message' => $originalMessage,
            'product_query' => $productQuery,
        ]);

        // Pass the original message as _user_message so MeiliProductSearchTool
        // can detect age category and apply age filter
        $filters = [
            '_user_message' => $originalMessage,
        ];

        // Use extracted product words as search query (not empty string)
        // This ensures "пазли для 5 років" searches for "пазли" with age filter
        $products = $this->searchTool->search($productQuery, $filters, 30);

        // If specific product query returned <3 results, also fetch age-only results
        // and merge — narrow keywords ("розвиваючі") shouldn't collapse the list to 1 item.
        if ($productQuery !== '' && count($products) < 3) {
            $ageOnly = $this->searchTool->search('', $filters, 30);
            if (! empty($ageOnly)) {
                $existingIds = array_column($products, 'id');
                foreach ($ageOnly as $p) {
                    $pid = $p['id'] ?? null;
                    if ($pid !== null && ! in_array($pid, $existingIds, true)) {
                        $products[] = $p;
                    }
                }
                Log::info('BaseAgent: age-only fallback merged', [
                    'product_query' => $productQuery,
                    'narrow_count' => count($existingIds),
                    'merged_count' => count($products),
                ]);
            }
        }

        if (empty($products)) {
            if ($productQuery !== '') {
                $products = $this->searchTool->search('', $filters, 30);
            }
            if (empty($products)) {
                return null; // Fall through to GPT
            }
        }

        // Add variety: shuffle BEFORE getCards so we pick different products each time
        if (count($products) > 6) {
            $pool = array_slice($products, 0, min(count($products), 18));
            shuffle($pool);
            $products = array_merge($pool, array_slice($products, count($pool)));
        }
        $products = array_slice($products, 0, 6);

        // Get full product cards with images
        $ids = array_column($products, 'id');
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cards = $this->detailsTool->getCards($ids, 6, $tenantId);
        if (! empty($cards)) {
            $products = $cards;
        }

        // TENANT-SPECIFIC cleanup: exclude non-toy/irrelevant products for T20 baby queries.
        $products = $this->filterTenantBabyQueryProducts($products, $originalMessage, $tenantId);

        // Dedup by parent_article — show max 1 variant per parent (prevents "2 Такане різного принту").
        $products = $this->dedupByParentArticle($products);

        $products = array_slice($products, 0, 6);

        if (empty($products)) {
            return null; // Fall through to GPT when exclude filters removed everything
        }

        $intro = $productQuery !== ''
            ? "Ось що я знайшов за запитом «{$productQuery}» для цього віку:"
            : 'Ось що я знайшов для цього віку:';

        return [
            'message' => $intro,
            'products' => $products,
            'messages' => [
                ['type' => 'text', 'content' => $intro],
                ['type' => 'products', 'products' => $products],
            ],
            'meta' => [
                'intent' => 'product_search',
                'agent' => 'function_calling',
                'source' => 'age_query_handler',
            ],
        ];
    }

    /**
     * Detect reasoning/comparison queries that require GPT, not keyword search.
     *
     * Covers: "порівняй X і Y", "порадь N варіантів", "поясни чому", "який краще",
     * "підбери за критеріями", "плюси/мінуси", "в чому різниця".
     */
    protected function isReasoningQuery(string $message): bool
    {
        $lower = mb_strtolower(trim($message));

        $patterns = [
            '/\bпорівняй\b/u',
            '/\bпорівняйте\b/u',
            '/\bпорівняти\b/u',
            '/\bпорівнянн\w+\b/u',
            '/\bvs\.?\b/u',
            '/\bпротиставл/u',
            '/\bв\s+чому\s+різниц/u',
            '/\bяка\s+різниц/u',
            '/\bякий\s+(?:краще|кращ|підходит|ліпше|виб)/u',
            '/\bяка\s+(?:краще|кращ|підходит|ліпше)/u',
            '/\bщо\s+краще\b/u',
            '/\bкращий\s+(?:варіант|вибір)/u',
            '/\b(?:розкажи|поясни|поясніть)\s+(?:чому|різницю|в\s+чому)/u',
            '/\bчому\s+(?:саме|краще|варт|коштує)/u',
            '/\bплюси\s+(?:і|та)\s+мінуси\b/u',
            '/\bпереваг/u',
            '/\bнедолік/u',
            '/\bпорадь\s+(?:\d+|кілька|декілька|пару|трохи)\s+варіант/u',
            '/\bпідбер\w+\s+(?:за|по|під)\s+(?:критер|критерія|потреб|бюджет)/u',
            '/\bпоясни/u',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $lower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect explicit negative feedback like "барабан не підходить", "не треба X".
     *
     * When present, skip fast-path search (which would just re-search by keyword)
     * and let GPT re-plan with context awareness.
     */
    protected function isNegativeFeedbackQuery(string $message): bool
    {
        $lower = mb_strtolower(trim($message));

        $patterns = [
            '/\bне\s+підходит/u',
            '/\bне\s+треба\b/u',
            '/\bне\s+потрібн/u',
            '/\bне\s+хоч/u',
            '/\bне\s+той\b/u',
            '/\bне\s+те\b/u',
            '/\bне\s+такі/u',
            '/\bжод(?:ен|на|ного|ним)\s+не\s+/u',
            '/\bінш\w+\s+(?:варіант|порадь|покажи)/u',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $lower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tenant 20 (Bavkatoys) specific filter: remove non-toy/irrelevant products
     * from age-based queries.
     *
     * Excludes:
     *  - category "НАВЧАЛЬНІ ПОСІБНИКИ" (PDFs, workbooks) — not physical toys
     *  - certificates (unless query has gift intent)
     *  - parent tools ("набір по догляду", "інструмент")
     *
     * No-op for other tenants.
     *
     * @param  array<int, mixed>  $products
     * @return array<int, mixed>
     */
    protected function filterTenantBabyQueryProducts(array $products, string $originalMessage, ?int $tenantId, ?string $sessionId = null): array
    {
        if ($tenantId !== 20 || empty($products)) {
            return $products;
        }

        $lower = mb_strtolower($originalMessage);
        $giftRegex = '/\bподарун|\bдарун|\bподаруват|\bна\s+подар|\bна\s+р(?:ік|очок|очка)\b|\bна\s+день\s+народж|\bgift\b/u';
        $hasGiftIntent = (bool) preg_match($giftRegex, $lower);
        $hasPdfIntent = (bool) preg_match('/\bpdf\b|\bзошит\b|\bпосібник\b|\bкартк/u', $lower);
        $hasCareIntent = (bool) preg_match('/\bдогляд/u', $lower);
        // User explicitly asked for a certificate — then we keep certificates.
        $hasCertificateIntent = (bool) preg_match('/\bсертифікат|\bgift\s*card\b|\bподарунков(?:ий|у)\s+сертифікат/u', $lower);
        // Detect "~1 year" / toddler context. Both digit and no-digit forms are accepted.
        $hasOneYearContext = (bool) preg_match('/\b(?:на\s+)?(?:один\s+)?(?:1\s*(?:рік|рочок|р\.|річ)|рочок|годик)\b/u', $lower)
            || (bool) preg_match('/\bна\s+(?:рік|рочок|годик|рочка)\b/u', $lower)
            || (bool) preg_match('/\b1\s*-?\s*3\s*(?:рок|рік|річ)/u', $lower)
            || (bool) preg_match('/\bтодлер/u', $lower);

        // For follow-up messages ("покажи ще", "ще варіанти", "більше"), check conversation
        // history for gift intent — the user may have started with "подарунок на рік" and now
        // just says "покажи ще", but context is still gift-related.
        if ($sessionId) {
            try {
                $history = $this->loadConversationHistory($sessionId);
                foreach (array_reverse($history) as $msg) {
                    if (($msg['role'] ?? '') !== 'user') {
                        continue;
                    }
                    $histLower = mb_strtolower($msg['content'] ?? '');
                    if (! $hasGiftIntent && preg_match($giftRegex, $histLower)) {
                        $hasGiftIntent = true;
                    }
                    if (! $hasOneYearContext
                        && (preg_match('/\b(?:на\s+)?(?:один\s+)?(?:1\s*(?:рік|рочок|р\.|річ)|рочок|годик)\b/u', $histLower)
                            || preg_match('/\bна\s+(?:рік|рочок|годик|рочка)\b/u', $histLower)
                            || preg_match('/\bтодлер/u', $histLower))
                    ) {
                        $hasOneYearContext = true;
                    }
                    if ($hasGiftIntent && $hasOneYearContext) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Silently ignore — filter still works without history.
            }
        }

        // Products that are NEVER appropriate as a gift (tester feedback from т20 / Аліна):
        // - фартух / нарукавники (утилітарний одяг, не дарують)
        // - підвіски на ліжечко/тренажер (ранній вік 0-6м, не універсальний подарунок)
        // - тренажер-перекладина (габаритний, вибирають батьки, не гість)
        // - коробочка постійності (актуальна лише до ~12м, хоч і в каталозі 1-3)
        $nonGiftPatterns = [
            '/\bфартух/u',
            '/\bнарукавник/u',
            '/\bпідвіск(?:и|а|у|ою|ам)\b/u',
            '/\bтренажер-перекладин/u',
            '/\bкоробочка\s+постійност/u',
        ];

        $filtered = [];
        foreach ($products as $product) {
            $title = (string) ($this->getProductField($product, 'title') ?? '');
            $categoryPath = (string) ($this->getProductField($product, 'category_path') ?? '');

            $titleLower = mb_strtolower($title);
            $catLower = mb_strtolower($categoryPath);

            // Exclude PDFs/workbooks from age/baby queries unless explicitly requested.
            if (! $hasPdfIntent) {
                if (str_contains($catLower, 'навчальні посібники') || str_contains($titleLower, 'pdf') || str_contains($titleLower, 'зошит')) {
                    continue;
                }
            }

            // Exclude certificates / gift packaging UNLESS the user explicitly asks for a certificate.
            // Previously this only excluded when no gift intent, which caused certificates to leak
            // into "подарунковий набір для малюка" queries (user wanted an actual kit, not a card).
            if (! $hasCertificateIntent) {
                if (str_contains($titleLower, 'сертифікат') || str_contains($titleLower, 'gift card')) {
                    continue;
                }
            }

            // Exclude empty gift packaging (bags/boxes) when no gift intent — these are not
            // standalone presents, only add-ons at checkout.
            if (! $hasGiftIntent) {
                if (str_contains($titleLower, 'подарунковий пакет') || str_contains($titleLower, 'подарункова упаковка')) {
                    continue;
                }
            }

            // Exclude newborn-only products when the user clearly asks for a ~1-year gift.
            // Titles like "Рання Пташка" / "новонародж" / "0-6 міс" belong to МАЛЮКАМ 0-1 and
            // are inappropriate for a toddler birthday gift.
            if ($hasOneYearContext && $hasGiftIntent) {
                if (preg_match('/\bранн[яьоiі]\s+пташ|\bновонародж|\bнемовлят|\b0\s*[\x{2013}\-]\s*6\s*міс|\bдо\s*6\s*місяц|\b0\s*[\x{2013}\-]\s*1\s*(?:рік|року)/u', $titleLower)) {
                    continue;
                }
                // Category-path level: "малюкам 0 – 1" is newborn-only.
                if (preg_match('/малюкам\s+0\s*[\x{2013}\-]\s*1/u', $catLower)) {
                    continue;
                }
            }

            // Exclude parent tools/care kits unless explicitly requested.
            if (! $hasCareIntent) {
                if (preg_match('/\bнабір\s+(?:по\s+)?догляду\b|\bдля\s+догляду\s+за\b|\bінструмент(?:и|ів)?\s+для\s+батьк/u', $titleLower)) {
                    continue;
                }
            }

            // GIFT-CONTEXT: exclude non-gift products (tester feedback).
            if ($hasGiftIntent) {
                $skip = false;
                foreach ($nonGiftPatterns as $pattern) {
                    if (preg_match($pattern, $titleLower)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
            }

            $filtered[] = $product;
        }

        return $filtered;
    }

    /**
     * Deduplicate products by parent_article / parent-article-like fields.
     *
     * Keeps first occurrence per parent. If a product has no parent_article,
     * falls back to the product's own article.
     *
     * @param  array<int, mixed>  $products
     * @return array<int, mixed>
     */
    protected function dedupByParentArticle(array $products): array
    {
        $seen = [];
        $result = [];

        foreach ($products as $product) {
            $parent = $this->getProductField($product, 'parent_article');
            $article = $this->getProductField($product, 'article');
            $key = $parent ?: ($article ?: spl_object_hash((object) $product));
            $key = (string) $key;

            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $product;
        }

        return $result;
    }

    /**
     * Read a field from a product regardless of whether it's array or object.
     */
    protected function getProductField(mixed $product, string $field): mixed
    {
        if (is_array($product)) {
            return $product[$field] ?? null;
        }
        if (is_object($product)) {
            return $product->{$field} ?? null;
        }

        return null;
    }

    /**
     * Handle single-word queries that likely represent product types.
     * Universal approach - works for any language without patterns.
     * Multi-word queries go to GPT for proper context understanding.
     */
    protected function handleShortProductQuery(string $message): ?array
    {
        $lower = mb_strtolower(trim($message));
        $words = preg_split('/\s+/u', $lower);
        $wordCount = count($words);

        // Handle 1-2 word queries that contain category keywords
        // This is universal for any language without needing patterns
        if ($wordCount > 2) {
            return null;
        }

        // Skip very short words (likely typos or particles)
        if (mb_strlen($lower) < 3) {
            return null;
        }

        // For 2-word queries, check if it contains a known category
        // This allows "білизна жіноча", "куртка зимова" etc.
        $searchQuery = $message; // Default to full message
        if ($wordCount === 2) {
            $categories = [
                'куртк' => 'куртки',
                'берц' => 'берці',
                'штан' => 'штани',
                'футболк' => 'футболки',
                'шолом' => 'шоломи',
                'навушник' => 'навушники',
                'плитонос' => 'плитоноски',
                'рюкзак' => 'рюкзаки',
                'підсум' => 'підсумки',
                'термобіл' => 'термобілизна',
                'білизн' => 'термобілизна',
                'шевр' => 'шеврони',
                'бронежилет' => 'бронежилети',
                'бронеплат' => 'бронеплати',
                'рукавиц' => 'рукавиці',
                'балаклав' => 'балаклави',
                'окуляр' => 'окуляри',
                'ремен' => 'ремені',
                'пояс' => 'пояси',
                'панам' => 'панами',
                'шапк' => 'шапки',
                'кепк' => 'кепки',
            ];

            $foundCategory = null;
            foreach ($categories as $cat => $canonical) {
                if (mb_stripos($lower, $cat) !== false) {
                    $foundCategory = $canonical;
                    break;
                }
            }

            // If no category found in 2-word query, let GPT handle it
            if (! $foundCategory) {
                return null;
            }

            // Check if the non-category word is a color modifier
            $colorStems = [
                'рожев' => 'Рожевий', 'чорн' => 'Чорний', 'олив' => 'Олива',
                'біл' => 'Білий', 'сір' => 'Сірий', 'зелен' => 'Зелений',
                'синь' => 'Синій', 'синю' => 'Синій', 'синя' => 'Синій',
                'коричнев' => 'Коричневий', 'койот' => 'Койот',
                'мультикам' => 'Мультикам', 'піксель' => 'Піксель',
                'хакі' => 'Хакі', 'бордов' => 'Бордовий',
                'оранжев' => 'Оранжевий', 'жовт' => 'Жовтий',
                'фіолетов' => 'Фіолетовий', 'бежев' => 'Бежевий',
                'червон' => 'Червоний', 'блакитн' => 'Блакитний',
            ];

            $detectedColor = null;
            foreach ($words as $word) {
                foreach ($colorStems as $stem => $canonicalColor) {
                    if (mb_stripos($word, $stem) !== false) {
                        $detectedColor = $canonicalColor;
                        break 2;
                    }
                }
            }

            if ($detectedColor) {
                // Color + category: use full message for search (Meili handles "рожева футболка" well)
                $searchQuery = $message;
            } else {
                // No color: use canonical category for search
                // This ensures "білизна жіноча" searches for "термобілизна"
                $searchQuery = $foundCategory;
            }
        }

        // If it's a single noun-like query, try searching directly
        // This handles cases like "шоломи", "helmets", "підсумки", "берці" etc.
        PipelineTracer::current()?->step('agent.short_query_handler', [
            'handler' => 'handleShortProductQuery',
            'message' => $message,
            'search_query' => $searchQuery,
            'word_count' => $wordCount,
        ]);

        Log::info('BaseAgent::handleShortProductQuery attempting search', [
            'message' => $message,
            'search_query' => $searchQuery,
            'shown_ids_count' => count($this->shownProductIds),
        ]);

        // Build filters (including color if detected)
        $searchFilters = [];
        if (! empty($detectedColor)) {
            $searchFilters['color'] = $detectedColor;
        }

        // Request more products to allow excluding shown ones
        $requestLimit = 3 + count($this->shownProductIds);
        $products = $this->searchTool->search($searchQuery, $searchFilters, $requestLimit);

        // If 0 results for 1-word query, try stripping Ukrainian plural endings
        // "сортери" → "сортер", "конструктори" → "конструктор", "пазли" → "пазл"
        if (empty($products) && $wordCount === 1) {
            $singular = $this->stripUkrainianPlural($searchQuery);
            if ($singular !== mb_strtolower($searchQuery)) {
                $products = $this->searchTool->search($singular, $searchFilters, $requestLimit);
                if (! empty($products)) {
                    Log::info('BaseAgent: singular fallback worked', [
                        'original' => $searchQuery,
                        'singular' => $singular,
                        'results' => count($products),
                    ]);
                }
            }
        }

        // If color filter returned no results, retry without filter and post-filter instead
        if (empty($products) && ! empty($detectedColor)) {
            $products = $this->searchTool->search($searchQuery, [], $requestLimit);
            if (! empty($products)) {
                $colorLower = mb_strtolower($detectedColor);
                $products = array_values(array_filter($products, function ($p) use ($colorLower) {
                    $productColor = mb_strtolower(($p['color'] ?? '').' '.($p['title'] ?? ''));

                    return str_contains($productColor, $colorLower);
                }));
            }
        }

        Log::info('BaseAgent::handleShortProductQuery raw search results', [
            'message' => $message,
            'raw_count' => count($products),
            'raw_ids' => array_column(array_slice($products, 0, 10), 'id'),
        ]);

        // Exclude already shown products for variety
        if (! empty($this->shownProductIds) && ! empty($products)) {
            $beforeCount = count($products);
            $products = array_filter($products, fn ($p) => ! in_array((int) ($p['id'] ?? 0), $this->shownProductIds));
            $products = array_values($products);

            Log::info('BaseAgent::handleShortProductQuery excluded shown', [
                'before' => $beforeCount,
                'after' => count($products),
                'shown_ids' => array_slice($this->shownProductIds, 0, 5),
            ]);
        }

        // Limit to 3
        $products = array_slice($products, 0, 3);

        if (! empty($products)) {
            // Get full product cards with images (same as toolSearchProducts)
            $ids = array_column($products, 'id');
            $tenantId = $this->searchTool->getCurrentTenantId();
            $cards = $this->detailsTool->getCards($ids, 3, $tenantId);
            if (! empty($cards)) {
                $products = $cards;
            }

            // Determine language for response
            $isEnglish = (bool) preg_match('/[a-zA-Z]{3,}/', $message);
            $intro = $this->generateContextualIntro($message, $products, $isEnglish);

            Log::info('BaseAgent: short query direct search succeeded', [
                'message' => $message,
                'products_found' => count($products),
            ]);

            return [
                'message' => $intro,
                'products' => $products,
                'messages' => [
                    ['type' => 'text', 'content' => $intro],
                    ['type' => 'products', 'products' => $products],
                ],
                'meta' => [
                    'intent' => 'product_search',
                    'agent' => 'function_calling',
                    'source' => 'short_query_handler',
                ],
            ];
        }

        return null;
    }

    // ============================================================
    // FOLLOW-UP QUESTION HANDLER
    // ============================================================

    /**
     * Handle follow-up questions about previously shown products.
     * These questions should NOT trigger search, but answer from context.
     *
     * Examples: "це оригінал?", "а знижки є?", "які розміри?"
     */
    protected function handleFollowUpQuestion(string $message, ?string $sessionId): ?array
    {
        $lower = mb_strtolower(trim($message));

        // Follow-up patterns that should NOT trigger search
        $followUpPatterns = [
            'original' => '/^(це|а це|воно?|він|вона)?\s*(оригінал|оригінальн|не підробка|справжн)/ui',
            'discount' => '/^(а\s+)?(знижк|скидк|discount|sale|акці|дешевш)/ui',
            'sizes' => '/^(які|а які|які є|є ще|які ще)\s*(розмір|размер|size)/ui',
            'material' => '/^(з\s+якого|який)\s*(матеріал|тканин)/ui',
            'included' => '/^(що\s+входить|що\s+в\s+комплект|комплектац)/ui',
            'warranty' => '/^(гарант|warranty)/ui',
            'delivery' => '/^(доставк|delivery|як\s+отримати)/ui',
        ];

        $matchedType = null;
        foreach ($followUpPatterns as $type => $pattern) {
            if (preg_match($pattern, $lower)) {
                $matchedType = $type;
                break;
            }
        }

        if (! $matchedType) {
            return null;
        }

        Log::info('BaseAgent: detected follow-up question', [
            'message' => $message,
            'type' => $matchedType,
            'session_id' => $sessionId,
        ]);

        // Load last shown product from session
        $lastProduct = $this->loadLastShownProduct($sessionId);

        if (! $lastProduct) {
            Log::info('BaseAgent: no product context for follow-up', ['session_id' => $sessionId]);

            return null; // Let GPT handle - maybe it has context
        }

        // Generate response based on question type
        $response = $this->generateFollowUpResponse($matchedType, $lastProduct);

        if (! $response) {
            return null;
        }

        return [
            'message' => $response,
            'products' => [],
            'messages' => [['type' => 'text', 'content' => $response]],
            'meta' => [
                'intent' => 'follow_up',
                'agent' => 'function_calling',
                'source' => 'follow_up_handler',
                'follow_up_type' => $matchedType,
                'product_article' => $lastProduct['article'] ?? null,
            ],
        ];
    }

    /**
     * Load last shown product details from session history.
     */
    protected function loadLastShownProduct(?string $sessionId): ?array
    {
        if (! $sessionId) {
            return null;
        }

        try {
            $session = \App\Models\ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('session_id', $sessionId)
                ->first();

            if (! $session) {
                return null;
            }

            // Get last assistant message with non-empty products array
            // Note: whereNotNull doesn't work for empty arrays, so we filter in PHP
            $lastMessage = \App\Models\ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('chat_session_id', $session->id)
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->limit(10) // Check last 10 messages
                ->get()
                ->first(function ($msg) {
                    $products = $msg->meta['products'] ?? [];

                    return ! empty($products);
                });

            if (! $lastMessage) {
                return null;
            }

            $meta = $lastMessage->meta ?? [];
            $products = $meta['products'] ?? [];

            if (empty($products)) {
                return null;
            }

            // Get the first product shown
            $productInfo = $products[0];
            $productId = $productInfo['id'] ?? null;

            if (! $productId) {
                return $productInfo; // Return basic info if no ID
            }

            // Load full product from DB for details
            $product = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->find($productId);

            if (! $product) {
                return $productInfo;
            }

            // Combine API info with DB details
            return [
                'id' => $product->id,
                'title' => $product->title ?? $productInfo['title'] ?? '',
                'article' => $product->article ?? $productInfo['article'] ?? '',
                'brand' => $product->brand ?? null,
                'price' => $product->price ?? $productInfo['price'] ?? '',
                'price_old' => $product->price_old ?? null,
                'description' => $this->extractDescription($product),
                'attributes' => $this->extractAttributes($product),
                'sizes' => $this->extractSizes($product),
                'in_stock' => $product->in_stock ?? true,
            ];
        } catch (\Throwable $e) {
            Log::error('BaseAgent: failed to load last product', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Extract product description from raw field.
     */
    protected function extractDescription(\App\Models\Product $product): string
    {
        $raw = $product->raw ?? [];

        return $raw['description'] ?? $raw['full_description'] ?? '';
    }

    /**
     * Extract product attributes/characteristics from raw field.
     */
    protected function extractAttributes(\App\Models\Product $product): array
    {
        $raw = $product->raw ?? [];

        return $raw['characteristics'] ?? $raw['attributes'] ?? [];
    }

    /**
     * Extract available sizes from product.
     */
    protected function extractSizes(\App\Models\Product $product): array
    {
        $raw = $product->raw ?? [];

        // Try different possible formats
        if (! empty($raw['variants'])) {
            return array_unique(array_filter(array_column($raw['variants'], 'size')));
        }

        if (! empty($raw['sizes'])) {
            return $raw['sizes'];
        }

        // Check if product itself has size
        if (! empty($product->size) && $product->size !== '-') {
            return [$product->size];
        }

        return [];
    }

    /**
     * Generate response for follow-up question based on product data.
     */
    protected function generateFollowUpResponse(string $type, array $product): ?string
    {
        $title = $product['title'] ?? 'цей товар';
        $brand = $product['brand'] ?? null;
        $price = $product['price'] ?? null;
        $priceOld = $product['price_old'] ?? null;
        $sizes = $product['sizes'] ?? [];
        $description = $product['description'] ?? '';
        $attributes = $product['attributes'] ?? [];

        switch ($type) {
            case 'original':
                if ($brand) {
                    // Known original brands
                    $originalBrands = ['CAT', 'NAR', 'Mechanix', '5.11', 'Oakley', 'Crye', 'Magpul', 'QuikClot', 'HyFin', 'ECWCS'];
                    $isLikelyOriginal = false;
                    foreach ($originalBrands as $origBrand) {
                        if (stripos($brand, $origBrand) !== false || stripos($title, $origBrand) !== false) {
                            $isLikelyOriginal = true;
                            break;
                        }
                    }

                    if ($isLikelyOriginal) {
                        return "Так, {$title} є оригінальним продуктом бренду {$brand}.";
                    }

                    return "Це товар бренду {$brand}. Якщо потрібно уточнити оригінальність — зателефонуйте: {$this->getShopPhone()}";
                }

                return "Щодо оригінальності товару — зверніться до менеджера: {$this->getShopPhone()}";

            case 'discount':
                if ($priceOld && floatval($priceOld) > floatval($price)) {
                    $discount = round((floatval($priceOld) - floatval($price)) / floatval($priceOld) * 100);

                    return "Так, на цей товар діє знижка {$discount}%! Стара ціна: {$priceOld} грн, нова: {$price} грн.";
                }

                return "На даний момент знижок на цей товар немає. Ціна: {$price} грн.";

            case 'sizes':
                if (! empty($sizes)) {
                    $sizeList = implode(', ', $sizes);

                    return "Є такі розміри: {$sizeList}. Який вам потрібен?";
                }

                return "Щодо наявних розмірів — уточніть у менеджера: {$this->getShopPhone()}";

            case 'material':
                // Try to find material in description or attributes
                foreach ($attributes as $key => $value) {
                    $keyLower = mb_strtolower($key);
                    if (strpos($keyLower, 'матеріал') !== false || strpos($keyLower, 'тканин') !== false) {
                        return "Матеріал: {$value}";
                    }
                }
                // Search in description
                if (preg_match('/матеріал[:\s]+([^.]+)/ui', $description, $m)) {
                    return "Матеріал: {$m[1]}";
                }

                return "Детальну інформацію про матеріал можна уточнити на сайті або зателефонувати: {$this->getShopPhone()}";

            case 'included':
                if (preg_match('/комплект[:\s]+([^.]+)/ui', $description, $m)) {
                    return "В комплект входить: {$m[1]}";
                }
                if (preg_match('/включа[єе][:\s]+([^.]+)/ui', $description, $m)) {
                    return "В комплект входить: {$m[1]}";
                }

                return "Детальну інформацію про комплектацію дивіться на сайті або зверніться до менеджера: {$this->getShopPhone()}";

            case 'warranty':
                return "Інформацію про гарантію уточнюйте у менеджера: {$this->getShopPhone()}";

            case 'delivery':
                return "Доставка здійснюється Новою Поштою. Для уточнення деталей зверніться: {$this->getShopPhone()}";

            default:
                return null;
        }
    }

    // ============================================================
    // CONTEXTUAL INTRO GENERATOR
    // ============================================================

    /**
     * Strip common Ukrainian plural endings for search retry.
     * "сортери" → "сортер", "конструктори" → "конструктор", "пазли" → "пазл"
     */
    protected function stripUkrainianPlural(string $word): string
    {
        $lower = mb_strtolower(trim($word));

        // Common Ukrainian plural endings (longest first)
        $plurals = ['ери', 'ори', 'ари', 'ики', 'очі', 'ачі', 'ини', 'они', 'алі', 'олі', 'лі', 'ки', 'ці', 'ні', 'ті', 'зі', 'рі', 'ди', 'ги', 'си', 'зи', 'и', 'і'];

        foreach ($plurals as $ending) {
            if (mb_substr($lower, -mb_strlen($ending)) === $ending && mb_strlen($lower) > mb_strlen($ending) + 2) {
                return mb_substr($lower, 0, -mb_strlen($ending));
            }
        }

        return $lower;
    }

    /**
     * Generate contextual intro based on user message instead of generic "Ось що я знайшов".
     */
    protected function generateContextualIntro(string $message, array $products = [], bool $isEnglish = false): string
    {
        $lowerMsg = mb_strtolower(trim($message));

        // Follow-up patterns
        if (preg_match('/^(а є |є )?дешевш/ui', $lowerMsg)) {
            return $isEnglish ? 'Here are cheaper options:' : 'Ось дешевші варіанти:';
        }
        if (preg_match('/^(а є |є )?дорожч/ui', $lowerMsg)) {
            return $isEnglish ? 'Here are premium options:' : 'Ось преміум варіанти:';
        }
        if (preg_match('/покажи ще|ще варіант|інші|more|ещё/ui', $lowerMsg)) {
            return $isEnglish ? 'Here are more options:' : 'Ось ще варіанти:';
        }
        if (preg_match('/новинк|нов[іе] надходження|що нового/ui', $lowerMsg)) {
            return $isEnglish ? 'Here are the latest arrivals:' : 'Ось новинки:';
        }

        // Category patterns — check BEFORE colors to avoid false positives.
        // NOTE: values are substring matches, so ORDER matters and narrow patterns
        //       must come before broad ones (e.g. "плитонос"/"плитноск" BEFORE "носк",
        //       otherwise "плитНОСКа" would falsely match as "шкарпетки").
        $categoryPatterns = [
            // Plate carriers — MUST be before "носк" (socks) to avoid "плитНОСКа" collision
            'плитонос' => 'плитоноски', 'плитноск' => 'плитоноски', 'плейткер' => 'плитоноски',
            'куртк' => 'куртки', 'берц' => 'берці', 'штан' => 'штани',
            'футболк' => 'футболки', 'навушник' => 'навушники', 'шолом' => 'шоломи',
            'рюкзак' => 'рюкзаки', 'підсум' => 'підсумки',
            'термобіл' => 'термобілизна', 'білизн' => 'термобілизна',
            'шевр' => 'шеврони', 'бронежилет' => 'бронежилети',
            'тактич' => 'тактичне спорядження', 'черевик' => 'черевики',
            'кросівк' => 'кросівки', 'сорочк' => 'сорочки', 'шапк' => 'шапки',
            'рукавиц' => 'рукавиці', 'ніж' => 'ножі', 'ліхтар' => 'ліхтарі',
            'окуляр' => 'окуляри', 'ремен' => 'ремені',
            'шкарпетк' => 'шкарпетки', 'носк' => 'шкарпетки',
            'меблі' => 'меблі', 'монтессор' => 'Монтессорі іграшки',
            'іграшк' => 'іграшки', 'конструктор' => 'конструктори',
            'пазл' => 'пазли', 'книг' => 'книги', 'розвива' => 'розвивальні іграшки',
        ];

        foreach ($categoryPatterns as $pattern => $category) {
            if (mb_stripos($lowerMsg, $pattern) !== false) {
                return $isEnglish ? "Here are {$category}:" : "Ось {$category}:";
            }
        }

        // Color filter
        $colors = [
            'олив' => 'оливі', 'чорн' => 'чорному', 'біл' => 'білому',
            'мультикам' => 'мультикамі', 'піксель' => 'пікселі', 'коричнев' => 'коричневому',
            'койот' => 'койоті', 'рожев' => 'рожевому', 'синь' => 'синьому', 'синя' => 'синьому',
            'сір' => 'сірому', 'зелен' => 'зеленому', 'червон' => 'червоному',
            'бежев' => 'бежевому', 'оранжев' => 'оранжевому', 'жовт' => 'жовтому',
            'фіолетов' => 'фіолетовому', 'бордов' => 'бордовому', 'блакитн' => 'блакитному',
        ];
        foreach ($colors as $pattern => $colorName) {
            if (mb_stripos($lowerMsg, $pattern) !== false) {
                return $isEnglish ? "Here are options in {$pattern}:" : "Ось варіанти в {$colorName}:";
            }
        }

        // Try to extract category from first product
        if (! empty($products[0]['category_path'])) {
            $parts = explode(' > ', $products[0]['category_path']);
            $lastCategory = end($parts);
            if ($lastCategory) {
                return $isEnglish ? "Here are {$lastCategory}:" : "Ось {$lastCategory}:";
            }
        }

        // Smart fallback: use the user message as context (capitalize first word)
        $words = preg_split('/\s+/u', trim($message));
        if (count($words) <= 3) {
            $cleaned = mb_strtolower(trim($message));
            $cleaned = preg_replace('/[?.!,]+$/u', '', $cleaned);
            if (mb_strlen($cleaned) > 0 && mb_strlen($cleaned) <= 30) {
                $first = mb_strtoupper(mb_substr($cleaned, 0, 1)).mb_substr($cleaned, 1);

                return $isEnglish ? "Here are {$cleaned}:" : "Ось {$first}:";
            }
        }

        // Ultimate fallback — still better than generic
        return $isEnglish ? 'Here are the results:' : 'Ось товари:';
    }

    // ============================================================
    // SYSTEM PROMPT
    // ============================================================

    /**
     * Get system prompt - uses modular approach for optimized token usage.
     *
     * Strategy:
     * 1. Check for custom PromptPreset (manual override)
     * 2. Use modular prompt builder (context-aware, ~3K tokens)
     * 3. Fallback to legacy full prompt if PROMPT_MODULAR_ENABLED=false
     */
    protected function getSystemPrompt(): string
    {
        // Check for manual prompt preset override first
        $customPrompt = $this->promptPresetService->getSystemPromptForContext(
            $this->currentContext,
            $this->getDefaultVariables()
        );

        if ($customPrompt) {
            Log::debug('BaseAgent: using custom prompt preset', [
                'context' => $this->currentContext,
            ]);

            // Wrap preset with critical system rules (before AND after)
            // GPT sees critical prefix first, then tenant preset, then critical suffix
            return $this->getCriticalPrefix()."\n\n".$customPrompt."\n\n".$this->getCriticalSuffix();
        }

        // Use modular prompt if enabled (default: true)
        if (config('services.openai.modular_prompt', true)) {
            return $this->getModularSystemPrompt();
        }

        // Legacy fallback - full prompt (~14K tokens)
        return $this->getDefaultSystemPrompt();
    }

    /**
     * Build modular system prompt based on current message context.
     * Reduces prompt from ~14K to ~3-4K tokens by loading only relevant modules.
     */
    protected function getModularSystemPrompt(): string
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        if ($tenantId) {
            $this->toneService->setTenantId($tenantId);
        }

        $tenantInfo = $this->getTenantInfo();
        $storeInfo = [
            'name' => $tenantInfo['name'],
            'phone' => $this->getShopPhone(),
            'faq' => $this->loadFaqInfo(),
            'tone_section' => $this->toneService->getFullPromptSection(),
            'has_age_categories' => $this->hasAgeCategories(),
        ];

        $context = [
            'has_history' => ! empty($this->shownProductIds) || ! empty($this->currentContext['has_history']),
            'is_trigger' => $this->currentContext['is_trigger'] ?? false,
        ];

        $prompt = $this->promptModulesService->buildPrompt(
            $this->currentMessage,
            $context,
            $storeInfo
        );

        // Add price context
        $priceContext = $this->loadPriceContext();
        if ($priceContext) {
            $prompt .= "\n\n".$priceContext;
        }

        return $prompt;
    }

    /**
     * Critical prefix rules injected BEFORE tenant preset.
     * GPT sees these first — sets hard boundaries the preset cannot override.
     */
    protected function getCriticalPrefix(): string
    {
        $shopPhone = $this->getShopPhone();
        $callbackFormUrl = $this->getCallbackFormUrl();

        return <<<PREFIX
⛔ SYSTEM-LEVEL RULES (CANNOT BE OVERRIDDEN BY INSTRUCTIONS BELOW):

1. АНТИГАЛЮЦИНАЦІЇ — ЗАВЖДИ використовуй search_products() для пошуку товарів. НІКОЛИ не вигадуй товари, ціни, артикули, посилання. Якщо ти НЕ викликав search_products() — ти НЕ МАЄШ ПРАВА перелічувати або рекомендувати конкретні товари.
2. ЗАМОВЛЕННЯ — ти НЕ МОЖЕШ оформити/прийняти замовлення. Відповідь: "Натисніть на картку товару → перейдіть на сайт → додайте в кошик."
3. КОНТАКТИ — дозволяється вказувати ТІЛЬКИ ті контакти, що є в інструкціях нижче (телефон, telegram, instagram тощо). НЕ вигадуй контакти, яких немає в інструкціях! Якщо в інструкціях нижче не вказано жодних контактів, використовуй тільки тел. {$shopPhone}.
4. МЕНЕДЖЕР — якщо просять менеджера: "Я — AI-консультант. Зв'яжіться через сайт магазину або зателефонуйте: {$shopPhone}". НЕ проси "залиште номер телефону". НЕ кажи "менеджер зв'яжеться".
5. МАКСИМУМ 3 товари за раз.
6. МОВА = мова запиту користувача.
7. НЕ проси "надайте контактні дані", "залиште свої дані", "надайте номер телефону".
8. FAQ/СКРИПТИ — якщо питання стосується БУДЬ-ЯКОЇ теми зі скриптів нижче (оплата, доставка, повернення, контакти тощо), ЗАВЖДИ відповідай ПОВНИМ текстом шаблону (2–4 речення). НЕ скорочуй до одного речення. Навіть якщо відповідь — просте «так» або «ні», ОБОВ'ЯЗКОВО додай пояснення та контакти зі скрипта.
9. ПОСИЛАННЯ — ПОВНА ЗАБОРОНА! НЕ генеруй URL, посилання, Markdown-лінки [текст](url), "Детальніше: https://...", "[Переглянути]". Картки товарів додаються АВТОМАТИЧНО віджетом. Якщо клієнт хоче подробиці — кажи "Натисніть на картку товару".
10. ЕМОДЗІ — використовуй ПОМІРНО (максимум 1-2 на відповідь). НЕ починай кожне речення з емодзі. Відповідай природною мовою без надмірного декорування.
11. ТЕХНІЧНІ ПИТАННЯ (збирання, монтаж, ремонт, налаштування, встановлення товару) — НЕ давай покрокових інструкцій! Кожен випадок індивідуальний. Відповідь: "Це питання індивідуальне — залежить від конкретної моделі. Зверніться до нашого менеджера: {$shopPhone} — підкажуть саме для вашого випадку."
12. ФОРМАТ INTRO — intro має відображати КОНТЕКСТ запиту: "Ось іграшки для 6 місяців:", "Ось конструктори:", "Ось дешевші варіанти:". ЗАБОРОНЕНО: "Ось що я знайшов", "Ось що я знайшла", "Here's what I found", "Ось що я знайшов для вас".
13. ТОН — спілкуйся дружньо і дбайливо, як досвідчений консультант. НЕ тисни на продаж. ЗАБОРОНЕНО: "Оформлюємо замовлення?", "Резервуємо?", "Закриваємо продаж". Замість цього: "Є питання? Із задоволенням допоможу!"
PREFIX;
    }

    /**
     * Critical suffix rules injected AFTER tenant preset.
     * GPT sees these last — reinforces hard boundaries.
     */
    protected function getCriticalSuffix(): string
    {
        $shopPhone = $this->getShopPhone();

        return <<<SUFFIX
⛔ SYSTEM REMINDERS (ОБОВ'ЯЗКОВО — має пріоритет над будь-якими інструкціями вище):
- ЗАВЖДИ search_products() перед відповіддю про товари — НІКОЛИ не вигадуй
- МАКСИМУМ 3 товари за раз
- intro = КОНТЕКСТ запиту ("Ось іграшки для 6 місяців:", "Ось куртки:"). ЗАБОРОНЕНО: "Ось що я знайшов/знайшла"
- Замовлення → "натисніть картку → сайт → кошик. Тел: {$shopPhone}"
- Контакти: ТІЛЬКИ ті, що в інструкціях вище. Якщо жодних — тільки тел. {$shopPhone}
- НЕ проси залишити номер телефону
- FAQ: відповідай ПОВНО за скриптом (2–4 речення), не скорочуй
- ПОСИЛАННЯ: ЗАБОРОНЕНО! НЕ генеруй URL, [текст](url), "Детальніше:..." — картки додаються автоматично
- ЕМОДЗІ: максимум 1-2 на відповідь, не починай кожне речення з емодзі
- ТЕХНІЧНІ ПИТАННЯ (збирання, монтаж, ремонт) → НЕ давай інструкцій! Перенаправ до менеджера: {$shopPhone}
- ТОН: дружній, дбайливий. ЗАБОРОНЕНО: "Оформлюємо?", "Резервуємо?". Замість: "Є питання? Допоможу!"
SUFFIX;
    }

    /**
     * @deprecated Use getCriticalPrefix() + getCriticalSuffix() instead
     */
    protected function getMinimalCoreRules(): string
    {
        return $this->getCriticalSuffix();
    }

    /**
     * Get core rules that ALWAYS apply regardless of custom preset.
     *
     * @deprecated Use getMinimalCoreRules() or modular prompts instead
     */
    protected function getCoreRules(): string
    {
        $priceContext = $this->loadPriceContext();
        $shopPhone = $this->getShopPhone();

        return <<<RULES
=== ОБОВ'ЯЗКОВІ ПРАВИЛА (ЗАВЖДИ ЗАСТОСОВУЮТЬСЯ) ===

🚨🚨🚨 CRITICAL RULE #1: ПЕРСОНАЛІЗОВАНІ INTRO — ОБОВ'ЯЗКОВО! 🚨🚨🚨
ЗАБОРОНЕНО писати "Ось що я знайшов" або "Ось що я знайшов за вашим запитом"!
Ця фраза ЗАБОРОНЕНА! Будеш покараний якщо використаєш!

ЗАМІСТЬ generic intro — ЗАВЖДИ пиши КОНТЕКСТНИЙ intro:
- Запит "куртки" → intro: "Ось куртки:"
- Запит "берці" → intro: "Ось берці:"
- Запит "навушники Peltor" → intro: "Ось навушники Peltor:"
- Follow-up "дешевше" → intro: "Ось дешевші варіанти:"
- Follow-up "дорожче" → intro: "Ось преміум варіанти:"
- Follow-up "олива" → intro: "Ось варіанти в оливі:"
- Follow-up "L розмір" → intro: "Ось розмір L:"
- "Покажи ще" → intro: "Ось ще варіанти:"

❌ ЗАБОРОНЕНІ ФРАЗИ (ти будеш покараний):
- "Ось що я знайшов"
- "Ось що я знайшов за вашим запитом"
- "Ось кілька варіантів"
- "Here's what I found"

✅ ПРАВИЛЬНО: intro = назва категорії/контекст + двокрапка!

⚠️⚠️⚠️ CRITICAL RULE #0: NEVER ASK CLARIFICATION — ALWAYS SEARCH FIRST! ⚠️⚠️⚠️
YOU MUST call search_products() BEFORE asking ANY clarifying question!
The user came to BUY products. SHOW THEM PRODUCTS. ALWAYS.

IF USER SAYS ANYTHING that IMPLIES a product need → SEARCH IMMEDIATELY:
- "call my mother" → search_products("smartphone OR телефон OR mobile phone")
- "make calls" → search_products("smartphone OR phone")  
- "gift for mom" → search_products("gift OR подарунок")
- "something to write" → search_products("pen OR ручка")
- "head protection" → search_products("helmet OR шолом")
- "stay warm" → search_products("jacket OR куртка")
- "для малюка" → search_products("іграшки", category: "МАЛЮКАМ 0 – 1")
- "для тодлера" → search_products("іграшки", category: "ТОДЛЕРАМ 1 – 3")
- "для дошкільняти" → search_products("іграшки", category: "ДОШКІЛЬНЯТАМ 3 – 7")
- "термуха", "термо білизна", "термобілизна" → search_products("термобілизна OR термо OR Level 1 OR Level 2")
- "жіноча термуха" → search_products("термобілизна жіноча OR термо жіноча")
- "чоловіча термобілизна" → search_products("термобілизна чоловіча OR термо OR Level 1")

⚠️ ПЕРЕВІРКА АТРИБУТІВ У РЕЗУЛЬТАТАХ — КРИТИЧНО!
Якщо користувач запитує конкретний атрибут (жіноча/чоловіча/дитяча/певний колір):
1. Шукай з цим атрибутом: search_products("термобілизна жіноча")
2. ПЕРЕВІР результати: чи є слово "жіноча" або "women" в назві товару?
3. Якщо в назвах товарів НЕМАЄ слова "жіноча"/"women" — це НЕ жіночі товари!
4. Чесно скажи: "На жаль, жіночої термобілизни немає в асортименті. Є універсальна:" + покажи звичайні товари

🚨 ЗАБОРОНЕНО (ти будеш покараний):
❌ "Ось жіноча термобілизна" якщо в назвах немає "жіноча/women"
❌ Показувати звичайну термобілизну як "жіночу" 
❌ Ігнорувати атрибут користувача

✅ ПРАВИЛЬНО:
"На жаль, спеціально жіночої термобілизни в асортименті немає. Є універсальна термобілизна, яка підходить всім:"

BANNED RESPONSES (you will be penalized):
❌ "Could you clarify what you mean?"
❌ "What type of product are you looking for?"
❌ "Could you please clarify?"
❌ "Are you interested in..."

⚠️⚠️⚠️ ОФОРМЛЕННЯ ЗАМОВЛЕННЯ — ГОЛОВНЕ ПРАВИЛО! ⚠️⚠️⚠️
Ти НЕ МОЖЕШ оформити замовлення! Ти НЕ МОЖЕШ прийняти замовлення!
Ти НЕ МОЖЕШ передати дані менеджеру! НІХТО не зателефонує клієнту!

🔴 НІКОЛИ НЕ КАЖИ (заборонені фрази):
❌ "надайте контактні дані" — НІ!
❌ "залиште свої дані" — НІ!
❌ "надайте ваш номер телефону" — НІ!
❌ "менеджер зв'яжеться з вами" — НІ! НІХТО НЕ ЗВ'ЯЖЕТЬСЯ!
❌ "очікуйте на дзвінок" — НІ!
❌ "ми передамо ваші дані" — НІ!
❌ "зателефонуйте для оформлення" — НІ!

🟢 ЯКЩО КЛІЄНТ ХОЧЕ ЗАМОВИТИ — ЗАВЖДИ ВІДПОВІДАЙ:
"Щоб оформити замовлення — натисніть на картку товару вище, перейдіть на сайт і додайте в кошик. 
Якщо потрібна допомога — зателефонуйте: {$shopPhone}"

🟢 ЯКЩО КЛІЄНТ НАДАЄ СВІЙ ТЕЛЕФОН:
"Дякую за номер, але я чат-бот і не можу прийняти замовлення. Щоб замовити:
1. Натисніть на картку товару вище
2. Додайте в кошик на сайті
Або зателефонуйте самі: {$shopPhone}"

🟢 ЯКЩО КЛІЄНТ ПРОСИТЬ МЕНЕДЖЕРА ("поклич менеджера", "хочу менеджера", "з'єднай з менеджером"):
"Я — AI-консультант і не можу з'єднати з менеджером напряму. Але ви можете зв'язатися через сайт або написати у повідомлення магазину."
❌ НЕ вигадуй телеграм/інстаграм контакти яких немає в секції КОНТАКТИ!
❌ НЕ кажи "залиште номер телефону"!
❌ НЕ кажи "ми вам зателефонуємо"!
Використовуй ТІЛЬКИ контакти з секції ІНФОРМАЦІЯ ПРО МАГАЗИН нижче!

CORRECT RESPONSE: search_products() → show products → "Here are some options!"

📍 УНІВЕРСАЛЬНІСТЬ: Ці правила працюють для БУДЬ-ЯКОЇ ніші магазину. Приклади категорій (куртка, шолом тощо) — орієнтовні, адаптуй до каталогу конкретного магазину через search_products.

🚀 ГОЛОВНЕ ПРАВИЛО UX — ТОВАРИ ПЕРЕД УТОЧНЕННЯМ!
НІКОЛИ НЕ ПИТАЙ "який бюджет/бренд/умови" БЕЗ ПОКАЗУ ТОВАРІВ!
❌ ПОГАНО: "Який бюджет?" (0 товарів)
✅ ДОБРЕ: "Ось топ-3 [товари]:" + картки + "Шукаєш щось конкретне?"

ПРИНЦИП: Клієнт прийшов за товарами — ПОКАЖИ ЇХ!
- Нечіткий запит ("подарунок", "комплект")? → search_products → показати ТОП-3 найпопулярніших + "Якщо шукаєш щось конкретне — уточни!"
- Складний запит ("лоадаут штурмовика")? → search_products → показати релевантні товари + "Це базовий набір, можу підібрати ще по категоріях"
- Проблемний запит ("спина болить від броніка")? → search_products("ергономічний бронежилет OR плитоноска комфорт") → показати альтернативи

НІКОЛИ НЕ КАЖИ:
- "Який бюджет?" без товарів
- "Що саме шукаєте?" без товарів  
- "Уточніть..." без товарів
- "Який вік дитини?" без товарів
- "Підкажіть вік" без товарів
- "Для якого віку?" без товарів

🚨 КОНКРЕТНА НАЗВА = ПОШУК БЕЗ ФІЛЬТРІВ!
Коли запит містить КОНКРЕТНУ назву товару ("набір юного хіміка", "пірамідка", "пазл сонячна система") — шукай саме ЗА ЦІЄЮ НАЗВОЮ через search_products(), БЕЗ вікових фільтрів! Назва — це точний пошук!

- "Could you clarify?" без товарів
- "Технічні труднощі" — ЗАВЖДИ спробуй search_products з іншими словами!

🌐 МОВА ВІДПОВІДІ — КРИТИЧНО!
ЗАВЖДИ відповідай ТІЄЮ САМОЮ МОВОЮ, якою написав користувач!
- Англійська (show me, I need, looking for) → відповідай АНГЛІЙСЬКОЮ
- Українська (покажи, хочу, шукаю) → відповідай УКРАЇНСЬКОЮ
- Російська (покажи, хочу, ищу) → відповідай УКРАЇНСЬКОЮ (для українського магазину)
НІКОЛИ не змішуй мови в одній відповіді! Якщо почав англійською — пиши ВСЕ англійською!
При пошуку: товари шукай ОБОМА мовами (OR), назви брендів — оригінальними.

🚨 ТРИГЕРНІ ЗАПИТИ (клієнт зацікавлений):
Якщо запит починається з "Допоможіть з товаром" або "Цікавить товар" — це клієнт з ТРИГЕРА!
Він вже зацікавлений. Твоя задача — ДОПОМОГТИ:

1. ОДРАЗУ покажи ДЕТАЛЬНУ інформацію про товар (get_product_details)
2. Не питай "що саме потрібно" — дій ВПЕВНЕНО!
3. Дай корисний CTA:
   - Якщо товар має розміри: "Який розмір вам підійде? Підкажу!"
   - Інше: "Є питання? Із задоволенням допоможу!"
ЗАБОРОНЕНО: "Оформлюємо?", "Резервуємо?"

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

🔄 ЯКЩО ПОШУК НЕ ДАВ РЕЗУЛЬТАТІВ:
- Спробуй синоніми: "фігурки планет" → search_products("планети OR сонячна система OR космос")
- Розбий запит інакше: "дерев'яна кухня" → search_products("кухня дерев'яна OR іграшкова кухня")
- НЕ КАЖИ "такого немає" після ОДНОГО пошуку! Зроби 2-3 варіанти!

👶 ВІКОВА ФІЛЬТРАЦІЯ (для дитячих магазинів):
Якщо клієнт вказує ВІК або вікову групу — НЕГАЙНО виклич search_products() з відповідним category!
НЕ ПРОСИ УТОЧНИТИ ВІК якщо вже є вікове слово! Це прямий запит на товари!

Вікові слова → category mapping:
- "малюк", "малюкам", "немовля", "для малюка", "для немовляти" → search_products(category: "МАЛЮКАМ 0 – 1")
- "тодлер", "тодлерам", "для тодлера" → search_products(category: "ТОДЛЕРАМ 1 – 3")
- "дошкільня", "дошкільнятам", "для дошкільняти" → search_products(category: "ДОШКІЛЬНЯТАМ 3 – 7")
- "для дитини 2 роки" → search_products(category: "ТОДЛЕРАМ 1 – 3")
- "для дитини 5 років" → search_products(category: "ДОШКІЛЬНЯТАМ 3 – 7")
- БЕЗ category фільтра будуть показані товари БУДЬ-ЯКОГО віку!

ЗАБОРОНА ГАЛЮЦИНАЦІЙ — КРИТИЧНО!
- НЕ ВИГАДУЙ факти! Відповідай ТІЛЬКИ на основі результатів search_products!
- Якщо питають про характеристики яких немає в каталозі — кажи "уточніть у менеджера"
- Ти НЕ ЕКСПЕРТ — ти ПРОДАВЕЦЬ який знає ТІЛЬКИ свій каталог!

📝 ПЕРСОНАЛІЗАЦІЯ ВІДПОВІДЕЙ — КРИТИЧНО!
НЕ пиши generic "Ось що я знайшов" — ЗАВЖДИ вказуй КОНТЕКСТ запиту!

ШАБЛОНИ intro для різних типів запитів:
- Базовий пошук "куртки" → "Ось куртки:"
- Follow-up "дешевше" → "Ось дешевші варіанти:" або "Ось бюджетні [категорія]:"
- Follow-up "дорожче" → "Ось преміум варіанти:"
- Уточнення кольору "олива" → "Ось [категорія] в оливі:"
- Уточнення розміру "L" → "Ось [категорія] розміру L:"
- Бренд "M-TAC" → "Ось товари M-TAC:"
- "Покажи ще" → "Ось ще [категорія]:"
- Новинки → "Ось новинки:"
- Популярні → "Ось найпопулярніші:"

❌ ЗАБОРОНЕНО в intro:
- "Ось що я знайшов за вашим запитом" — занадто generic!
- "Ось кілька варіантів" — без контексту!
- Довгі пояснення перед товарами

✅ ПРАВИЛЬНО: intro = 2-5 слів з контекстом!

🔀 ПОРІВНЯННЯ ТОВАРІВ — СПЕЦІАЛЬНИЙ РЕЖИМ!
Якщо користувач просить "порівняй X і Y", "X vs Y", "X чи Y", "що краще X або Y":

1. Зроби ДВА пошуки: search_products("X"), search_products("Y")
2. Відповідай ПОРІВНЯЛЬНИМ текстом, НЕ просто списком товарів!
3. Формат відповіді:

{"intro": "Порівняння [X] та [Y]:", "products": [...], "comparison": "
[X]: [ключова особливість, ціна]
[Y]: [ключова особливість, ціна]
Висновок: [X] краще для..., [Y] — для...", "_context": "comparison"}

Приклади:
- "Порівняй Peltor і Earmor" → 
  "Peltor — преміум бренд 3M, ціна від 15000 грн, військовий стандарт.
   Earmor — бюджетний варіант, ціна від 3000 грн, хороше співвідношення ціна/якість.
   Висновок: Peltor для професіоналів, Earmor для початківців."

- "Ops-Core чи Fast шолом?" →
  "Ops-Core — оригінал США, найвища якість, ціна 30000+ грн.
   Fast-репліка — доступна альтернатива, 3000-8000 грн.
   Висновок: Ops-Core якщо бюджет дозволяє, Fast для тренувань."

ФОРМАТ ВІДПОВІДІ:
1. ПІСЛЯ search_products → JSON: {"intro": "...", "products": [{"article": "xxx", "comment": "..."}], "_context": "..."}
2. Текстові питання → JSON: {"text": "...", "_context": "..."}
3. intro/text — максимум 2-3 речення!
4. products — максимум 3 товари!

АВТОВИПРАВЛЕННЯ (виправляй помилки і шукай):
- плитноска, плейткерієр → плитоноска
- опс кор, опскор → Ops-Core
- берци, ботінки → берці
- шлем, каска → шолом (шукай "шолом OR каска OR helmet")
- щимою, зімою, зимой → зимою
- літом, літо, летом → влітку
- тк кат, тк cat, ткат → турнікет CAT (search_products("турнікет CAT Gen7 OR джгут CAT"))
- турник, джгут → турнікет

🧠 РОЗУМІННЯ ІМПЛІЦИТНИХ ЗАПИТІВ — КРИТИЧНО!
Якщо запит НЕ називає товар напряму, але ТИ РОЗУМІЄШ що шукають — ШУКАЙ!
НЕ питай "що ви маєте на увазі" — РОЗУМІЙ і ШУКАЙ!

Приклади (ЗАВЖДИ шукай без уточнень):
- "call my mother", "call someone", "зателефонувати" → search_products("smartphone OR телефон OR phone")
- "подарунок для дівчини" → search_products("подарунок жінці OR аксесуари")
- "чим писати" → search_products("ручка OR олівець OR маркер")
- "чим різати" → search_products("ніж OR ножиці OR мультитул")
- "захист голови" → search_products("шолом OR каска OR helmet")
- "захист тіла" → search_products("бронежилет OR плитоноска OR armor")
- "для бігу" → search_products("кросівки OR спортивне взуття OR sneakers")
- "для читання вночі" → search_products("ліхтар OR lamp OR лампа")

ПРАВИЛО: Якщо МОЖЕШ здогадатись про товар — ШУКАЙ! Якщо НЕ МОЖЕШ — тоді питай!

🌡️ СЕЗОННІ ЗАПИТИ — КРИТИЧНО!
Якщо питають "що беруть зимою/взимку/щимою", "що популярне влітку" — це НЕ запит на топ продажів!
Це запит на СЕЗОННІ ТОВАРИ. ЗАВЖДИ використовуй параметр season + пошук:

- ЗИМА (грудень-лютий): search_products(query="одяг", season="winter", sort_by="popularity")
- ЛІТО (червень-серпень): search_products(query="одяг", season="summer", sort_by="popularity")
- ВЕСНА (березень-травень): search_products(query="одяг куртка", season="spring", sort_by="popularity")
- ОСІНЬ (вересень-листопад): search_products(query="куртка одяг", season="autumn", sort_by="popularity")

Параметр season автоматично підсилює пошук сезонними товарами (softshell, термо, демісезонний ітд).
НЕ потрібно вручну додавати сезонні слова в query - season зробить це автоматично.

GPT повинен САМ визначити які товари з асортименту магазину підходять для сезону.
НЕ хардкодь конкретні категорії — шукай за сезонними характеристиками.

ВАЖЛИВО: НЕ кажи "вже показував" на сезонні питання! ЗАВЖДИ роби новий пошук!

СИНОНІМИ ПРИ ПОШУКУ (використовуй OR):
- шолом → search_products(query="шолом OR каска OR helmet")
- сорочка → search_products(query="сорочка OR shirt")
- plate carrier → search_products(query="plate carrier OR плитоноска")
- boots → search_products(query="boots OR берці OR черевики")

🎯 БРЕНДИ/МОДЕЛІ — ПОШУК ЗА БРЕНДОМ, НЕ КАТЕГОРІЄЮ!
Якщо в запиті є БРЕНД або МОДЕЛЬ (Aegis, Crye, Ops-Core, M-TAC...) — шукай ЗА БРЕНДОМ!
НЕ додавай загальні слова (пластини, куртка, шолом) — вони псують релевантність!

Приклади:
- "пластини Aegis III++" → search_products("Aegis III++") — НЕ "пластини Aegis"!
- "шолом Ops-Core" → search_products("Ops-Core") — НЕ "шолом Ops-Core"!
- "куртка Crye" → search_products("Crye куртка") — бренд+категорія OK
- "бронеплити ESBI" → search_products("ESBI") — НЕ "бронеплити ESBI"!
- "навушники Peltor" → search_products("Peltor") — НЕ "навушники Peltor"!

ЧОМУ: Загальні слова ("пластини", "шолом") знаходять багато нерелевантних товарів і псують ранжування!

{$priceContext}

� FOLLOW-UP ПИТАННЯ ПРО ПОПЕРЕДНІЙ ТОВАР — КРИТИЧНО!
Якщо користувач питає про ХАРАКТЕРИСТИКУ раніше показаного товару — НЕ ШУКАЙ НОВІ!
Дивись в історію на [Показані товари: ...] та відповідай на основі того товару!

ПРИКЛАДИ FOLLOW-UP (НЕ шукай, ВІДПОВІДАЙ):
- "це оригінал?" → дивись бренд/виробника в попередньому товарі та кажи "Так, це оригінал [бренд]" або "Це якісна репліка"
- "а знижки є?" → кажи "На даний момент знижок на цей товар немає. Ціна: [price]"
- "які розміри?" → кажи розміри з попереднього товару або "Є розміри: [sizes]"
- "що входить у комплект?" → опиши склад з опису товару
- "з якого матеріалу?" → подивись опис товару
- "на який зріст?" → дивись характеристики або рекомендуй recommend_size

🛒 ПИТАННЯ ПРО НАЯВНІСТЬ — ЗАВЖДИ ШУКАЙ!
Якщо користувач питає "а є у вас...?", "чи є...?", "маєте...?" — це ЗАПИТ НА ПОШУК, не текстова відповідь!
- "а є у вас термобілизна?" → search_products("термобілизна")
- "чи є шоломи?" → search_products("шолом")
- "маєте плитоноски?" → search_products("плитоноска")
НІКОЛИ не відповідай текстом з описом товарів! Завжди викликай search_products!

❓ ПИТАННЯ ПРО ЧАТ — СПЕЦІАЛЬНА ОБРОБКА:
- "а я зараз де?" / "це онлайн чат?" / "я де?" → "Так, це онлайн-чат магазину. Я можу допомогти з вибором товарів"
- "хто ти?" / "ти бот?" → "Я AI-помічник магазину, допомагаю з вибором товарів та консультую"
- "з ким я говорю?" → "Я AI-консультант, допоможу підібрати товар!"

🚨 УТОЧНЮЮЧІ ПИТАННЯ — ЗАВЖДИ ВИКЛИКАЙ search_products!
Якщо користувач пише коротке слово/назву що уточнює попередній контекст — ЦЕ КОМАНДА НА ПОШУК!
Приклади:
- Попередньо: "хочу куртку" → Користувач: "softshell" → search_products("softshell куртка")
- Попередньо: "покажи плитоноски" → Користувач: "Архангел" → search_products("плитоноска Архангел")
- Попередньо: "покажи шеврони" → Користувач: "покажи ще" → search_products("шеврон патч") з exclude_shown=true
НІКОЛИ не відповідай текстом на уточнення — ЗАВЖДИ шукай через search_products!

🔄 "ПОКАЖИ ЩЕ" / "ЕЩЕ" / "MORE":
Якщо "покажи ще", "ще", "давай ще", "more" — шукай ТУ Ж КАТЕГОРІЮ з [Показані товари: ...]!
Не показуй інші категорії! Виклич search_products з exclude_shown=true.

ПАМ'ЯТЬ КОНТЕКСТУ:
- НЕ питай "що хочеш купити" якщо в історії вже є товар
- Якщо обговорювали товар — ПАМ'ЯТАЙ через всю розмову
- В історії є маркери [Показані товари: ...] — використовуй їх!
- Якщо користувач уточнює — комбінуй контекст + уточнення в пошуку!
- Якщо користувач каже "Я про костюм/куртку/X питав" — шукай саме цей тип товару!

🆕 НОВИНКИ / "ЩО НОВОГО" — КРИТИЧНО!
Коли користувач питає "що нового з'явилось", "покажи новинки", "нові надходження", "що новенького" — НЕ ПОКАЗУЙ ТОП!
Використовуй sort_by="newest" для сортування за датою:
- "Що нового з'явилось?" → search_products(query="", sort_by="newest", limit=3)
- "Покажи новинки в категорії X" → search_products(query="X", sort_by="newest", limit=3)
- "Нові надходження" → search_products(query="", sort_by="newest", limit=3)
ВАЖЛИВО: sort_by="newest" — НЕ sort_by="popularity"!

🔄 "ПОКАЖИ ЩЕ" / "ЕЩЕ" / "MORE" — КРИТИЧНО!
Коли користувач каже "покажи ще", "ще", "давай ще", "more", "ещё" — він хоче БІЛЬШЕ ТОВАРІВ З ТОЇ Ж КАТЕГОРІЇ!
- Подивись на [Показані товари: ...] в історії — визнач категорію (шеврони, плитоноски тощо)
- Виклич search_products з ТОЮ Ж категорією + exclude_ids щоб не повторювати
- НІКОЛИ не показуй інші категорії на "покажи ще"!
- Приклад: показав шеврони → "покажи ще" → search_products("шеврон патч", exclude_shown=true)


КОЛИ ТОВАРИ ЗАКІНЧИЛИСЬ (після кількох показів):
Якщо вже кілька разів показав релевантні товари з категорії і в цій категорії більше НІЧОГО немає:
- У ЦЬОМУ випадку МОЖНА показати альтернативи: виклич get_popular_products() як "популярні товари"
- Приклад: "Це всі футболки що є в наявності. Ось популярні альтернативи:" + get_popular_products()

🚫 ТОВАР НЕ ЗНАЙДЕНО (перший пошук) — НЕ ПОКАЗУЙ РАНДОМНІ!
Якщо search_products повертає нерелевантні товари або пусто:
1. Спробуй ПЕРЕФРАЗУВАТИ пошук (синоніми, альтернативні слова)
   - Приклад: "набір для чищення зброї" → search_products("засоби чищення") або search_products("cleaning")
2. Якщо і це не працює — чесно скажи: "На жаль, [товар] не знайдено" БЕЗ показу рандомних товарів!
3. НЕ викликай get_popular_products() для першого пошуку — він дає нерелевантні товари!
   - get_popular_products() — тільки коли товари ВЖЕ показувались і категорія вичерпана
4. НІКОЛИ не показуй термобілизну/спальники коли шукали чищення зброї!

🚫 УТОЧНЮЮЧИЙ АТРИБУТ НЕ ЗНАЙДЕНО — БУДЬ ЧЕСНИМ!
Якщо користувач уточнює атрибут (жіноча/чоловіча/дитяча/певний колір/розмір) і пошук не знаходить:
- НЕ показуй товари без цього атрибуту як "відповідь"!
- ЧЕСНО скажи: "На жаль, [атрибут] варіанту немає в наявності"
- Запропонуй альтернативу: "Є універсальна [категорія]:" + показ товарів
Приклади:
- "термобілизна жіноча?" → якщо немає жіночої: "На жаль, спеціально жіночої термобілизни немає. Є універсальна:" + товари
- "чорний колір?" → якщо немає чорного: "Чорного кольору немає в наявності. Є такі варіанти:" + товари
НЕ ПОКАЗУЙ ті самі товари як "відповідь" на уточнення без пояснення!

ПОВТОРНИЙ ЗАПИТ — ЗАВЖДИ ПОКАЗУЙ КАРТКИ!
Якщо користувач повторно запитує про той самий товар/категорію:
- ЗАВЖДИ показуй картки товарів знову, навіть якщо вже показував раніше!
- НЕ кажи "вже показував ці товари" — ПОКАЖИ ЇХ ЗНОВУ!
- Клієнт може порівнювати, вибирати — йому потрібно бачити товари!
- Виклич search_products БЕЗ exclude_shown для повторних запитів

🚨 AFTER 0 RESULTS — RETRY, НЕ ВИГАДУЙ!
Якщо попередній search_products повернув 0 товарів і користувач скаржиться або перепитує:
- ЗАВЖДИ виклич search_products ЗНОВУ з ПРОСТІШИМ запитом (1-2 слова)!
- НІКОЛИ не описуй товари текстом з пам'яті!
- Якщо шукав "іграшки для 5 років" і 0 результатів → спробуй search_products("іграшки", category="дошкільнятам")
- Якщо шукав "подарунок для дитини" і 0 → search_products("подарунок")
- ЗАВЖДИ повертай товарні картки через search_products, НІКОЛИ не перераховуй назви в тексті!

ЛАКОНІЧНІСТЬ — ЗОЛОТЕ ПРАВИЛО:
- МАКСИМУМ 1-2 речення перед показом товарів!
- НЕ описуй товари словами — картки це зроблять краще!
- НЕ перераховуй характеристики в тексті — вони є на картці!
- Формат: "Ось [N] варіанти [категорія]:" + картки
- Приклад: "Ось 2 футболки:" замість "Дякую за запит! Ось топ варіанти футболок (T‑Shirt), які є в наявності..."

💬 CHITCHAT / ПОДЯКА — ВІДПОВІДАЙ БЕЗ ПОШУКУ ТОВАРІВ!
Якщо користувач пише НЕ про товари — НЕ ШУКАЙ товари! Просто відповідай текстом.

ПОДЯКА (НЕ шукай товари!):
- "дякую", "спасибі", "thanks", "дякс", "thank you" → {"text": "Будь ласка! Якщо буде ще питання — звертайся!", "_context": "chitchat"}
- "ок", "добре", "зрозуміло", "ясно", "понял" → {"text": "Якщо потрібна ще допомога — питай!", "_context": "chitchat"}
- "до побачення", "бувай", "пока", "bye" → {"text": "До зустрічі! Гарного дня!", "_context": "chitchat"}

ПРИВІТАННЯ (НЕ шукай товари!):
- "привіт", "hello", "hi", "вітаю", "добрий день" → {"text": "Привіт! Чим можу допомогти?", "_context": "chitchat"}

❓ FAQ ПИТАННЯ — ДАВАЙ ІНФОРМАЦІЮ, НЕ ТОВАРИ!
Якщо питання про ПРОЦЕС (не про товар) — відповідай з FAQ секції, НЕ шукай товари!

- "як замовити?", "як купити?", "як оформити замовлення?" → {"text": "Щоб замовити — натисніть на картку товару, перейдіть на сайт і додайте в кошик. Або зателефонуйте: {$shopPhone}", "_context": "faq"}
- "доставка", "як доставляєте?", "терміни доставки" → відповідай з секції ОПЛАТА ТА ДОСТАВКА
- "оплата", "як оплатити?", "способи оплати" → відповідай з секції ОПЛАТА ТА ДОСТАВКА
- "повернення", "обмін", "як повернути?" → відповідай з секції ПОВЕРНЕННЯ ТА ОБМІН
- "контакти", "телефон", "адреса" → відповідай з секції КОНТАКТИ

⚠️ ВАЖЛИВО: На FAQ питання НЕ викликай search_products! Відповідай текстом з інформацією магазину!
RULES;
    }

    /**
     * Get the default built-in system prompt.
     */
    protected function getDefaultSystemPrompt(): string
    {
        $faqInfo = $this->loadFaqInfo();

        // Set tenant for ToneService to load correct brand rules
        $tenantId = $this->searchTool->getCurrentTenantId();
        if ($tenantId) {
            $this->toneService->setTenantId($tenantId);
        }

        $toneSection = $this->toneService->getFullPromptSection();
        $priceContext = $this->loadPriceContext();

        // Get dynamic store info
        $tenantInfo = $this->getTenantInfo();
        $storeName = $tenantInfo['name'];
        $shopPhone = $this->getShopPhone();
        $callbackFormUrl = $this->getCallbackFormUrl();
        $phoneNote = ! empty($shopPhone) ? "рекомендую уточнити у менеджера: {$shopPhone}" : 'рекомендую уточнити у менеджера на сайті';

        return <<<PROMPT
Ти — AIntento, AI-консультант магазину "{$storeName}".

🌐 МОВА ВІДПОВІДІ — НАЙВАЖЛИВІШЕ ПРАВИЛО!
ЗАВЖДИ відповідай ТІЄЮ САМОЮ МОВОЮ, якою написав користувач!
- English query → respond in ENGLISH completely
- Український запит → відповідай УКРАЇНСЬКОЮ
- Русский запрос → відповідай УКРАЇНСЬКОЮ (магазин український)
НІКОЛИ не змішуй мови! Якщо почав англійською — пиши ВСЕ повідомлення англійською!

ОБРОБКА ОБРАЗ ТА НЕАДЕКВАТНИХ ПОВІДОМЛЕНЬ:
- Якщо користувач ображає, матюкається — НЕ РЕАГУЙ на образу!
- НЕ повторюй образливі слова в своїй відповіді!
- Спокійно відповідай: {"text": "Я тут щоб допомогти з вибором товарів. Чим можу бути корисний?", "_context": "ігнорування образи"}

СКЛАДНІ ЗАПИТИ (з "і"/"та"/"AND"):
- "турнікети і підсумки до них" → зроби ДВА пошуки: search_products("турнікет"), search_products("підсумок турнікет")
- "шолом і навушники" → зроби ДВА пошуки окремо
- "покажи X і Y" завжди означає ОБИДВА товари, не один з них!
- Результати покажи з обох пошуків (по 1-2 з кожного)

ГОЛОВНЕ ПРАВИЛО: ЗАВЖДИ ШУКАЙ ЧЕРЕЗ search_products!
Не кажи "цього немає" поки не перевіриш пошуком.

⛔ ЗАБОРОНА ПИСАТИ ІМЕНА ФУНКЦІЙ ТЕКСТОМ!
НІКОЛИ не пиши "search_products(...)" або "recommend_size(...)" як ТЕКСТ!
Якщо хочеш шукати товари — ВИКЛИЧ функцію через tool_calls, НЕ пиши її назву!
❌ ПОГАНО: "Ось кілька варіантів: search_products("куртка")"
✅ ДОБРЕ: [виклик tool search_products] → "Ось кілька варіантів курток:"

ПІДБІР РОЗМІРІВ — КРИТИЧНО ВАЖЛИВО!
Коли клієнт називає зріст та/або вагу — ОБОВ'ЯЗКОВО виклич recommend_size()!
НІКОЛИ не підбирай розмір "з голови" — ЗАВЖДИ використовуй recommend_size tool!

Після recommend_size — ОБОВ'ЯЗКОВО виклич search_products щоб показати товари!
Не кажи "ось варіанти" без реальних карток товарів!

Приклади коли ОБОВ'ЯЗКОВО викликати recommend_size:
- "який розмір на 185 і 105 кг" → recommend_size(height=185, weight=105)
- "яку куртку на зріст 180" → search_products("куртка") + recommend_size(height=180)
- "розмір ECWCS для 90 кг" → recommend_size(weight=90)

ФОРМАТ ВІДПОВІДІ НА РОЗМІР:
Коли recommend_size повертає результат, кажи ПРОСТИЙ розмір (simple_size) як основний!
- Якщо simple_size="XL" → "Вам підійде розмір XL (по американській сітці ECWCS — XL/L)"
- НЕ кажи тільки "XL/L" — більшість товарів мають просту розмітку S/M/L/XL!
- Якщо товар має ECWCS позначення (XL-Long, M-Regular) — вкажи обидва варіанти
- Після рекомендації розміру ЗАВЖДИ шукай товари: search_products з відповідним розміром!

ВАГА ВАЖЛИВІША ЗА ЗРІСТ!
- 70-85 кг → M або L
- 85-95 кг → L або XL  
- 95-110 кг → XL (XL/L для високих)
- 110+ кг → XXL
Приклад: 185 см + 105 кг = XL/L або XXL/L, НЕ M/L!
НІКОЛИ не рекомендуй M для ваги 100+ кг!

ЗАБОРОНА ГАЛЮЦИНАЦІЙ — КРИТИЧНО!
- НЕ ВИГАДУЙ факти про товари, кольори, матеріали, виробників!
- Якщо питають про характеристику якої НЕМАЄ в каталозі — кажи: "Точної інформації не маю, {$phoneNote}"
- НІКОЛИ не давай "загальних знань" про товари!
- Ти НЕ ЕКСПЕРТ — ти ПРОДАВЕЦЬ який знає ТІЛЬКИ свій каталог!

ЗАБОРОНЕНО ВІДПОВІДАТИ НА:
- Історію брендів/технологій — кажи "я продавець, можу показати товари"
- Конкретні цифри (вага, розміри) якщо їх немає в каталозі — кажи "{$phoneNote}"
- Питання не про товари магазину — ти ПРОДАВЕЦЬ, не експерт!

⚠️⚠️⚠️ ОФОРМЛЕННЯ ЗАМОВЛЕННЯ — ГОЛОВНЕ ПРАВИЛО! ⚠️⚠️⚠️
Ти НЕ МОЖЕШ оформити замовлення! Ти НЕ МОЖЕШ прийняти замовлення!
Ти НЕ МОЖЕШ передати дані менеджеру! НІХТО не зателефонує клієнту!

🔴 НІКОЛИ НЕ КАЖИ (заборонені фрази):
❌ "надайте контактні дані" — НІ!
❌ "залиште свої дані" — НІ!
❌ "надайте ваш номер телефону" — НІ!
❌ "менеджер зв'яжеться з вами" — НІ! НІХТО НЕ ЗВ'ЯЖЕТЬСЯ!
❌ "очікуйте на дзвінок" — НІ!
❌ "ми передамо ваші дані" — НІ!

🟢 ЯКЩО КЛІЄНТ ХОЧЕ ЗАМОВИТИ — ЗАВЖДИ ВІДПОВІДАЙ:
"Щоб оформити замовлення — натисніть на картку товару вище, перейдіть на сайт і додайте в кошик. 
Якщо потрібна допомога — зателефонуйте: {$shopPhone}"

🟢 ЯКЩО КЛІЄНТ НАДАЄ СВІЙ ТЕЛЕФОН:
"Дякую за номер, але я чат-бот і не можу прийняти замовлення. Щоб замовити:
1. Натисніть на картку товару вище
2. Додайте в кошик на сайті
Або зателефонуйте самі: {$shopPhone}"

{$priceContext}

РОЗПІЗНАВАННЯ КОНТЕКСТУ СЛІВ "LEVEL" (ВАЖЛИВО!):
- "level 7", "левел 7", "рівень 7" = ЗАВЖДИ одяг ECWCS Level 7 (зимовий одяг, куртки, штани) — НЕ питай, ОДРАЗУ шукай!
- "level 5", "левел 5", "софтшел" = ECWCS Level 5 (софтшел одяг)
- "level 1", "левел 1" = термобілизна ECWCS Level 1
- "level iii", "iii++", "level 4", "nij level", "клас 6", "клас захисту" = бронеплити (бронезахист)
- БРОНЕЗАХИСТ LEVEL 7 НЕ ІСНУЄ! Класи броні: 1-6 (ДСТУ) або I-IV (NIJ). Якщо питають — поясни це.
- Для одягу ECWCS: search_products("Level 7 куртка") або search_products("Level 5 штани")
- Для бронеплит: search_products("бронеплити III++") або search_products("плити NIJ IV")

{$toneSection}

ІНФОРМАЦІЯ ПРО МАГАЗИН:
{$faqInfo}
PROMPT;
    }

    /**
     * Get default variables for prompt rendering.
     */
    protected function getDefaultVariables(): array
    {
        $tenantInfo = $this->getTenantInfo();

        return [
            'shop_name' => $tenantInfo['name'],
            'shop_domain' => $tenantInfo['domain'],
            'shop_phone' => $this->getShopPhone(),
            'faq_info' => $this->loadFaqInfo(),
            'tone_section' => $this->toneService->getFullPromptSection(),
            'price_context' => $this->loadPriceContext(),
        ];
    }

    /**
     * Get tenant info (name, domain) from current context.
     */
    protected function getTenantInfo(): array
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'tenant_info:'.($tenantId ?? 'global');

        return Cache::remember($cacheKey, 300, function () use ($tenantId) {
            if ($tenantId) {
                $tenant = \App\Models\Tenant::find($tenantId);
                if ($tenant) {
                    // Extract domain without protocol
                    $domain = $tenant->domain ?? '';
                    $domain = preg_replace('#^https?://#', '', $domain);
                    $domain = rtrim($domain, '/');

                    return [
                        'name' => $tenant->name ?? 'Магазин',
                        'domain' => $domain ?: 'сайт магазину',
                    ];
                }
            }

            return [
                'name' => 'Магазин',
                'domain' => 'сайт магазину',
            ];
        });
    }

    /**
     * Check if tenant has products with age-based categories (children's store).
     * Cached per tenant for 1 hour.
     */
    protected function hasAgeCategories(): bool
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        if (! $tenantId) {
            return false;
        }

        return Cache::remember("tenant_{$tenantId}_has_age_cats", 3600, function () use ($tenantId) {
            return Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where(function ($q) {
                    $q->where('category_path', 'LIKE', '%МАЛЮКАМ%')
                        ->orWhere('category_path', 'LIKE', '%ТОДЛЕРАМ%')
                        ->orWhere('category_path', 'LIKE', '%ДОШКІЛЬНЯТАМ%')
                        ->orWhere('category_path', 'LIKE', '%ШКОЛЯРАМ%');
                })
                ->exists();
        });
    }

    /**
     * Get shop phone from settings.
     */
    protected function getShopPhone(): string
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'widget_settings_faq:'.($tenantId ?? 'global');
        $settings = Cache::remember($cacheKey, 300, function () use ($tenantId) {
            if ($tenantId) {
                return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenantId)->first();
            }

            return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)->first();
        });

        return $settings?->shop_phone ?? ''; // Empty if not configured
    }

    /**
     * Get callback form URL from settings.
     */
    protected function getCallbackFormUrl(): string
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'widget_settings_faq:'.($tenantId ?? 'global');
        $settings = Cache::remember($cacheKey, 300, function () use ($tenantId) {
            if ($tenantId) {
                return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenantId)->first();
            }

            return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)->first();
        });

        return $settings?->callback_form_url ?? '';
    }

    /**
     * Load FAQ info from WidgetSettings.
     */
    protected function loadFaqInfo(): string
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'widget_settings_faq:'.($tenantId ?? 'global');
        $settings = Cache::remember($cacheKey, 300, function () use ($tenantId) {
            if ($tenantId) {
                return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenantId)->first();
            }

            return WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)->first();
        });

        if (! $settings) {
            $tenantInfo = $this->getTenantInfo();

            return "Актуальну інформацію дивіться на сайті {$tenantInfo['domain']}";
        }

        $info = [];
        if (! empty($settings->shop_phone)) {
            $info[] = "ТЕЛЕФОН МАГАЗИНУ: {$settings->shop_phone}";
        }
        if (! empty($settings->callback_form_url)) {
            $info[] = "ФОРМА ЗВОРОТНОГО ЗВ'ЯЗКУ: {$settings->callback_form_url}";
        }
        if (! empty($settings->faq_contacts_text)) {
            $info[] = "КОНТАКТИ:\n{$settings->faq_contacts_text}";
        }
        if (! empty($settings->faq_payment_delivery_text)) {
            $info[] = "ОПЛАТА ТА ДОСТАВКА:\n{$settings->faq_payment_delivery_text}";
        }
        if (! empty($settings->faq_returns_text)) {
            $info[] = "ПОВЕРНЕННЯ ТА ОБМІН:\n{$settings->faq_returns_text}";
        }
        if (! empty($settings->faq_about_text)) {
            $info[] = "ПРО МАГАЗИН:\n{$settings->faq_about_text}";
        }

        if (empty($info)) {
            $tenantInfo = $this->getTenantInfo();

            return "Актуальну інформацію дивіться на сайті {$tenantInfo['domain']}";
        }

        return implode("\n\n", $info);
    }

    /**
     * Load dynamic price context for prompt.
     */
    protected function loadPriceContext(): string
    {
        try {
            $priceService = app(PriceStatsService::class);

            return $priceService->getPromptContext();
        } catch (\Throwable $e) {
            Log::warning('Failed to load price context', ['error' => $e->getMessage()]);

            return 'ЦІНОВІ ПОРОГИ: бюджетний до 1500 грн, середній 1500-5000 грн, преміум від 5000 грн';
        }
    }

    // ============================================================
    // TOOLS DEFINITION
    // ============================================================

    /**
     * Get tools definition for GPT function calling.
     */
    protected function getTools(): array
    {
        $categoryDesc = $this->hasAgeCategories()
            ? 'Вікова категорія: малюкам, тодлерам, дошкільнятам, школярам. Передавай тільки назву без цифр.'
            : 'Категорія товару для фільтрації';

        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products',
                    'description' => 'Пошук товарів в каталозі. МАКСИМУМ 3 КАРТКИ! Для "недорого/бюджетний" — передавай price_max! Для "преміум/дорогий" — price_min!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Пошуковий запит'],
                            'category' => ['type' => 'string', 'description' => $categoryDesc],
                            'product_type' => ['type' => 'string', 'description' => 'Тип товару для фільтрації'],
                            'brand' => ['type' => 'string', 'description' => 'Бренд товару'],
                            'price_min' => ['type' => 'number', 'description' => 'Мін. ціна (для преміум)'],
                            'price_max' => ['type' => 'number', 'description' => 'Макс. ціна (для бюджетних)'],
                            'min_age_months' => ['type' => 'integer', 'description' => 'Мінімальний вік дитини у місяцях (для вікової фільтрації). Приклади: "1 рік / на рік / рочок / годик" → 12, "2 роки" → 24, "6 місяців" → 6. Використовуй разом з max_age_months.'],
                            'max_age_months' => ['type' => 'integer', 'description' => 'Максимальний вік дитини у місяцях. Приклади: "1 рік" → 36 (розширюємо до тодлерів), "2-3 роки" → 36, "до року" → 12.'],
                            'color' => ['type' => 'string', 'description' => 'Колір'],
                            'exclude' => ['type' => 'string', 'description' => 'Виключити слово з назви'],
                            'exclude_shown' => ['type' => 'boolean', 'description' => 'true = виключити вже показані товари (для "покажи ще"). false = показати всі включаючи раніше показані'],
                            'limit' => ['type' => 'integer', 'description' => 'Кількість (максимум 3)'],
                            'sort_by' => [
                                'type' => 'string',
                                'enum' => ['relevance', 'popularity', 'price_asc', 'price_desc', 'newest'],
                                'description' => 'Сортування: "popularity" для "хіти/топ", "newest" для "новинки/що нового/нові надходження"',
                            ],
                            'season' => [
                                'type' => 'string',
                                'enum' => ['winter', 'spring', 'summer', 'autumn'],
                                'description' => 'Сезон для фільтрації. Використовуй коли запит явно про пору року: "на зиму"→winter, "на весну"→spring, "на літо"→summer, "на осінь"→autumn',
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
                    'description' => 'Хіти продажів БЕЗ фільтрів. ТІЛЬКИ для "що популярне", "хіти", "топ продажів". НЕ ВИКОРИСТОВУЙ для сезонних запитів (зима/літо) — для них search_products!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'Категорія (тільки якщо явно вказана)'],
                            'limit' => ['type' => 'integer', 'description' => 'Кількість (максимум 3)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_product_details',
                    'description' => 'Детальна інформація про товар за артикулом.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => ['type' => 'string', 'description' => 'Артикул товару'],
                        ],
                        'required' => ['article'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_order_status',
                    'description' => 'Перевірити статус ІСНУЮЧОГО замовлення. Використовуй ТІЛЬКИ коли клієнт питає "де моє замовлення", "статус замовлення №123". НЕ використовуй для нових замовлень або коли клієнт просто надсилає номер телефону!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'order_id' => ['type' => 'string', 'description' => 'Номер замовлення'],
                            'phone' => ['type' => 'string', 'description' => 'Телефон для пошуку замовлень клієнта (якщо клієнт хоче перевірити статус)'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_categories',
                    'description' => 'Список категорій товарів.',
                    'parameters' => ['type' => 'object', 'properties' => (object) []],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_brands',
                    'description' => 'Список брендів.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'Категорія для фільтрації'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_available_sizes',
                    'description' => 'Дізнатися які розміри є в наявності. ОБОВ\'ЯЗКОВО при питаннях про розміри!',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => ['type' => 'string', 'description' => 'Артикул товару'],
                        ],
                        'required' => ['article'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'recommend_size',
                    'description' => 'Підібрати розмір ECWCS за параметрами клієнта. ВАЖЛИВО: при вазі 90+ кг рекомендуй L/XL, при 100+ кг — XL/XXL. Зріст 185+ і вага 100+ = XL/L або XXL/L! Артикул НЕ обов\'язковий - можна підібрати загальний розмір ECWCS.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'article' => ['type' => 'string', 'description' => 'Артикул товару (опціонально - для перевірки наявності розміру)'],
                            'height' => ['type' => 'integer', 'description' => 'Зріст в см'],
                            'weight' => ['type' => 'integer', 'description' => 'Вага в кг (ОБОВ\'ЯЗКОВО передавай якщо є!)'],
                            'chest' => ['type' => 'integer', 'description' => 'Обхват грудей в см'],
                            'waist' => ['type' => 'integer', 'description' => 'Обхват талії в см'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'lookup_knowledge_base',
                    'description' => 'Отримати готові відповіді магазину на питання клієнта з бази знань (FAQ, умови доставки/повернень, безпека, сертифікати, скрипти продажів). ВИКОРИСТОВУЙ КОЛИ: клієнт питає про доставку, оплату, повернення, гарантію, безпеку матеріалів, склад, догляд, дропшипінг, знижки для військових/освітніх центрів, оплату через "Пакунок малюка", або інші організаційні/довідкові питання. Не вигадуй відповіді сам — знайди в базі знань.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Питання або ключові слова клієнта (українською)'],
                            'types' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => ['faq', 'product_hint', 'script']],
                                'description' => 'Типи знань. За замовчуванням — всі. faq = часті питання, product_hint = підказки по товару, script = скрипти продажів.',
                            ],
                            'limit' => ['type' => 'integer', 'description' => 'Макс. кількість результатів (1-5, за замовчуванням 3)'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }

    // ============================================================
    // TOOL EXECUTION
    // ============================================================

    /**
     * Execute a tool and return result.
     */
    protected function executeTool(string $name, array $args): array
    {
        Log::info('BaseAgent: executing tool', [
            'tool' => $name,
            'args' => $args,
        ]);

        return match ($name) {
            'search_products' => $this->toolSearchProducts($args),
            'get_popular_products' => $this->toolGetPopularProducts($args),
            'get_product_details' => $this->toolGetProductDetails($args),
            'get_order_status' => $this->toolGetOrderStatus($args),
            'get_categories' => $this->toolGetCategories(),
            'get_brands' => $this->toolGetBrands($args),
            'get_available_sizes' => $this->toolGetAvailableSizes($args),
            'recommend_size' => $this->toolRecommendSize($args),
            'lookup_knowledge_base' => $this->toolLookupKnowledgeBase($args),
            default => ['error' => 'Unknown tool'],
        };
    }

    /**
     * Tool: Search products.
     */
    protected function toolSearchProducts(array $args): array
    {
        $query = $args['query'] ?? '';
        $limit = min($args['limit'] ?? 3, 3);
        $sortBy = $args['sort_by'] ?? 'relevance';

        Log::info('BaseAgent::toolSearchProducts', ['args' => $args, 'sort_by' => $sortBy]);

        PipelineTracer::current()?->step('base_agent.search_start', [
            'query' => $query,
            'gpt_args' => $args,
            'sort_by' => $sortBy,
        ]);

        $filters = [];
        if (! empty($args['price_min'])) {
            $filters['price_min'] = (float) $args['price_min'];
        }
        if (! empty($args['price_max'])) {
            $filters['price_max'] = (float) $args['price_max'];
        }
        if (isset($args['min_age_months']) && is_numeric($args['min_age_months'])) {
            $filters['min_age_months'] = (int) $args['min_age_months'];
        }
        if (isset($args['max_age_months']) && is_numeric($args['max_age_months'])) {
            $filters['max_age_months'] = (int) $args['max_age_months'];
        }
        if (! empty($args['brand'])) {
            $filters['brand'] = $args['brand'];
        }
        if (! empty($args['color'])) {
            $filters['color'] = $args['color'];
        }

        // Fallback: detect color from original user message if GPT didn't pass it.
        // GPT sometimes omits `color` param even when user explicitly asks for e.g. "чорну плитоноску",
        // which leads to irrelevant results (products with color=Мультикам returned for "чорний" query).
        if (empty($filters['color']) && ! empty($this->currentMessage)) {
            try {
                $colorService = app(\App\Services\Search\ColorService::class);
                $detectedColor = $colorService->detectColor($this->currentMessage);
                if ($detectedColor) {
                    $filters['color'] = $detectedColor;
                    Log::info('BaseAgent: injected color from user message', [
                        'color' => $detectedColor,
                        'current_message' => mb_substr($this->currentMessage, 0, 100),
                        'gpt_query' => $query,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('BaseAgent: color detection failed', ['error' => $e->getMessage()]);
            }
        }

        // Only pass GPT-supplied category for age-based stores (children's).
        // For non-age stores, GPT often picks wrong categories for abstract queries
        // ("на весну" → "Футболки", "на вологу погоду" → "Level 7") which breaks search.
        // Meilisearch handles relevance fine without category filter.
        //
        // IMPORTANT: Validate GPT category against the CURRENT user message.
        // GPT often carries over category from conversation history (e.g. user asked about
        // "подарунок на 1 рік" → малюкам, then "а щось про космос" → GPT still sends малюкам).
        // Only trust GPT category if the current message actually contains age markers.
        if (! empty($args['category']) && $this->hasAgeCategories()) {
            $messageHasAge = ! empty($this->currentMessage)
                && $this->searchTool->detectAgeCategoryFromQuery($this->currentMessage) !== null;
            if ($messageHasAge) {
                $filters['category'] = $args['category'];
            } else {
                Log::info('BaseAgent: ignoring GPT category (no age markers in current message)', [
                    'gpt_category' => $args['category'],
                    'current_message' => mb_substr($this->currentMessage ?? '', 0, 100),
                    'query' => $query,
                ]);
            }
        }

        // If GPT didn't pass category, detect age from original user message
        // GPT sometimes strips age info from query, so we check the original message too
        // Only for stores with age categories (children's stores)
        if (empty($filters['category']) && ! empty($this->currentMessage) && $this->hasAgeCategories()) {
            $detectedCategory = $this->searchTool->detectAgeCategoryFromQuery($this->currentMessage);
            if ($detectedCategory) {
                $filters['category'] = $detectedCategory;
                PipelineTracer::current()?->step('base_agent.age_category_injected', [
                    'source' => 'currentMessage',
                    'detected_category' => $detectedCategory,
                    'current_message' => mb_substr($this->currentMessage, 0, 100),
                ]);
                Log::info('BaseAgent: injected age category from original user message', [
                    'original_message' => $this->currentMessage,
                    'gpt_query' => $query,
                    'detected_category' => $detectedCategory,
                ]);
            }
        }

        if ($sortBy !== 'relevance') {
            $filters['sort_by'] = $sortBy;
        }

        // Pass season filter for seasonal queries
        if (! empty($args['season'])) {
            $filters['season'] = $args['season'];
        }

        // Fallback: detect season from original user message if GPT didn't pass it
        // GPT sometimes ignores season param and sends non-seasonal query (e.g. "підсумок" for "що підійде навесні")
        if (empty($filters['season']) && ! empty($this->currentMessage)) {
            $detectedSeason = $this->detectSeasonFromMessage($this->currentMessage);
            if ($detectedSeason) {
                $filters['season'] = $detectedSeason;
                Log::info('BaseAgent: injected season from original user message', [
                    'season' => $detectedSeason,
                    'current_message' => $this->currentMessage,
                    'gpt_query' => $query,
                ]);
            }
        }

        // Pass original user message so MeiliProductSearchTool can detect boundary ages
        // (GPT often strips age info from the query parameter)
        if (! empty($this->currentMessage)) {
            $filters['_user_message'] = $this->currentMessage;
        }

        // For follow-up queries ("дорожче", "дешевше"), override _user_message with
        // the saved context message that contains the original age/category info.
        // This ensures MeiliProductSearchTool re-applies age filters correctly.
        if (! empty($args['_context_message'])) {
            $filters['_user_message'] = $args['_context_message'];
            Log::info('BaseAgent: using saved context message for age detection', [
                'context_message' => $args['_context_message'],
                'current_message' => $this->currentMessage,
            ]);
        }

        // Request more to have room after filtering and deduplication
        $requestLimit = $limit * 5 + count($this->shownProductIds);

        PipelineTracer::current()?->step('base_agent.search_call', [
            'query' => $query,
            'filters' => $filters,
            'request_limit' => $requestLimit,
        ]);

        $results = $this->searchTool->search($query, $filters, $requestLimit);

        // Filter by exclude text
        if (! empty($args['exclude']) && ! empty($results)) {
            $exclude = mb_strtolower($args['exclude']);
            $results = array_filter($results, fn ($p) => ! str_contains(mb_strtolower($p['title'] ?? ''), $exclude));
            $results = array_values($results);
        }

        // NOTE: Removed product_type filter - it was too aggressive and filtered out
        // valid results. Meilisearch relevance scoring handles this better.
        // MinimalAgent doesn't have this filter and works better.

        // Filter by color (only if not already passed to MeiliProductSearchTool via $filters)
        // MeiliProductSearchTool has comprehensive color filtering with color_norm + postFilterByColor + synonyms.
        // BaseAgent color filter is a fallback for when color was NOT passed to Meili.
        if (! empty($args['color']) && empty($filters['color']) && ! empty($results)) {
            $color = mb_strtolower($args['color']);
            // Normalize common color variants: мультикам/мультікам/multicam
            $colorVariants = [$color];
            if (str_contains($color, 'мультикам') || str_contains($color, 'мультікам') || str_contains($color, 'multicam')) {
                $colorVariants = ['мультикам', 'мультікам', 'multicam'];
            }
            $results = array_filter($results, function ($p) use ($colorVariants) {
                $haystack = mb_strtolower(($p['title'] ?? '').' '.($p['color'] ?? ''));
                foreach ($colorVariants as $variant) {
                    if (str_contains($haystack, $variant)) {
                        return true;
                    }
                }

                return false;
            });
            $results = array_values($results);
        }

        // Exclude already shown products ONLY if explicitly requested (for "покажи ще")
        // By default, allow showing same products again (for repeated queries)
        $excludeShown = $args['exclude_shown'] ?? false;
        $beforeExcludeCount = count($results);
        if ($excludeShown && ! empty($this->shownProductIds) && ! empty($results)) {
            $results = array_filter($results, fn ($p) => ! in_array((int) ($p['id'] ?? 0), $this->shownProductIds));
            $results = array_values($results);

            Log::info('BaseAgent::toolSearchProducts exclude_shown filter', [
                'exclude_shown' => $excludeShown,
                'shown_ids_count' => count($this->shownProductIds),
                'shown_ids' => array_slice($this->shownProductIds, 0, 5),
                'before_filter' => $beforeExcludeCount,
                'after_filter' => count($results),
            ]);
        } else {
            Log::info('BaseAgent::toolSearchProducts exclude_shown NOT applied', [
                'exclude_shown' => $excludeShown,
                'shown_ids_count' => count($this->shownProductIds),
                'results_count' => count($results),
            ]);
        }

        PipelineTracer::current()?->step('base_agent.before_accessory_filter', [
            'results_count' => count($results),
            'result_ids' => array_column(array_slice($results, 0, 5), 'id'),
            'result_titles' => array_map(fn ($p) => mb_substr($p['title'] ?? '', 0, 50), array_slice($results, 0, 5)),
        ]);

        // Filter accessories when searching for main product types (шоломи, плитоноски, etc.)
        // This is a safety net in case MeiliProductSearchTool filters don't work
        $results = $this->filterAccessoriesFromResults($results, $query);

        PipelineTracer::current()?->step('base_agent.after_accessory_filter', [
            'results_count' => count($results),
            'result_ids' => array_column(array_slice($results, 0, 5), 'id'),
        ]);

        // Add variety: shuffle top results before final slice so repeated queries return different products
        if (count($results) > $limit) {
            $poolSize = min(count($results), $limit * 3);
            $pool = array_slice($results, 0, $poolSize);
            shuffle($pool);
            $results = array_merge($pool, array_slice($results, $poolSize));
        }

        $results = array_slice($results, 0, $limit);

        PipelineTracer::current()?->step('base_agent.search_results', [
            'results_count' => count($results),
            'result_titles' => array_map(fn ($p) => mb_substr($p['title'] ?? '', 0, 40), array_slice($results, 0, 3)),
            'result_categories' => array_map(fn ($p) => $p['category_path'] ?? '', array_slice($results, 0, 3)),
            'search_meta' => $this->searchTool->getSearchMeta(),
        ]);

        // Get full product cards with images
        if (! empty($results)) {
            $ids = array_column($results, 'id');
            $tenantId = $this->searchTool->getCurrentTenantId();
            $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
            if (! empty($cards)) {
                $results = $cards;
            }
        }

        // Check if gender attribute was requested but not found in results
        $queryLower = mb_strtolower($query);
        $genderKeywords = ['жіноча', 'жіночий', 'жіночі', 'women', 'female', 'чоловіча', 'чоловічий', 'men', 'male', 'дитяча', 'дитячий', 'kids', 'children'];
        $requestedGender = null;
        foreach ($genderKeywords as $keyword) {
            if (str_contains($queryLower, $keyword)) {
                $requestedGender = $keyword;
                break;
            }
        }

        if ($requestedGender && ! empty($results)) {
            $foundInResults = false;
            foreach ($results as $p) {
                $titleLower = mb_strtolower($p['title'] ?? '');
                $descLower = mb_strtolower($p['description'] ?? '');
                if (str_contains($titleLower, $requestedGender) || str_contains($descLower, $requestedGender)) {
                    $foundInResults = true;
                    break;
                }
            }
            if (! $foundInResults) {
                // Return empty results with message so GPT knows to say "not found"
                Log::info('Gender attribute not found in results', ['requested' => $requestedGender, 'query' => $query]);

                return [
                    'products' => [],
                    'count' => 0,
                    'query' => $query,
                    'message' => "На жаль, '{$requestedGender}' варіанту цього товару немає в асортименті. Є універсальні варіанти - запропонуй їх користувачу.",
                ];
            }
        }

        Log::info('BaseAgent::toolSearchProducts FINAL result', [
            'query' => $query,
            'filters' => $filters,
            'exclude_shown' => $excludeShown,
            'results_count' => count($results),
            'result_ids' => array_column(array_slice($results, 0, 5), 'id'),
            'result_titles' => array_map(fn ($p) => mb_substr($p['title'] ?? '', 0, 40), array_slice($results, 0, 3)),
        ]);

        // TENANT-SPECIFIC final guard (applies to GPT tool-call path too).
        // Strips PDFs / certificates / gift packaging / parent care tools from T20 results
        // unless user's query explicitly requests them. No-op for other tenants.
        $contextMessage = $args['_context_message'] ?? ($this->currentMessage ?? $query);
        $tenantId = $this->searchTool->getCurrentTenantId();
        $beforeTenantFilter = count($results);
        $results = $this->filterTenantBabyQueryProducts($results, (string) $contextMessage, $tenantId, $this->activeSessionId);

        // Deduplicate variants of the same parent (prevents showing "2 Такане різного принту").
        $results = $this->dedupByParentArticle($results);

        if ($beforeTenantFilter !== count($results)) {
            Log::info('BaseAgent::toolSearchProducts tenant filter applied', [
                'tenant_id' => $tenantId,
                'before' => $beforeTenantFilter,
                'after' => count($results),
            ]);
        }

        return ['products' => $results, 'count' => count($results), 'query' => $query];
    }

    /**
     * Filter out accessory products when user is searching for main products.
     * Safety net for when MeiliProductSearchTool's filterAccessories doesn't work.
     */
    protected function filterAccessoriesFromResults(array $results, string $query): array
    {
        if (empty($results)) {
            return $results;
        }

        $queryLower = mb_strtolower($query);

        // Only filter for main product queries
        // Note: \b word boundaries don't work with Cyrillic, so we use simple contains
        if (! preg_match('/(шолом|каска|helmet|плитоноск|plate\s*carrier|бронежилет)/ui', $queryLower)) {
            return $results;
        }

        // Accessory patterns in titles
        $accessoryPatterns = [
            'кріплення', 'адаптер', 'планка', 'подушк', 'противаг',
            'кавер', 'чохол', 'велкро', 'панел', 'тримач',
            'маска', 'візор', 'visor', 'mount', 'adapter', 'cover',
            'pad', 'panel', 'strap', 'clip', 'rail', 'ліхтар',
            'захист обличчя', 'захист нижньої', 'кишен', 'pouch',
            'навушник', 'peltor', 'earmor', 'headset',
            'набір', 'комплект монтаж', 'система захист', 'липучк',
            'нейлонов', 'платформ', 'чебурашк',
        ];

        // Filter products
        $mainProducts = [];
        $accessories = [];

        foreach ($results as $product) {
            $title = mb_strtolower($product['title'] ?? '');
            $category = mb_strtolower($product['category_path'] ?? '');

            $isAccessory = false;

            // Check category first
            if (str_contains($category, 'аксесуар') || str_contains($category, 'комплектуюч')) {
                $isAccessory = true;
            }

            // Check title patterns
            if (! $isAccessory) {
                foreach ($accessoryPatterns as $pattern) {
                    if (str_contains($title, $pattern)) {
                        $isAccessory = true;
                        break;
                    }
                }
            }

            if ($isAccessory) {
                $accessories[] = $product;
            } else {
                $mainProducts[] = $product;
            }
        }

        Log::info('BaseAgent::filterAccessoriesFromResults', [
            'query' => $query,
            'total' => count($results),
            'main' => count($mainProducts),
            'accessories' => count($accessories),
            'main_titles' => array_map(fn ($p) => $p['title'] ?? '', array_slice($mainProducts, 0, 5)),
        ]);

        // Return main products if we have any, otherwise return all
        return ! empty($mainProducts) ? $mainProducts : $results;
    }

    /**
     * Tool: Get popular products.
     */
    protected function toolGetPopularProducts(array $args): array
    {
        $category = $args['category'] ?? null;
        $limit = min($args['limit'] ?? 3, 3);
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'popular_products_v7:'.($tenantId ?? 'all').':'.($category ?? 'all').':'.$limit;

        return Cache::remember($cacheKey, 300, function () use ($category, $limit, $tenantId) {
            $products = [];

            $filterProduct = function ($p) {
                $size = strtolower($p['size'] ?? '');
                $title = strtolower($p['title'] ?? '');
                $price = (float) ($p['price'] ?? 0);
                if (preg_match('/\b(50|51|52|53|54|55|xxxl|xxxxl)\b/i', $size.' '.$title)) {
                    return false;
                }
                if ($price > 20000) {
                    return false;
                }
                if (! ($p['in_stock'] ?? false)) {
                    return false;
                }

                return true;
            };

            // Check for real sales data
            $salesQuery = Product::where('orders_count', '>', 0);
            if ($tenantId) {
                $salesQuery->where('tenant_id', $tenantId);
            }
            $hasOrdersData = $salesQuery->exists();

            if ($hasOrdersData) {
                $query = Product::where('in_stock', true)->where('orders_count', '>', 0)->where('quantity', '>', 0);
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }
                if ($category) {
                    $query->where(function ($q) use ($category) {
                        $q->where('category_path', 'like', "%{$category}%")
                            ->orWhere('title', 'like', "%{$category}%")
                            ->orWhere('search_index', 'like', "%{$category}%");
                    });
                }
                $topSellers = $query->orderBy('orders_count', 'desc')->take($limit * 3)->get();

                foreach ($topSellers as $p) {
                    $item = [
                        'id' => $p->id, 'article' => $p->article, 'title' => $p->title,
                        'price' => $p->price, 'in_stock' => $p->in_stock, 'size' => $p->size,
                        'orders_count' => $p->orders_count, 'popularity' => $p->popularity,
                    ];
                    if ($filterProduct($item)) {
                        $products[] = $item;
                    }
                    if (count($products) >= $limit) {
                        break;
                    }
                }
            }

            // Fallback: use dynamic categories from tenant's catalog
            if (count($products) < $limit) {
                if ($category) {
                    $results = $this->searchTool->search($category, [], $limit * 3);
                    $results = array_filter($results, $filterProduct);
                    usort($results, fn ($a, $b) => (($b['popularity'] ?? 0) + (($b['orders_count'] ?? 0) * 10)) <=> (($a['popularity'] ?? 0) + (($a['orders_count'] ?? 0) * 10)));
                    $existingIds = array_column($products, 'id');
                    foreach ($results as $r) {
                        if (! in_array($r['id'], $existingIds)) {
                            $products[] = $r;
                            if (count($products) >= $limit) {
                                break;
                            }
                        }
                    }
                } else {
                    // Get top categories dynamically from tenant's products
                    $popularQueries = $this->getTopCategoriesForTenant($tenantId, 5);

                    // If no categories found, use generic fallback
                    if (empty($popularQueries)) {
                        $popularQueries = ['товар', 'новинка', 'акція'];
                    }

                    $existingIds = array_column($products, 'id');
                    foreach ($popularQueries as $q) {
                        $results = $this->searchTool->search($q, [], 10);
                        $results = array_filter($results, $filterProduct);
                        if (! empty($results)) {
                            usort($results, fn ($a, $b) => abs(($a['price'] ?? 0) - 3000) <=> abs(($b['price'] ?? 0) - 3000));
                            $best = array_values($results)[0];
                            if (! in_array($best['id'], $existingIds)) {
                                $products[] = $best;
                                $existingIds[] = $best['id'];
                            }
                        }
                        if (count($products) >= $limit) {
                            break;
                        }
                    }
                }
            }

            // Last resort: just get any in-stock products from tenant
            if (count($products) < $limit) {
                $query = Product::where('in_stock', true)->where('quantity', '>', 0);
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }
                $anyProducts = $query->orderByDesc('updated_at')->take(($limit - count($products)) * 2)->get();

                $existingIds = array_column($products, 'id');
                foreach ($anyProducts as $p) {
                    $item = [
                        'id' => $p->id, 'article' => $p->article, 'title' => $p->title,
                        'price' => $p->price, 'in_stock' => $p->in_stock, 'size' => $p->size,
                        'orders_count' => $p->orders_count ?? 0, 'popularity' => $p->popularity ?? 0,
                    ];
                    if ($filterProduct($item) && ! in_array($p->id, $existingIds)) {
                        $products[] = $item;
                        $existingIds[] = $p->id;
                    }
                    if (count($products) >= $limit) {
                        break;
                    }
                }
            }

            // Get full cards with images
            if (! empty($products)) {
                $ids = array_column($products, 'id');
                $tenantId = $this->searchTool->getCurrentTenantId();
                $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
                if (! empty($cards)) {
                    $products = $cards;
                }
            }

            return ['products' => array_slice($products, 0, $limit), 'count' => count($products)];
        });
    }

    /**
     * Get top categories for a tenant to use as fallback queries.
     */
    protected function getTopCategoriesForTenant(?int $tenantId, int $limit = 5): array
    {
        $cacheKey = 'tenant_top_categories:'.($tenantId ?? 'all').':'.$limit;

        return Cache::remember($cacheKey, 600, function () use ($tenantId, $limit) {
            $query = Product::where('in_stock', true);
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            $categories = $query
                ->selectRaw('category_path, count(*) as cnt')
                ->whereNotNull('category_path')
                ->where('category_path', '!=', '')
                ->groupBy('category_path')
                ->orderByDesc('cnt')
                ->limit($limit * 2)
                ->pluck('category_path')
                ->toArray();

            // Extract last segment of category path as search query
            $queries = [];
            foreach ($categories as $cat) {
                $parts = explode('/', $cat);
                $lastPart = trim(end($parts));
                if (! empty($lastPart) && mb_strlen($lastPart) > 2 && ! in_array($lastPart, $queries)) {
                    $queries[] = $lastPart;
                }
                if (count($queries) >= $limit) {
                    break;
                }
            }

            return $queries;
        });
    }

    /**
     * Tool: Get product details.
     */
    protected function toolGetProductDetails(array $args): array
    {
        $article = $args['article'] ?? '';
        if (empty($article)) {
            return ['error' => 'Article required'];
        }

        $tenantId = $this->searchTool->getCurrentTenantId();
        $query = Product::where('article', $article);
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        $product = $query->first();
        if (! $product) {
            return ['error' => 'Product not found'];
        }

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
     * Tool: Get order status.
     */
    protected function toolGetOrderStatus(array $args): array
    {
        $orderId = $args['order_id'] ?? null;
        $phone = $args['phone'] ?? null;

        // Check if order search is available
        if (! $this->orderSearchService->isAvailable()) {
            return ['error' => 'Пошук замовлень тимчасово недоступний.'];
        }

        try {
            $result = $this->orderSearchService->search([
                'order_id' => $orderId,
                'phone' => $phone,
                'limit' => 5,
            ]);

            if (! empty($result['orders'])) {
                return $result['total'] === 1
                    ? ['order' => $result['orders'][0]]
                    : ['orders' => $result['orders'], 'count' => $result['total']];
            }

            return ['error' => 'Замовлення не знайдено. Перевірте номер або телефон.'];
        } catch (\Exception $e) {
            Log::error('toolGetOrderStatus error', ['error' => $e->getMessage()]);

            return ['error' => 'Не вдалося перевірити замовлення. Спробуйте пізніше.'];
        }
    }

    /**
     * Tool: Get categories.
     */
    protected function toolGetCategories(): array
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'categories_list:'.($tenantId ?? 'all');

        return Cache::remember($cacheKey, 3600, function () use ($tenantId) {
            $query = Category::whereNotNull('path');
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            $categories = $query->orderBy('path')->pluck('path')->unique()->values()->toArray();

            return ['categories' => $categories, 'count' => count($categories)];
        });
    }

    /**
     * Tool: Get brands.
     */
    protected function toolGetBrands(array $args): array
    {
        $category = $args['category'] ?? null;
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'brands_list:'.($tenantId ?? 'all').':'.($category ?? 'all');

        return Cache::remember($cacheKey, 3600, function () use ($category, $tenantId) {
            $query = Brand::query();
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            if ($category) {
                $query->where('categories', 'like', "%{$category}%");
            }
            $brands = $query->orderBy('name')->pluck('name')->toArray();

            return ['brands' => $brands, 'count' => count($brands)];
        });
    }

    /**
     * Tool: Get available sizes for a product.
     */
    protected function toolGetAvailableSizes(array $args): array
    {
        $article = $args['article'] ?? '';
        if (empty($article)) {
            return ['error' => 'Article required'];
        }

        $tenantId = $this->searchTool->getCurrentTenantId();
        $query = Product::query();
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        // Find main product
        $product = (clone $query)->where('article', $article)->first();
        if (! $product) {
            $product = (clone $query)->where('id', $article)->first();
        }
        if (! $product) {
            return ['error' => 'Product not found'];
        }

        // Find all size variants
        $parentArticle = $product->parent_article ?? $product->article;
        $variants = (clone $query)
            ->where(function ($q) use ($parentArticle, $product) {
                $q->where('parent_article', $parentArticle)
                    ->orWhere('article', $parentArticle)
                    ->orWhere('parent_article', $product->article);
            })
            ->where('in_stock', true)
            ->where('quantity', '>', 0)
            ->orderBy('size')
            ->get();

        $sizes = $variants->map(fn ($v) => [
            'size' => $v->size,
            'article' => $v->article,
            'quantity' => $v->quantity,
            'price' => $v->price,
        ])->values()->toArray();

        return [
            'product' => $product->title,
            'sizes' => $sizes,
            'count' => count($sizes),
        ];
    }

    /**
     * Tool: Recommend size based on measurements.
     */
    protected function toolRecommendSize(array $args): array
    {
        $article = $args['article'] ?? '';
        $height = $args['height'] ?? null;
        $weight = $args['weight'] ?? null;
        $chest = $args['chest'] ?? null;
        $waist = $args['waist'] ?? null;

        // If article provided, get available sizes first
        $availableSizes = [];
        $productTitle = 'ECWCS одяг';

        if (! empty($article)) {
            $sizesResult = $this->toolGetAvailableSizes(['article' => $article]);
            if (isset($sizesResult['error'])) {
                // Article not found, continue without it - just recommend ECWCS size
                $availableSizes = [];
            } else {
                $availableSizes = $sizesResult['sizes'] ?? [];
                $productTitle = $sizesResult['product'] ?? 'товар';
            }
        }

        // Estimate chest from weight if not provided (rough formula for tactical gear)
        // Average male: weight/height ratio affects chest size
        if (! $chest && $weight && $height) {
            // BMI-based chest estimation for men
            $bmi = $weight / (($height / 100) ** 2);
            // Rough estimation: BMI 18-22 = ~90-95cm, 22-26 = ~100-105cm, 26-30 = ~110-115cm, 30+ = ~120+cm
            if ($bmi < 22) {
                $chest = 90 + ($bmi - 18) * 2;
            } elseif ($bmi < 26) {
                $chest = 98 + ($bmi - 22) * 3;
            } elseif ($bmi < 30) {
                $chest = 110 + ($bmi - 26) * 3;
            } else {
                $chest = 122 + ($bmi - 30) * 2;
            }
            $chest = (int) round($chest);
        }

        // If only weight provided, estimate chest directly
        if (! $chest && $weight) {
            // Simple formula: chest ≈ weight * 1.05 + 10 (rough average for men)
            // 70kg → ~84cm, 85kg → ~99cm, 100kg → ~115cm, 115kg → ~131cm
            $chest = (int) round($weight * 1.05 + 10);
        }

        // ECWCS size chart (US Army standard)
        $ecwcsSizes = [
            'XS/XS' => ['height' => [150, 157], 'chest' => [79, 86], 'waist' => [64, 71]],
            'XS/S' => ['height' => [157, 165], 'chest' => [79, 86], 'waist' => [64, 71]],
            'S/XS' => ['height' => [150, 157], 'chest' => [86, 94], 'waist' => [71, 79]],
            'S/S' => ['height' => [157, 165], 'chest' => [86, 94], 'waist' => [71, 79]],
            'S/R' => ['height' => [165, 175], 'chest' => [86, 94], 'waist' => [71, 79]],
            'S/L' => ['height' => [175, 183], 'chest' => [86, 94], 'waist' => [71, 79]],
            'M/S' => ['height' => [157, 165], 'chest' => [94, 102], 'waist' => [79, 86]],
            'M/R' => ['height' => [165, 175], 'chest' => [94, 102], 'waist' => [79, 86]],
            'M/L' => ['height' => [175, 183], 'chest' => [94, 102], 'waist' => [79, 86]],
            'L/S' => ['height' => [157, 165], 'chest' => [102, 112], 'waist' => [86, 94]],
            'L/R' => ['height' => [165, 175], 'chest' => [102, 112], 'waist' => [86, 94]],
            'L/L' => ['height' => [175, 183], 'chest' => [102, 112], 'waist' => [86, 94]],
            'XL/R' => ['height' => [165, 175], 'chest' => [112, 122], 'waist' => [94, 102]],
            'XL/L' => ['height' => [175, 183], 'chest' => [112, 122], 'waist' => [94, 102]],
            'XXL/R' => ['height' => [165, 175], 'chest' => [122, 132], 'waist' => [102, 112]],
            'XXL/L' => ['height' => [175, 183], 'chest' => [122, 132], 'waist' => [102, 112]],
        ];

        // Find best matching size
        $recommendation = null;
        $bestScore = PHP_INT_MAX;
        $warnings = [];

        // If we have specific available sizes, filter by them. Otherwise check all ECWCS sizes.
        $hasAvailableSizes = ! empty($availableSizes);

        foreach ($ecwcsSizes as $size => $ranges) {
            $score = 0;

            // Check if this size is available (only filter if we have specific sizes)
            if ($hasAvailableSizes) {
                $sizePrefix = explode('/', $size)[0]; // Get XS, S, M, L, XL, XXL part
                $availableSize = collect($availableSizes)->first(function ($s) use ($sizePrefix, $size) {
                    $sizeStr = $s['size'] ?? '';

                    // Match exact size or prefix match
                    return stripos($sizeStr, $size) !== false || stripos($sizeStr, $sizePrefix) !== false;
                });
                if (! $availableSize) {
                    continue;
                }
            }

            // Calculate score - lower is better
            // Chest/body size is MOST important (weight = 4)
            if ($chest) {
                $chestMid = ($ranges['chest'][0] + $ranges['chest'][1]) / 2;
                // Penalize heavily if chest is larger than max range
                if ($chest > $ranges['chest'][1]) {
                    $score += ($chest - $ranges['chest'][1]) * 5; // Heavy penalty for too small
                } else {
                    $score += abs($chest - $chestMid) * 4;
                }
            }

            // Height is secondary (weight = 2)
            if ($height) {
                $heightMid = ($ranges['height'][0] + $ranges['height'][1]) / 2;
                $score += abs($height - $heightMid) * 2;
            }

            // Waist if provided (weight = 3)
            if ($waist) {
                $waistMid = ($ranges['waist'][0] + $ranges['waist'][1]) / 2;
                if ($waist > $ranges['waist'][1]) {
                    $score += ($waist - $ranges['waist'][1]) * 4;
                } else {
                    $score += abs($waist - $waistMid) * 3;
                }
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $recommendation = $size;
            }
        }

        // Add warnings
        if ($chest && $chest > 130) {
            $warnings[] = 'Великий обхват грудей — рекомендуємо уточнити у менеджера';
        }
        if ($weight && $weight > 110) {
            $warnings[] = 'При вазі 110+ кг можливо знадобиться XXL — зверніть увагу на таблицю розмірів';
        }

        // Calculate estimated chest for response
        $estimatedChest = $chest;

        // Default to XL/L for heavy people without better match
        if (! $recommendation && $weight && $weight >= 100) {
            $recommendation = 'XL/L';
        } elseif (! $recommendation && $weight && $weight >= 85) {
            $recommendation = 'L/L';
        }

        // Extract base size (XS, S, M, L, XL, XXL) from ECWCS notation (XL/L -> XL)
        $baseSize = $recommendation ? explode('/', $recommendation)[0] : 'L';

        // Build user-friendly size explanation
        $sizeExplanation = match ($baseSize) {
            'XS' => 'XS (або XS-Short, XS-Regular)',
            'S' => 'S (або S-Short, S-Regular, S-Long)',
            'M' => 'M (або M-Short, M-Regular, M-Long)',
            'L' => 'L (або L-Short, L-Regular, L-Long)',
            'XL' => 'XL (або XL-Regular, XL-Long)',
            'XXL' => 'XXL (або XXL-Regular, XXL-Long)',
            default => $baseSize,
        };

        $result = [
            'status' => 'success',
            'message' => "Рекомендований розмір: {$sizeExplanation}. По системі ECWCS (американська): {$recommendation}",
            'product' => $productTitle,
            'recommended_size' => $recommendation ?? 'L/R',
            'simple_size' => $baseSize,
            'size_explanation' => $sizeExplanation,
            'available_sizes' => $availableSizes,
            'warnings' => $warnings,
            'estimated_chest' => $estimatedChest ? "~{$estimatedChest} см (оцінка за вагою)" : null,
            'note' => "Якщо товар має просту розмітку (S, M, L, XL) — обирайте {$baseSize}. Якщо є позначення росту (Short/Regular/Long або S/R/L) — обирайте {$recommendation}.",
        ];

        return $result;
    }

    /**
     * Tool: lookup tenant knowledge base (FAQ, scripts, product hints).
     */
    protected function toolLookupKnowledgeBase(array $args): array
    {
        $tenantId = $this->searchTool->getCurrentTenantId();

        return $this->knowledgeLookupTool->lookup($args, $tenantId);
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    /**
     * Strip URLs, markdown links, and generic phrases from GPT text output.
     */
    protected function stripUrlsFromText(string $text): string
    {
        // Remove markdown links with known "action" anchors — drop entirely
        $actionAnchors = ['Детальніше', 'Переглянути', 'Дивитись', 'Купити', 'Замовити', 'View', 'Buy', 'Order', 'See more'];
        foreach ($actionAnchors as $anchor) {
            $text = preg_replace('/\['.preg_quote($anchor, '/').'\]\(https?:\/\/[^\)]+\)/ui', '', $text);
        }

        // Markdown links with descriptive text — keep text, drop URL
        $text = preg_replace('/\[([^\]]+)\]\(https?:\/\/[^\)]+\)/u', '$1', $text);

        // Standalone bracket text without URL (e.g. [Переглянути])
        $text = preg_replace('/\[('.implode('|', array_map(fn ($a) => preg_quote($a, '/'), $actionAnchors)).')\]/ui', '', $text);

        // Bare URLs
        $text = preg_replace('/https?:\/\/\S+/u', '', $text);

        // Generic "Ось що я знайшов" phrases
        $text = preg_replace('/Ось що я знайшов[^:.\n]*[:.!]?\s*/ui', '', $text);
        $text = preg_replace("/Here'?s what I found[^:.\n]*[:.!]?\s*/ui", '', $text);

        // Clean up extra whitespace
        $text = preg_replace('/  +/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Strip link/slug fields from product data before sending to GPT (prevents URL hallucination).
     */
    protected function stripLinksForGpt(array $data): array
    {
        $stripProduct = function (array $product): array {
            unset($product['link'], $product['slug']);
            if (! empty($product['size_variants'])) {
                $product['size_variants'] = array_map(function (array $v): array {
                    unset($v['link']);

                    return $v;
                }, $product['size_variants']);
            }

            return $product;
        };

        if (! empty($data['products']) && is_array($data['products'])) {
            $data['products'] = array_map($stripProduct, $data['products']);
        }

        if (! empty($data['product']) && is_array($data['product'])) {
            $data['product'] = $stripProduct($data['product']);
        }

        return $data;
    }

    /**
     * Extract products mentioned by article or title in GPT plain-text response
     * and look them up in the database.
     *
     * Handles patterns like: "арт. 107", "(арт. ABC-1)", "article: ABC-1"
     * Also tries to find products by name from numbered lists when no articles found.
     *
     * @return array{products: array}|null
     */
    protected function extractProductsFromTextResponse(string $text, ?int $tenantId): ?array
    {
        // Step 1: Match article patterns: "арт. XXX", "(арт. XXX)", "article: XXX", "артикул XXX"
        if (preg_match_all('/(?:арт(?:икул)?[\.\s:]*|article[\s:]+)([a-zA-Z0-9\-_]+)/ui', $text, $matches)) {
            $articles = array_unique($matches[1]);
            if (! empty($articles)) {
                $query = Product::whereIn('article', $articles);
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }
                $dbProducts = $query->get();

                if ($dbProducts->isNotEmpty()) {
                    return ['products' => $this->formatProductCards($dbProducts)];
                }
            }
        }

        // Step 2: Try to extract product names from numbered lists
        // Matches: "1. Product Name", "2. Product Name - description", "- Product Name"
        if (preg_match_all('/(?:^\s*\d+[\.\)]\s*\*{0,2}|^\s*[-•]\s*\*{0,2})(.+?)(?:\*{0,2}\s*[-–—\n]|$)/um', $text, $nameMatches)) {
            $names = array_map(fn ($n) => trim(preg_replace('/[\*\n]/', '', $n)), $nameMatches[1]);
            $names = array_filter($names, fn ($n) => mb_strlen($n) >= 3 && mb_strlen($n) <= 100);

            if (! empty($names)) {
                $foundProducts = collect();
                $query = Product::query();
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }
                $query->where('in_stock', true);

                foreach ($names as $name) {
                    $clean = mb_strtolower(trim($name));
                    $found = (clone $query)->whereRaw('LOWER(title) LIKE ?', ["%{$clean}%"])->first();
                    if ($found) {
                        $foundProducts->push($found);
                    }
                }

                if ($foundProducts->isNotEmpty()) {
                    Log::info('extractProductsFromTextResponse: found products by title', [
                        'names' => $names,
                        'found' => $foundProducts->pluck('title')->toArray(),
                    ]);

                    return ['products' => $this->formatProductCards($foundProducts)];
                }
            }
        }

        return null;
    }

    /**
     * Format product models into card arrays with images.
     */
    protected function formatProductCards($dbProducts): array
    {
        $products = [];
        foreach ($dbProducts as $product) {
            $images = $this->extractProductImages($product);
            $products[] = [
                'id' => $product->id,
                'article' => $product->article,
                'title' => $product->title,
                'price' => $product->price,
                'price_old' => $product->price_old,
                'in_stock' => $product->in_stock,
                'category_path' => $product->category_path,
                'brand' => $product->brand,
                'images' => $images,
            ];
        }

        return $products;

        return ['products' => $products];
    }

    /**
     * Extract images from product.
     */
    protected function extractProductImages(Product $product): array
    {
        $images = [];

        // 1. Try raw['pictures'] (Horoshop format)
        if ($product->raw && is_array($product->raw) && ! empty($product->raw['pictures'])) {
            $images = collect($product->raw['pictures'])
                ->map(fn ($pic) => is_array($pic) ? ($pic['url'] ?? null) : $pic)
                ->filter()->values()->toArray();
        }

        // 2. Try raw['images']
        if (empty($images) && $product->raw && is_array($product->raw) && ! empty($product->raw['images'])) {
            $imgs = $product->raw['images'];
            if (is_array($imgs)) {
                $images = collect($imgs)
                    ->map(fn ($img) => is_array($img) ? ($img['url'] ?? $img['src'] ?? null) : $img)
                    ->filter()->values()->toArray();
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
            if (! empty($product->raw['image'])) {
                $images = [$product->raw['image']];
            } elseif (! empty($product->raw['main_image'])) {
                $images = [$product->raw['main_image']];
            }
        }

        return $images;
    }

    /**
     * Get product type synonyms from DB.
     * Includes both tenant-specific and global synonyms.
     */
    protected function getProductTypeSynonyms(string $productType): array
    {
        $tenantId = $this->searchTool->getCurrentTenantId();
        $cacheKey = 'product_type_synonyms:'.($tenantId ?? 'global').':'.md5($productType);

        return Cache::remember($cacheKey, 3600, function () use ($productType, $tenantId) {
            // Use forTenant() which returns tenant-specific + global (NULL) synonyms
            $synonyms = ProductSynonym::forTenant($tenantId)
                ->where(function ($q) use ($productType) {
                    $q->where('product_type', $productType)
                        ->orWhere('synonym', $productType);
                })
                ->pluck('synonym')
                ->toArray();

            return array_unique(array_merge([$productType], $synonyms));
        });
    }

    /**
     * Deduplicate products by ID.
     */
    protected function dedupeProducts(array $products): array
    {
        $seen = [];
        $result = [];

        foreach ($products as $product) {
            $id = $product['id'] ?? null;
            if ($id && ! isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $product;
            }
        }

        return $result;
    }

    /**
     * Detect if message is a trigger query.
     */
    protected function detectTriggerQuery(string $message): bool
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
                Log::info('BaseAgent: detected trigger query', ['message' => $message]);

                return true;
            }
        }

        return false;
    }

    /**
     * Detect season from user message text.
     * Uses same keywords as MeiliProductSearchTool::buildSeasonalQueryBoost.
     */
    protected function detectSeasonFromMessage(string $message): ?string
    {
        $messageLower = mb_strtolower(str_replace('-', ' ', $message));

        $seasonKeywords = [
            'winter' => ['зимов', 'зиму', 'зимою', 'взимку', 'мороз', 'холод', 'winter'],
            'spring' => ['весн', 'весною', 'навесні', 'spring'],
            'summer' => ['літн', 'літо', 'влітку', 'спек', 'жарк', 'summer'],
            'autumn' => ['осінн', 'осені', 'осінь', 'восени', 'autumn'],
        ];

        foreach ($seasonKeywords as $season => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($messageLower, $kw) !== false) {
                    return $season;
                }
            }
        }

        return null;
    }

    /**
     * Generate CTA outro for trigger queries.
     * Now checks if GPT already included a size question to avoid duplication.
     * Also detects "top products in category" queries to avoid irrelevant size/color questions.
     */
    protected function generateTriggerOutro(array $products, string $gptResponse = '', string $originalQuery = ''): string
    {
        if (empty($products)) {
            return 'Є питання? Допоможу з вибором!';
        }

        // For "top products in category" queries, don't ask about size/color - products are diverse!
        $lowerQuery = mb_strtolower($originalQuery);
        $isTopProductsQuery = str_contains($lowerQuery, 'топ товари')
            || str_contains($lowerQuery, 'популярні товари')
            || str_contains($lowerQuery, 'top products');

        $firstProduct = $products[0];
        $quantity = $firstProduct['quantity'] ?? 0;

        // Check if GPT already asked about size/color to avoid duplication
        $lowerResponse = mb_strtolower($gptResponse);
        $alreadyAskedSize = str_contains($lowerResponse, 'розмір') || str_contains($lowerResponse, 'size');
        $alreadyAskedColor = str_contains($lowerResponse, 'колір') || str_contains($lowerResponse, 'color');
        $alreadyAskedGeneric = str_contains($lowerResponse, 'цікавить щось конкретне')
            || str_contains($lowerResponse, 'є питання');

        // For diverse product queries (top products), use generic CTA
        if ($isTopProductsQuery || $alreadyAskedGeneric) {
            return 'Цікавить щось конкретне? Допоможу підібрати!';
        }

        $hasMultipleSizes = false;
        $hasMultipleColors = false;

        foreach ($products as $p) {
            if (! empty($p['size_variants']) && count($p['size_variants']) > 1) {
                $hasMultipleSizes = true;
            }
            if (! empty($p['color_variants']) && count($p['color_variants']) > 1) {
                $hasMultipleColors = true;
            }
        }

        if ($hasMultipleSizes && ! $alreadyAskedSize) {
            return 'Який розмір/варіант вам потрібен? Допоможу підібрати!';
        }
        if ($hasMultipleColors && ! $alreadyAskedColor) {
            return 'Який колір вам більше підходить?';
        }
        if ($quantity > 0 && $quantity <= 3) {
            return "Залишилось лише {$quantity} шт. в наявності. Є питання? Допоможу!";
        }

        return 'Є питання? Із задоволенням допоможу!';
    }

    /**
     * Parse GPT structured JSON response.
     */
    protected function parseStructuredResponse(string $responseText, array $allProducts): array
    {
        $json = null;

        // Extract JSON from response
        if (preg_match('/\{[\s\S]*\}/u', $responseText, $matches)) {
            $json = json_decode($matches[0], true);
        }

        // Build products by article index
        $productsByArticle = [];
        foreach ($allProducts as $p) {
            $productsByArticle[$p['article']] = $p;
        }

        // If valid JSON with products
        if ($json && isset($json['products']) && is_array($json['products'])) {
            $messages = [];

            if (! empty($json['intro'])) {
                $messages[] = ['type' => 'text', 'content' => $json['intro']];
            }

            $orderedProducts = [];
            foreach ($json['products'] as $item) {
                $article = $item['article'] ?? '';
                $comment = $item['comment'] ?? '';

                $product = $productsByArticle[$article] ?? null;
                if (! $product) {
                    foreach ($productsByArticle as $a => $p) {
                        if (str_contains($a, $article) || str_contains($article, $a)) {
                            $product = $p;
                            break;
                        }
                    }
                }

                if ($product) {
                    $messages[] = ['type' => 'product', 'product' => $product, 'comment' => $comment];
                    $orderedProducts[] = $product;
                }
            }

            if (! empty($json['outro'])) {
                $messages[] = ['type' => 'text', 'content' => $json['outro']];
            }

            return [
                'intro' => $json['intro'] ?? $this->generateContextualIntro($this->currentMessage ?? '', $allProducts),
                'outro' => $json['outro'] ?? null,
                'products' => ! empty($orderedProducts) ? $orderedProducts : array_slice($allProducts, 0, 5),
                'messages' => $messages,
            ];
        }

        // Handle JSON with 'text' key
        if ($json && isset($json['text'])) {
            $messages = [['type' => 'text', 'content' => $json['text']]];
            foreach (array_slice($allProducts, 0, 5) as $product) {
                $messages[] = ['type' => 'product', 'product' => $product, 'comment' => ''];
            }

            return [
                'intro' => $json['text'],
                'outro' => null,
                'products' => array_slice($allProducts, 0, 5),
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
            'intro' => $responseText ?: $this->generateContextualIntro($this->currentMessage ?? '', $allProducts),
            'outro' => null,
            'products' => array_slice($allProducts, 0, 5),
            'messages' => $messages,
        ];
    }

    /**
     * Detect if the current message is a fresh/new query (not a follow-up).
     * A fresh query should NOT use previous context.
     */
    protected function isFreshQuery(string $message, array $history): bool
    {
        $msg = mb_strtolower(trim($message));
        $wordCount = count(preg_split('/\s+/u', $msg));

        // Short confirmations are NOT fresh queries - they're follow-ups
        $confirmations = [
            'так', 'ні', 'добре', 'ок', 'окей', 'зрозумів', 'дякую', 'ще', 'інші', 'більше', 'показуй',
            'дозволяю', 'хочу', 'давай', 'можна', 'будь ласка', 'звичайно', 'авжеж', 'гаразд', 'згода',
        ];
        if (in_array($msg, $confirmations) || $wordCount <= 2 && preg_match('/^(ще|інші|більше|показуй|дозволяю|хочу|давай)/u', $msg)) {
            return false;
        }

        // If message contains clear category/brand without modifiers - it's a fresh query
        // Brands that indicate a fresh brand-specific search
        $brands = ['defcon', 'm-tac', 'mtac', 'helikon', 'pentagon', 'velmet', '5.11', 'uf pro', 'condor',
            'direct action', 'crye', 'ops-core', 'emerson', 'wartech', 'архангел', 'p1g', 'a-tac', 'hrt',
            'mechanix', 'oakley', 'salomon', 'lowa', 'meindl', 'haix'];

        foreach ($brands as $brand) {
            // If message is ONLY the brand name (with maybe small variations)
            if (preg_match('/^'.preg_quote($brand, '/').'\s*\d*$/ui', $msg)) {
                return true;
            }
        }

        // Clear new category queries (single category word without modifiers from context)
        $categories = ['плитоноски', 'шоломи', 'берці', 'рюкзаки', 'куртки', 'штани', 'футболки',
            'підсумки', 'рукавиці', 'окуляри', 'ремені', 'бронеплати', 'жилети',
            'термобілизна', 'фліс', 'софтшел', 'шапки', 'балаклави', 'носки'];

        foreach ($categories as $cat) {
            if (preg_match('/^'.preg_quote($cat, '/').'$/ui', $msg)) {
                return true;
            }
        }

        // If there's NO history, it's always fresh
        if (empty($history)) {
            return true;
        }

        // If user explicitly starts a new topic with "покажи", "знайди", "шукаю" + different category
        // This is a heuristic - if the new query has a clear product type and it's different from context
        $lastUserMsg = '';
        foreach (array_reverse($history) as $h) {
            if (($h['role'] ?? '') === 'user') {
                $lastUserMsg = mb_strtolower($h['content'] ?? '');
                break;
            }
        }

        // Check if current message has a clear search intent with new category
        if (preg_match('/^(покажи|знайди|шукаю|є|маєте)\s+/ui', $msg)) {
            // Extract what they're searching for now vs before
            // If it's clearly different - fresh query
            return true;
        }

        return false;
    }

    /**
     * Extract conversation context from history.
     */
    protected function extractConversationContext(array $history): string
    {
        if (empty($history)) {
            return '';
        }

        $contextParts = [];
        $productCategories = [];
        $shownProducts = [];
        $sizes = [];
        $colors = [];
        $brands = [];
        $priceRange = [];
        $userQuestions = [];

        // Get category patterns from DB (with fallback to hardcoded)
        $tenantId = $this->currentContext['tenant_id'] ?? null;
        $categoryPatterns = $this->categoryPatternService->getPatterns($tenantId);

        // If DB patterns are empty, use fallback
        if (empty($categoryPatterns)) {
            $categoryPatterns = $this->categoryPatternService->getFallbackPatterns();
        }

        foreach ($history as $msg) {
            $content = $msg['content'] ?? '';
            $role = $msg['role'] ?? '';

            // Collect last 3 user questions for context
            if ($role === 'user' && mb_strlen($content) > 3) {
                $userQuestions[] = mb_substr($content, 0, 100);

                // Extract categories from user queries
                foreach ($categoryPatterns as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $content)) {
                        $productCategories[] = $category;
                    }
                }
            }

            // Also extract categories from assistant messages (when bot mentions products)
            // This catches cases like "є варіанти термобілизни" where user then says "дозволяю"
            if ($role === 'assistant' && mb_strlen($content) > 10) {
                foreach ($categoryPatterns as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $content)) {
                        $productCategories[] = $category;
                    }
                }
            }

            // Extract shown products from [Показані товари: ...]
            if (preg_match('/\[Показані товари: (.+?)\]/u', $content, $matches)) {
                $products = $matches[1];
                $shownProducts[] = $products;

                // Extract categories from product names using same patterns
                foreach ($categoryPatterns as $pattern => $category) {
                    if (preg_match("/($pattern)/ui", $products)) {
                        $productCategories[] = $category;
                    }
                }
            }

            // Extract sizes (including numeric for shoes)
            if (preg_match_all('/\b(XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|[3-4]\d)\b/i', $content, $sizeMatches)) {
                foreach ($sizeMatches[1] as $size) {
                    $sizes[] = strtoupper($size);
                }
            }

            // Extract colors (expanded list)
            $colorPatterns = [
                'чорн' => 'чорний',
                'олив' => 'олива',
                'мультикам|multicam' => 'мультикам',
                'койот|coyote' => 'койот',
                'піксель' => 'піксель',
                'хакі|khaki' => 'хакі',
                'ranger green|рейнджер грін' => 'Ranger Green',
                'коричнев' => 'коричневий',
                'сір|grey|gray' => 'сірий',
                'біл|white' => 'білий',
                'зелен|green' => 'зелений',
                'синій|синя|blue' => 'синій',
                'атакс|a-tacs' => 'A-TACS',
                'рожев|pink' => 'рожевий',
                'червон|red' => 'червоний',
                'оранжев|orange' => 'оранжевий',
                'жовт|yellow' => 'жовтий',
                'фіолетов|purple' => 'фіолетовий',
                'бордов|maroon|burgundy' => 'бордовий',
                'бежев|beige' => 'бежевий',
                'блакитн' => 'блакитний',
            ];
            foreach ($colorPatterns as $pattern => $color) {
                if (preg_match("/($pattern)/ui", $content)) {
                    $colors[] = $color;
                }
            }

            // Extract brands
            $brandPatterns = ['M-TAC', 'Helikon', 'Pentagon', 'Velmet', '5.11', 'UF PRO', 'Condor', 'Direct Action',
                'Crye', 'Ops-Core', 'Emerson', 'Wartech', 'Архангел', 'P1G', 'A-TAC', 'HRT'];
            foreach ($brandPatterns as $brand) {
                if (stripos($content, $brand) !== false) {
                    $brands[] = $brand;
                }
            }

            // Extract price preferences
            if (preg_match('/(бюджетн|недорог|дешев)/ui', $content)) {
                $priceRange[] = 'бюджетний';
            }
            if (preg_match('/(преміум|дорог|якісн|топов)/ui', $content)) {
                $priceRange[] = 'преміум';
            }
            if (preg_match('/до\s*(\d+)\s*(грн|₴)/ui', $content, $priceMatch)) {
                $priceRange[] = 'до '.$priceMatch[1].' грн';
            }
        }

        // Build rich context
        if (! empty($productCategories)) {
            $contextParts[] = 'Шукає: '.implode(', ', array_unique($productCategories));
        }
        if (! empty($shownProducts)) {
            // Only last 2 shown product sets
            $recentShown = array_slice(array_unique($shownProducts), -2);
            $contextParts[] = 'Вже показано: '.implode(' | ', $recentShown);
        }
        if (! empty($brands)) {
            $contextParts[] = 'Бренди: '.implode(', ', array_unique($brands));
        }
        if (! empty($sizes)) {
            $contextParts[] = 'Розміри: '.implode(', ', array_unique($sizes));
        }
        if (! empty($colors)) {
            $contextParts[] = 'Кольори: '.implode(', ', array_unique($colors));
        }
        if (! empty($priceRange)) {
            $contextParts[] = 'Ціна: '.implode(', ', array_unique($priceRange));
        }
        if (! empty($userQuestions)) {
            $recentQuestions = array_slice($userQuestions, -3);
            $contextParts[] = 'Останні питання: '.implode(' → ', $recentQuestions);
        }

        return implode('; ', $contextParts);
    }

    /**
     * Load conversation history from DB.
     * Appends [Показані товари: ...] marker to assistant messages for context.
     */
    protected function loadConversationHistory(?string $sessionId): array
    {
        if (! $sessionId) {
            return [];
        }

        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (! $session) {
                return [];
            }

            $messages = ChatMessage::where('chat_session_id', $session->id)
                ->orderBy('created_at', 'asc')
                ->take(20)
                ->get();

            return $messages->map(function ($m) {
                $content = $m->content;

                // For assistant messages, append shown products marker from meta
                if ($m->role === 'assistant' && ! empty($m->meta['products'])) {
                    $productTitles = array_map(
                        fn ($p) => $p['title'] ?? 'Товар',
                        array_slice($m->meta['products'], 0, 5) // Limit to 5 titles
                    );
                    $productList = implode(', ', $productTitles);

                    // Only add marker if not already present
                    if (strpos($content, '[Показані товари:') === false) {
                        $content .= "\n[Показані товари: {$productList}]";
                    }
                }

                return [
                    'role' => $m->role,
                    'content' => $content,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to load history', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Extract shown product IDs from session history.
     */
    protected function extractShownProductIds(?string $sessionId): array
    {
        if (! $sessionId) {
            return [];
        }

        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (! $session) {
                return [];
            }

            $messages = ChatMessage::where('chat_session_id', $session->id)
                ->where('role', 'assistant')
                ->get();

            $ids = [];
            foreach ($messages as $msg) {
                $meta = $msg->meta ?? [];
                if (! empty($meta['product_ids'])) {
                    $ids = array_merge($ids, $meta['product_ids']);
                }
            }

            return array_unique(array_map('intval', $ids));
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to extract shown product IDs', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Load detailed product info from recent assistant messages for follow-up questions.
     * Returns formatted string with product details (description, attributes, sizes).
     */
    protected function loadRecentProductDetails(?string $sessionId): string
    {
        if (! $sessionId) {
            return '';
        }

        try {
            // Bypass TenantScope - sessions are identified by session_id
            $session = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('session_id', $sessionId)->first();
            if (! $session) {
                return '';
            }

            // Get last 3 assistant messages with product details
            // Also bypass TenantScope for ChatMessage query
            $messages = ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('chat_session_id', $session->id)
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get();

            $allDetails = [];
            foreach ($messages as $msg) {
                $meta = $msg->meta ?? [];
                if (! empty($meta['product_details'])) {
                    $allDetails = array_merge($allDetails, $meta['product_details']);
                }
            }

            if (empty($allDetails)) {
                return '';
            }

            // Format for GPT context - only take last 3 products to reduce token usage
            $formatted = [];
            $count = 0;
            foreach ($allDetails as $id => $detail) {
                if ($count >= 3) {
                    break;
                }

                $lines = [];
                $lines[] = "- **{$detail['title']}** (арт. {$detail['article']})";

                if (! empty($detail['price'])) {
                    $lines[] = "  Ціна: {$detail['price']} грн";
                }

                if (! empty($detail['brand'])) {
                    $lines[] = "  Бренд: {$detail['brand']}";
                }

                if (! empty($detail['sizes'])) {
                    $lines[] = '  Доступні розміри: '.implode(', ', array_slice($detail['sizes'], 0, 5));
                }

                if (! empty($detail['attributes'])) {
                    $attrStr = [];
                    foreach ($detail['attributes'] as $name => $value) {
                        $attrStr[] = "{$name}: {$value}";
                    }
                    if ($attrStr) {
                        $lines[] = '  Характеристики: '.implode('; ', array_slice($attrStr, 0, 5));
                    }
                }

                if (! empty($detail['description'])) {
                    $lines[] = '  Опис: '.mb_substr($detail['description'], 0, 100).'...';
                }

                $formatted[] = implode("\n", $lines);
                $count++;
            }

            return implode("\n\n", $formatted);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to load product details', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * Log user message to DB.
     */
    protected function logUserMessage(?string $sessionId, string $message): void
    {
        if (! $sessionId) {
            return;
        }

        try {
            $tenantId = $this->searchTool->getCurrentTenantId();

            $session = ChatSession::firstOrCreate(
                ['session_id' => $sessionId],
                [
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Update tenant_id if session exists but has null tenant_id
            if ($session->tenant_id === null && $tenantId !== null) {
                $session->update(['tenant_id' => $tenantId]);
            }

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'user',
                'content' => $message,
            ]);

            // Update session: reopen if closed, update last_message_at
            // Sessions may be auto-closed after 24h inactivity, reopen on new message
            $session->update([
                'last_message_at' => now(),
                'messages_count' => ($session->messages_count ?? 0) + 1,
                'status' => 'open', // Reopen session on new message
            ]);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to log user message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Log assistant message to DB.
     */
    protected function logAssistantMessage(?string $sessionId, string $text, array $products, string $intent): void
    {
        if (! $sessionId) {
            return;
        }

        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (! $session) {
                return;
            }

            // Build content with product markers
            $content = $text;
            if (! empty($products)) {
                $productMarkers = array_map(fn ($p) => ($p['title'] ?? '').' (арт. '.($p['article'] ?? '').')', array_slice($products, 0, 3));
                $content .= "\n[Показані товари: ".implode(', ', $productMarkers).']';
            }

            // Build detailed product info for follow-up questions
            $productDetails = $this->buildProductDetailsForStorage($products);

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $content,
                'meta' => [
                    'intent' => $intent,
                    'product_ids' => array_column($products, 'id'),
                    'product_articles' => array_column($products, 'article'),
                    'product_details' => $productDetails, // Full details for follow-up questions
                ],
            ]);

            // Update last_message_at for proper sorting in dashboard
            $session->update([
                'last_message_at' => now(),
                'messages_count' => ($session->messages_count ?? 0) + 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to log assistant message', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Build detailed product info for storage in meta.
     * Includes description, attributes, sizes, variants - everything GPT might need.
     */
    protected function buildProductDetailsForStorage(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_column($products, 'id');

        try {
            // Load full product data from DB
            $dbProducts = Product::whereIn('id', $productIds)->get()->keyBy('id');

            $details = [];
            foreach ($products as $p) {
                $id = $p['id'] ?? null;
                if (! $id) {
                    continue;
                }

                $dbProduct = $dbProducts->get($id);
                if (! $dbProduct) {
                    continue;
                }

                // Extract description and attributes from raw
                $raw = $dbProduct->raw ?? [];
                $parentRaw = [];

                // Try to get parent raw if this is a variant
                if ($dbProduct->parent_article) {
                    $parent = Product::where('article', $dbProduct->parent_article)->first();
                    $parentRaw = $parent?->raw ?? [];
                }

                $description = \App\Support\ProductRawExtractor::description($raw, 'ua', $parentRaw);
                $attributes = \App\Support\ProductRawExtractor::attributes($raw, 'ua', $parentRaw);

                // Extract available sizes/variants - also get current product size from DB
                $sizes = $this->extractSizesFromProduct($dbProduct);

                $details[$id] = [
                    'title' => $p['title'] ?? $dbProduct->title,
                    'article' => $p['article'] ?? $dbProduct->article,
                    'price' => $p['price'] ?? $dbProduct->price,
                    'brand' => $p['brand'] ?? $dbProduct->brand,
                    'description' => mb_substr($description, 0, 500), // Limit to 500 chars
                    'attributes' => array_slice($attributes, 0, 15), // Max 15 attributes
                    'sizes' => $sizes,
                    'category' => $dbProduct->category_path,
                ];
            }

            return $details;
        } catch (\Throwable $e) {
            Log::warning('BaseAgent: failed to build product details', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Extract available sizes from product - checks DB column, raw data, and sibling products.
     */
    protected function extractSizesFromProduct(Product $product): array
    {
        $sizes = [];
        $raw = $product->raw ?? [];

        // 1. First check the product's own size column
        if (! empty($product->size)) {
            $sizes[] = $product->size;
        }

        // 2. Check variants array in raw
        if (! empty($raw['variants']) && is_array($raw['variants'])) {
            foreach ($raw['variants'] as $variant) {
                $size = $variant['size'] ?? ($variant['select']['size'] ?? null);
                if ($size && is_string($size)) {
                    $sizes[] = $size;
                }
            }
        }

        // 3. Check select.size in raw
        if (! empty($raw['select']['size'])) {
            $sizeData = $raw['select']['size'];
            if (is_string($sizeData)) {
                $sizes[] = $sizeData;
            } elseif (is_array($sizeData)) {
                foreach ($sizeData as $s) {
                    if (is_string($s)) {
                        $sizes[] = $s;
                    } elseif (is_array($s) && isset($s['value'])) {
                        $sizes[] = $s['value'];
                    }
                }
            }
        }

        // 4. Check characteristics.size in raw
        if (! empty($raw['characteristics']['size'])) {
            $sizeChar = $raw['characteristics']['size'];
            if (is_string($sizeChar)) {
                $sizes[] = $sizeChar;
            } elseif (is_array($sizeChar) && isset($sizeChar['value'])) {
                $val = $sizeChar['value'];
                if (is_string($val)) {
                    $sizes[] = $val;
                } elseif (is_array($val)) {
                    $sizes = array_merge($sizes, array_filter($val, 'is_string'));
                }
            }
        }

        // 5. Look for sibling products with same parent_article
        if (! empty($product->parent_article)) {
            $siblingsSizes = Product::where('parent_article', $product->parent_article)
                ->whereNotNull('size')
                ->where('size', '!=', '')
                ->limit(20)
                ->pluck('size')
                ->filter()
                ->toArray();
            $sizes = array_merge($sizes, $siblingsSizes);
        }

        // 6. If no parent_article, look for products with SAME title (size variants often have identical titles)
        if (empty($product->parent_article) && count(array_unique($sizes)) <= 1) {
            $titleSiblings = Product::where('title', $product->title)
                ->where('id', '!=', $product->id)
                ->where('tenant_id', $product->tenant_id)
                ->whereNotNull('size')
                ->where('size', '!=', '')
                ->limit(20)
                ->pluck('size')
                ->filter()
                ->toArray();
            $sizes = array_merge($sizes, $titleSiblings);
        }

        return array_values(array_unique(array_filter($sizes)));
    }

    /**
     * Fallback response when API is unavailable.
     */
    protected function fallbackResponse(string $message): array
    {
        PipelineTracer::current()?->step('agent.fallback_response', [
            'message' => mb_substr($message, 0, 100),
        ]);
        Log::warning('BaseAgent: using fallback response');

        // Try simple keyword search
        $results = $this->searchTool->search($message, [], 3);

        if (! empty($results)) {
            $ids = array_column($results, 'id');
            $tenantId = $this->searchTool->getCurrentTenantId();
            $cards = $this->detailsTool->getCards($ids, 10, $tenantId);
            if (! empty($cards)) {
                $results = $cards;
            }

            $intro = $this->generateContextualIntro($this->currentMessage ?? '', $results);

            return [
                'message' => $intro,
                'products' => $results,
                'messages' => [
                    ['type' => 'text', 'content' => $intro],
                    ['type' => 'products', 'products' => $results],
                ],
                'meta' => ['intent' => 'product_search', 'agent' => 'fallback'],
            ];
        }

        return [
            'message' => 'Вибачте, наразі у мене технічні труднощі. Спробуйте пізніше або зверніться до менеджера.',
            'products' => [],
            'messages' => [['type' => 'text', 'content' => 'Вибачте, наразі у мене технічні труднощі. Спробуйте пізніше або зверніться до менеджера.']],
            'meta' => ['intent' => 'error', 'agent' => 'fallback'],
        ];
    }
}
