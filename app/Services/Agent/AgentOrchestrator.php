<?php

namespace App\Services\Agent;

use App\Enums\Intent;
use App\DTO\SearchPlanDTO;
use App\DTO\AgentResponseDTO;
use App\Services\Ai\AiRouter;
use App\Services\Session\SessionContextService;
use App\Services\Search\ColorService;
use App\Services\Agent\Handlers\FaqHandler;
use App\Services\Agent\Handlers\SmallTalkHandler;
use App\Services\Agent\Handlers\OrderStatusHandler;
use App\Services\Agent\Handlers\NarrativeBuilder;
use App\Models\Brand;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Agent\Tools\DeduperTool;
use App\Services\Agent\Tools\AccessoryFilterTool;
use App\Services\Agent\Tools\AiRerankTool;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Horoshop\DeliveryTrackingService;
use App\Services\Horoshop\HoroshopDataService;
use App\Models\WidgetSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    public function __construct(
        private AiRouter $aiRouter,
        private MeiliProductSearchTool $searchTool,
        private ProductDetailsTool $detailsTool,
        private DeduperTool $deduperTool,
        private AccessoryFilterTool $accessoryFilterTool,
        private AiRerankTool $rerankTool,
        private OrderSearchService $orderSearchService,
        private DeliveryTrackingService $deliveryTrackingService,
        private HoroshopDataService $horoshopDataService,
        private SessionContextService $sessionService,
        private ColorService $colorService,
        private FaqHandler $faqHandler,
        private SmallTalkHandler $smallTalkHandler,
        private OrderStatusHandler $orderStatusHandler,
        private NarrativeBuilder $narrativeBuilder,
    ) {}

    /**
     * Main orchestration method
     * RULE: ALWAYS return products FIRST if product-related, questions AFTER
     */
    public function handle(string $message, array $context = []): array
    {
        $sessionId = $context['session_id'] ?? null;
        
        Log::info('AgentOrchestrator: processing message', [
            'message' => $message,
            'session_id' => $sessionId
        ]);

        // Step 1: Plan with AI (intent + search query + ambiguity)
        $plan = $this->createPlan($message, $context);
        
        Log::info('AgentOrchestrator: plan created', $plan->toArray());

        // Step 2: Execute based on intent using handlers
        $response = match($plan->intent) {
            Intent::ProductSearch => $this->handleProductSearch($message, $plan, $context),
            Intent::ProductComparison => $this->handleProductComparison($message, $plan, $context),
            Intent::OrderStatus => $this->orderStatusHandler->handle($message, $plan->toArray(), $context),
            Intent::Faq => $this->faqHandler->handle($message, $plan->toArray(), $context),
            Intent::SmallTalk => $this->smallTalkHandler->handle($message, $plan->toArray(), $context),
            default => AgentResponseDTO::unknown("Не зовсім зрозумів запит. Можете уточнити: шукаєте товар, питаєте про замовлення чи потрібна інша інформація?"),
        };

        // Convert AgentResponseDTO to array if needed
        $result = $response instanceof AgentResponseDTO ? $response->toArray() : $response;

        Log::info('AgentOrchestrator: result generated', [
            'intent' => $plan->intent->value,
            'products_count' => count($result['products'] ?? []),
            'message_length' => strlen($result['message'] ?? '')
        ]);

        return $result;
    }

    /**
     * Create execution plan using AI
     * Returns SearchPlanDTO with intent, query, filters
     */
    private function createPlan(string $message, array $context): SearchPlanDTO
    {
        // Use existing AiRouter for intent classification
        $classification = $this->aiRouter->classify($message);
        
        // Use Intent enum for type safety
        $intent = Intent::fromString($classification['intent'] ?? 'unknown');
        
        // Normalize search query if product-related
        $searchQuery = null;
        $filters = [];
        $ambiguous = false;
        
        // Fast-path: if user message clearly contains a brand word, force product search
        if ($this->containsBrandWord($message)) {
            $intent = Intent::ProductSearch;
        }

        if ($intent === Intent::ProductSearch) {
            $normalized = $this->aiRouter->normalizeSearchQuery($message);
            
            // normalizeSearchQuery returns a string, not array
            if (is_string($normalized)) {
                $searchQuery = $normalized;
            } else {
                $searchQuery = $normalized['query'] ?? $message;
                $filters = $normalized['filters'] ?? [];
            }
            
            // Preserve brand terms: if message contains a known brand, override searchQuery with original
            try {
                if ($this->containsBrandWord($message)) {
                    $searchQuery = $message;
                    $ambiguous = false;
                    Log::info('AgentOrchestrator: brand detected, preserving original query', [
                        'query' => $message
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('AgentOrchestrator: brand detection failed', ['error' => $e->getMessage()]);
            }

            // Extract filters from original message (budget, color, etc.) using ColorService
            $filters = $this->extractFiltersFromMessage($message);
            
            // Check if query is ambiguous (needs clarification but AFTER showing products)
            $ambiguous = $this->detectAmbiguity($message, $searchQuery ?? $message, $filters);
        }

        return new SearchPlanDTO(
            intent: $intent,
            searchQuery: $searchQuery,
            filters: $filters,
            ambiguous: $ambiguous,
            confidence: (float) ($classification['confidence'] ?? $intent->defaultConfidence()),
            orderId: $classification['order_id'] ?? null,
        );
    }

    /**
     * Detect if the message contains any known brand word.
     */
    private function containsBrandWord(string $message): bool
    {
        $msg = mb_strtolower($message);
        $brands = $this->getBrandNames();

        foreach ($brands as $brand) {
            $b = mb_strtolower($brand);
            if (preg_match('/\b' . preg_quote($b, '/') . '\b/u', $msg) || str_contains($msg, $b)) {
                return true;
            }
        }
        return false;
    }

    private function getBrandNames(): array
    {
        try {
            $names = Brand::query()->pluck('name')->filter()->values()->all();
            if (!empty($names)) {
                return $names;
            }
        } catch (\Throwable $e) {
            // Fallback list
        }
        return ['hoffmann', 'атака', 'ataka', 'mil-tec', 'miltec', 'avenger', 'condor', '5.11', '511'];
    }

    /**
     * Handle product search intent
     * ALWAYS show products, questions come after
     */
    private function handleProductSearch(string $originalMessage, SearchPlanDTO $plan, array $context): array
    {
        $sessionId = $context['session_id'] ?? null;
        $sessionContext = $this->sessionService->loadContext($sessionId);

        // Detect if this is a follow-up details request about the last product
        if ($this->isDetailsFollowUp($originalMessage) && !empty($sessionContext['last_shown_product_id'])) {
            // Return details of the last shown product instead of searching
            return $this->handleProductDetailsFollowUp($originalMessage, $sessionContext);
        }

        $searchQuery = $plan->searchQuery ?? $originalMessage;
        $filters = $plan->filters;
        
        $debug = [
            'original_message' => $originalMessage,
            'search_query' => $searchQuery,
            'filters' => $filters,
            'steps' => [],
        ];
        
        // Step 1: Search Meili (get 40 candidates)
        $startTime = microtime(true);
        $candidates = $this->searchTool->search($searchQuery, $filters, 40);
        $debug['steps'][] = [
            'step' => 'search',
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'candidates_found' => count($candidates),
        ];
        
        if (empty($candidates)) {
            return AgentResponseDTO::noResults($searchQuery)->toArray();
        }

        // Step 2: Deduplicate by parent_article
        $startTime = microtime(true);
        $deduped = $this->deduperTool->dedupe($candidates);
        $debug['steps'][] = [
            'step' => 'dedupe',
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'before' => count($candidates),
            'after' => count($deduped),
            'removed' => count($candidates) - count($deduped),
        ];
        
        // Step 3: Filter/downrank accessories if needed
        $startTime = microtime(true);
        $hint = ['query' => $searchQuery, 'filters' => $filters];
        $filtered = $this->accessoryFilterTool->downrankAccessories($deduped, $hint);
        $debug['steps'][] = [
            'step' => 'accessory_filter',
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'reranked' => count($filtered),
        ];
        
        // Step 3.5: AI Re-ranking (intelligent relevance scoring + dynamic limit)
        $startTime = microtime(true);
        $reranked = $this->rerankTool->rerank($filtered, $searchQuery, $filters, 10);
        $debug['steps'][] = [
            'step' => 'ai_rerank',
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'before' => count($filtered),
            'after' => count($reranked),
            'dynamic_limit' => count($reranked), // AI decided how many are relevant
        ];
        
        // Step 3.6: Relevance validation - check if results match the query type
        $relevanceCheck = $this->validateResultRelevance($reranked, $searchQuery, $originalMessage);
        if (!$relevanceCheck['is_relevant']) {
            Log::info('AgentOrchestrator: results not relevant to query', [
                'query' => $searchQuery,
                'reason' => $relevanceCheck['reason'],
                'found_categories' => $relevanceCheck['found_categories'] ?? [],
            ]);
            
            return AgentResponseDTO::noResults(
                $searchQuery,
                $relevanceCheck['suggestion'] ?? "Спробуйте уточнити запит або пошукати в іншій категорії."
            )->toArray();
        }
        
        // Step 4: Get full details for AI-selected products (dynamic count)
        $startTime = microtime(true);
        $topIds = array_column($reranked, 'id');
        $products = $this->detailsTool->getCards($topIds, count($topIds));
        $debug['steps'][] = [
            'step' => 'get_details',
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'ids' => $topIds,
            'products_fetched' => count($products),
        ];
        
        // Collect articles for debug
        $chosenArticles = array_column($products, 'article');
        
        // Step 5: Generate response - intro message + product cards with descriptions
        $message = $this->narrativeBuilder->buildProductNarrative($products, $originalMessage, $filters, $sessionContext, $sessionId);
        $productCards = $this->narrativeBuilder->buildProductCards($products, $originalMessage, $filters);

        // Persist lightweight session context using SessionContextService
        $lastProduct = !empty($products) ? $products[0] : null;
        $this->sessionService->saveContext($sessionId, [
            'last_category' => $this->guessCategoryFromProducts($products),
            'last_budget_min' => $filters['budget_min'] ?? null,
            'last_budget_max' => $filters['budget_max'] ?? null,
            'shown_products' => array_column($products, 'id'),
            'last_shown_product_id' => $lastProduct['id'] ?? null,
            'last_shown_product' => $lastProduct ? [
                'id' => $lastProduct['id'],
                'title' => $lastProduct['title'],
                'article' => $lastProduct['article'],
                'price' => $lastProduct['price'],
                'category_path' => $lastProduct['category_path'],
            ] : null,
        ]);
        
        // Step 6: Add follow-up question if ambiguous (AFTER products)
        if ($plan->ambiguous && count($products) > 5) {
            $question = $this->narrativeBuilder->generateFollowUpQuestion($originalMessage, $searchQuery, $filters, $products);
            if (!empty($question)) {
                $message .= "\n\n" . $question;
            }
        }

        $response = AgentResponseDTO::productSearch(
            message: $message,
            products: $products,
            refinedQuery: $searchQuery,
            filters: $filters,
            chosenIds: $topIds,
            ambiguous: $plan->ambiguous,
            searchDebug: $debug,
        );
        
        // Add product_cards to response for new display format
        $result = $response->toArray();
        $result['meta']['product_cards'] = $productCards;
        
        return $result;
    }

    /**
     * Handle product comparison request.
     * Uses products from session context (shown_products) for comparison.
     */
    private function handleProductComparison(string $message, SearchPlanDTO $plan, array $context): array
    {
        $sessionId = $context['session_id'] ?? null;
        $sessionContext = $this->sessionService->loadContext($sessionId);
        
        // Get products IDs from session
        $shownProductIds = $sessionContext['shown_products'] ?? [];
        
        Log::info('AgentOrchestrator: handling product comparison', [
            'session_id' => $sessionId,
            'shown_products' => $shownProductIds,
        ]);
        
        // If no products in session, ask user to search first
        if (empty($shownProductIds)) {
            return AgentResponseDTO::unknown(
                "Щоб порівняти товари, спочатку покажіть мені, що вас цікавить. Напишіть, наприклад: 'плитоноска' або 'шолом чорний'."
            )->toArray();
        }
        
        // Fetch full product details for comparison
        $products = $this->detailsTool->getCards($shownProductIds, count($shownProductIds));
        
        if (count($products) < 2) {
            return AgentResponseDTO::unknown(
                "Для порівняння потрібно мінімум 2 товари. Показати ще варіанти для порівняння?"
            )->toArray();
        }
        
        // Build expert comparison
        $comparison = $this->buildExpertComparison($products, $message);
        
        return [
            'message' => $comparison,
            'products' => $products, // Return same products for UI consistency
            'meta' => [
                'intent' => 'product_comparison',
                'compared_ids' => $shownProductIds,
                'products_count' => count($products),
            ],
        ];
    }

    /**
     * Build expert product comparison based on real data.
     * Priority: description → characteristics → title
     * No hallucinations allowed - only use actual product data.
     */
    private function buildExpertComparison(array $products, string $userQuestion): string
    {
        // Limit to 3 products for comparison
        $products = array_slice($products, 0, 3);
        
        // Collect product info for comparison
        $productInfos = [];
        foreach ($products as $i => $p) {
            $info = [
                'title' => trim($p['title'] ?? 'Товар ' . ($i + 1)),
                'price' => $p['price'] ?? null,
                'description' => trim($p['description'] ?? ''),
                'characteristics' => $p['characteristics'] ?? [],
                'category_path' => $p['category_path'] ?? '',
                'brand' => $p['brand'] ?? '',
                'color' => $p['color'] ?? '',
            ];
            $productInfos[] = $info;
        }
        
        // Build comparison prompt for AI
        $productsContext = [];
        foreach ($productInfos as $i => $info) {
            $num = $i + 1;
            $priceText = $info['price'] ? round($info['price']) . ' ₴' : 'ціна не вказана';
            
            $ctx = "Товар {$num}: {$info['title']} — {$priceText}";
            
            if (!empty($info['description'])) {
                $ctx .= "\nОпис: " . mb_substr($info['description'], 0, 500);
            }
            
            if (!empty($info['characteristics']) && is_array($info['characteristics'])) {
                $charsList = [];
                foreach ($info['characteristics'] as $key => $val) {
                    if (is_string($key) && !empty($val)) {
                        $charsList[] = "{$key}: {$val}";
                    }
                }
                if (!empty($charsList)) {
                    $ctx .= "\nХарактеристики: " . implode('; ', array_slice($charsList, 0, 8));
                }
            }
            
            if (!empty($info['brand'])) {
                $ctx .= "\nБренд: {$info['brand']}";
            }
            
            $productsContext[] = $ctx;
        }
        
        $productsText = implode("\n\n", $productsContext);
        
        $prompt = "Ти — експерт-консультант магазину тактичного спорядження Contractor.

Користувач запитав: \"{$userQuestion}\"

Ось товари для порівняння:
{$productsText}

ЗАВДАННЯ:
Дай коротке ЕКСПЕРТНЕ порівняння (до 300 слів) українською.

ПРАВИЛА:
1. Використовуй ТІЛЬКИ дані з опису та характеристик вище
2. НЕ вигадуй факти, яких немає в даних
3. Порівнюй по реальних відмінностях: ціна, матеріали, рівень захисту, особливості
4. Дай конкретну рекомендацію: кому який варіант підійде краще
5. НЕ використовуй Markdown (жирний текст, списки з -)
6. Пиши як досвідчений менеджер-консультант

Формат відповіді:
- Короткий вступ (1 речення)
- Ключові відмінності (2-4 пункти простим текстом)
- Рекомендація (1-2 речення про те, кому що краще)
- Питання: 'Який варіант вам більше підходить?' або подібне";

        try {
            $response = $this->aiRouter->callOpenAI($prompt, 0.3, 500);
            $comparison = trim($response);
            
            if (empty($comparison) || mb_strlen($comparison) < 50) {
                return $this->buildFallbackComparison($productInfos);
            }
            
            return $comparison;
        } catch (\Throwable $e) {
            Log::warning('AgentOrchestrator: AI comparison failed', ['error' => $e->getMessage()]);
            return $this->buildFallbackComparison($productInfos);
        }
    }

    /**
     * Fallback comparison without AI.
     */
    private function buildFallbackComparison(array $productInfos): string
    {
        $lines = [];
        $lines[] = "Ось порівняння товарів:";
        $lines[] = "";
        
        foreach ($productInfos as $i => $info) {
            $num = $i + 1;
            $priceText = $info['price'] ? round($info['price']) . ' ₴' : 'ціна не вказана';
            $lines[] = "{$num}. {$info['title']} — {$priceText}";
            
            // Add key facts
            $facts = [];
            if (!empty($info['brand'])) {
                $facts[] = "Бренд: {$info['brand']}";
            }
            if (!empty($info['color'])) {
                $facts[] = "Колір: {$info['color']}";
            }
            if (!empty($facts)) {
                $lines[] = "   " . implode(', ', $facts);
            }
        }
        
        $lines[] = "";
        
        // Price comparison
        $prices = array_filter(array_column($productInfos, 'price'));
        if (count($prices) > 1) {
            $minPrice = min($prices);
            $maxPrice = max($prices);
            $diff = $maxPrice - $minPrice;
            $lines[] = "Різниця в ціні: " . round($diff) . " ₴";
        }
        
        $lines[] = "";
        $lines[] = "Який варіант вас більше цікавить? Можу розповісти детальніше.";
        
        return implode("\n", $lines);
    }

    /**
     * Generate follow-up clarification question using AI
     * @deprecated Use NarrativeBuilder::generateFollowUpQuestion instead
     */
    private function generateFollowUpQuestion(string $originalMessage, string $searchQuery, array $filters, array $products): string
    {
        return $this->narrativeBuilder->generateFollowUpQuestion($originalMessage, $searchQuery, $filters, $products);
    }

    /**
     * Build product narrative
     * @deprecated Use NarrativeBuilder::buildProductNarrative instead
     */
    private function buildProductNarrative(array $products, string $originalMessage, array $filters, array $sessionContext, ?string $sessionId): string
    {
        return $this->narrativeBuilder->buildProductNarrative($products, $originalMessage, $filters, $sessionContext, $sessionId);
    }

    /**
     * Legacy: Check if products have diversity
     * @deprecated Moved to NarrativeBuilder
     */
    private function hasProductDiversity(array $products): bool
    {
        if (count($products) < 3) {
            return false; // Too few to ask questions
        }

        $categories = array_unique(array_column($products, 'category_path'));
        $colors = array_filter(array_unique(array_column($products, 'color')));
        $prices = array_column($products, 'price');
        
        $priceRange = !empty($prices) ? (max($prices) - min($prices)) : 0;

        // Don't ask if: 1 category + ≤2 colors + price range <1000
        if (count($categories) === 1 && count($colors) <= 2 && $priceRange < 1000) {
            return false;
        }

        return true;
    }

    /**
     * Handle no results scenario
     */
    private function handleNoResults(string $originalMessage, string $searchQuery, array $filters = []): array
    {
        return [
            'message' => "Вибачте, не знайшов товарів за запитом «{$searchQuery}». Спробуйте інші ключові слова або опишіть що саме вам потрібно.",
            'products' => [],
            'meta' => [
                'intent' => 'product_search',
                'ambiguous' => false,
                'refined_query' => $searchQuery,
                'filters' => $filters,
                'chosen_ids' => [],
                'search_debug' => ['candidates_found' => 0],
            ],
        ];
    }

    /**
     * Handle order status requests
     * @deprecated Use OrderStatusHandler instead. This method is kept for backward compatibility.
     */
    private function handleOrderStatus(string $message, array $plan, array $context): array
    {
        return $this->orderStatusHandler->handle($message, $plan, $context);
    }

    /**
     * Handle FAQ requests
     * @deprecated Use FaqHandler instead. This method is kept for backward compatibility.
     */
    private function handleFaq(string $message, array $plan, array $context): array
    {
        return $this->faqHandler->handle($message, $plan, $context);
    }

    /**
     * Handle small talk
     * @deprecated Use SmallTalkHandler instead. This method is kept for backward compatibility.
     */
    private function handleSmallTalk(string $message, array $plan, array $context): array
    {
        return $this->smallTalkHandler->handle($message, $plan, $context);
    }

    /**
     * Handle unknown intent
     * @deprecated Use SmallTalkHandler::handleUnknown instead.
     */
    private function handleUnknown(string $message, array $plan, array $context): array
    {
        return $this->smallTalkHandler->handleUnknown($message, $context);
    }

    private function detectProductFlow(string $message): string
    {
        $m = mb_strtolower($message);

        $comparisonKeywords = ['порівняй', 'чим різниця', 'чим відрізня', 'чим відрізняється', 'vs', 'відмінність', 'різниця'];
        foreach ($comparisonKeywords as $kw) {
            if (str_contains($m, $kw)) {
                return 'comparison';
            }
        }

        $followupKeywords = ['докупити', 'що ще потрібно', 'комплект', 'додатков', 'аксесуар', 'до цієї', 'сумісн'];
        foreach ($followupKeywords as $kw) {
            if (str_contains($m, $kw)) {
                return 'followup';
            }
        }

        $detailKeywords = ['що за', 'розкажи про', 'опис', 'характеристик', 'підійде', 'підходить'];
        foreach ($detailKeywords as $kw) {
            if (str_contains($m, $kw)) {
                return 'details';
            }
        }

        return 'discovery';
    }

    private function formatComparisonDiffs(array $a, array $b): array
    {
        $diffs = [];

        $levelA = $this->extractLevel($a);
        $levelB = $this->extractLevel($b);
        if ($levelA && $levelB && $levelA !== $levelB) {
            $diffs[] = "Рівень: {$levelA} vs {$levelB}";
        }

        $brandA = trim((string) ($a['brand'] ?? ''));
        $brandB = trim((string) ($b['brand'] ?? ''));
        if ($brandA && $brandB && mb_strtolower($brandA) !== mb_strtolower($brandB)) {
            $diffs[] = "Бренд: {$brandA} vs {$brandB}";
        }

        $catA = trim((string) ($a['category_path'] ?? ''));
        $catB = trim((string) ($b['category_path'] ?? ''));
        if ($catA && $catB && mb_strtolower($catA) !== mb_strtolower($catB)) {
            $diffs[] = "Категорія: {$catA} vs {$catB}";
        }

        $colorA = trim((string) ($a['color'] ?? ''));
        $colorB = trim((string) ($b['color'] ?? ''));
        if ($colorA && $colorB && mb_strtolower($colorA) !== mb_strtolower($colorB)) {
            $diffs[] = "Колір: {$colorA} vs {$colorB}";
        }

        $priceA = $a['price'] ?? null;
        $priceB = $b['price'] ?? null;
        if (is_numeric($priceA) && is_numeric($priceB) && (float)$priceA !== (float)$priceB) {
            $diff = round(abs((float)$priceA - (float)$priceB));
            $diffs[] = "Ціна: {$this->formatPrice($priceA)} vs {$this->formatPrice($priceB)} (різниця ~{$diff} ₴)";
        }

        return array_slice($diffs, 0, 3);
    }

    private function formatProductFacts(array $p, int $limit = 2): array
    {
        $facts = [];

        $level = $this->extractLevel($p);
        if ($level) {
            $facts[] = "Level: {$level}";
        }

        $chars = $p['characteristics'] ?? [];
        if (is_array($chars) && !empty($chars)) {
            foreach ($chars as $k => $v) {
                if (count($facts) >= $limit) { break; }
                if (!is_string($k) || $k === '' || $v === null) { continue; }
                $vText = is_scalar($v) ? (string) $v : '';
                if ($vText === '') { continue; }
                $facts[] = trim($k) . ': ' . trim($vText);
            }
        }

        if (count($facts) < $limit) {
            $desc = trim((string) ($p['description'] ?? ''));
            if ($desc !== '') {
                $facts[] = mb_substr($desc, 0, 80) . (mb_strlen($desc) > 80 ? '…' : '');
            }
        }

        return array_slice($facts, 0, $limit);
    }

    private function extractLevel(array $product): ?string
    {
        $haystack = mb_strtolower(trim((string) (($product['title'] ?? '') . ' ' . ($product['category_path'] ?? ''))));
        if ($haystack === '') {
            return null;
        }

        if (preg_match('/level\s*([1-9])/iu', $haystack, $m)) {
            return 'Level ' . $m[1];
        }

        return null;
    }

    private function formatPrice($price): string
    {
        if ($price === null || $price === '') {
            return 'ціна не вказана';
        }
        if (!is_numeric($price)) {
            return 'ціна не вказана';
        }
        return round((float)$price) . ' ₴';
    }
    private function pickComparisonPair(array $products, string $message): array
    {
        $scores = [];
        foreach ($products as $idx => $p) {
            $scores[$idx] = $this->scoreProductByMessage($p, $message);
        }
        arsort($scores);
        $indices = array_keys($scores);
        $first = $products[$indices[0]] ?? ($products[0] ?? []);
        $second = $products[$indices[1]] ?? ($products[1] ?? $first);
        if (($first['id'] ?? null) === ($second['id'] ?? null) && isset($products[1])) {
            $second = $products[1];
        }
        return [$first, $second];
    }

    private function scoreProductByMessage(array $product, string $message): int
    {
        $m = mb_strtolower($message);
        $title = mb_strtolower((string)($product['title'] ?? ''));
        $brand = mb_strtolower((string)($product['brand'] ?? ''));
        $score = 0;
        $tokens = preg_split('/[^a-zа-я0-9]+/ui', $m, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_filter($tokens, fn($t) => mb_strlen($t) >= 3);
        $brandWords = array_map('mb_strtolower', $this->getBrandNames());
        foreach ($tokens as $t) {
            if (in_array($t, $brandWords, true)) {
                if (!empty($brand) && str_contains($brand, $t)) { $score += 5; }
            }
            if (str_contains($title, $t)) { $score += 2; }
        }
        return $score;
    }

    private function buildAccessorySuggestions(string $category): array
    {
        $map = [
            'plates' => [
                '- Додати плитоноску: зручна посадка і швидке скидання',
                '- Чохол/кавер для плити: захист від зносу',
                '- Пакети/антишар: покращує комфорт під бронею',
            ],
            'plate-carriers' => [
                '- Кишені/підсумки: для магазинів і медички',
                '- Камербанд: краща стабілізація спорядження',
                '- Плечові накладки: більше комфорту з навантаженням',
            ],
            'helmets' => [
                '- Кріплення/релси: для ліхтарів і аксесуарів',
                '- Амортизаційні накладки: комфорт і посадка',
                '- Захист очей/візор: сумісні варіанти',
            ],
        ];
        return $map[$category] ?? [];
    }

    /**
     * Load session context
     * @deprecated Use SessionContextService::loadContext instead
     */
    private function loadSessionContext(?string $sessionId): array
    {
        return $this->sessionService->loadContext($sessionId);
    }

    /**
     * Save session context
     * @deprecated Use SessionContextService::saveContext instead
     */
    private function saveSessionContext(?string $sessionId, array $data): void
    {
        $this->sessionService->saveContext($sessionId, $data);
    }

    // === Helper methods ===

    private function detectAmbiguity(string $message, string $searchQuery, array $filters): bool
    {
        // No specific filters = potentially ambiguous
        if (empty($filters['camo']) && empty($filters['color']) && empty($filters['budget_min'])) {
            // But if query is very specific - not ambiguous
            $specificTerms = ['sapi', 'esapi', 'nij', 'балістичний', 'bump', 'керамічна'];
            foreach ($specificTerms as $term) {
                if (str_contains(mb_strtolower($message), $term)) {
                    return false;
                }
            }
            return true;
        }
        
        return false;
    }

    private function extractMainCategory(array $product): string
    {
        $categoryPath = $product['category_path'] ?? '';
        $aiType = $product['ai_product_type'] ?? '__unknown__';
        
        if ($aiType && $aiType !== '__unknown__') {
            return $aiType;
        }
        
        // Fallback: extract from category_path
        $parts = explode('/', $categoryPath);
        if (count($parts) >= 2) {
            $mainCategory = mb_strtolower($parts[1]);
            
            if (str_contains($mainCategory, 'плит') || str_contains($mainCategory, 'брон')) {
                return 'plates';
            }
            if (str_contains($mainCategory, 'шолом') || str_contains($mainCategory, 'каск')) {
                return 'helmets';
            }
            if (str_contains($mainCategory, 'носій') || str_contains($mainCategory, 'carrier')) {
                return 'plate-carriers';
            }
            if (str_contains($mainCategory, 'сумк')) {
                return 'bags';
            }
            if (str_contains($mainCategory, 'рюкзак')) {
                return 'backpacks';
            }
            if (str_contains($mainCategory, 'куртк') || str_contains($mainCategory, 'форм')) {
                return 'clothing';
            }
            if (str_contains($mainCategory, 'черевик') || str_contains($mainCategory, 'взутт')) {
                return 'footwear';
            }
        }
        
        return 'other';
    }
    
    /**
     * Validate that search results are actually relevant to the query.
     * Returns array with 'is_relevant', 'reason', 'suggestion', 'found_categories'.
     */
    private function validateResultRelevance(array $products, string $searchQuery, string $originalMessage): array
    {
        if (empty($products)) {
            return ['is_relevant' => false, 'reason' => 'no_products'];
        }
        
        $query = mb_strtolower($searchQuery);
        $original = mb_strtolower($originalMessage);
        
        // Define product type mappings: query keywords -> expected category paths (not titles!)
        $productTypeMap = [
            // Plate carriers - must be in plate carrier category, not accessories
            'плитоноска' => [
                'expected_categories' => ['плитоноск', 'plate carrier', 'бронежилет'],
                'exclude_categories' => ['ремен', 'кріплен', 'аксесуар', '2-точков', '1-точков', 'панел'],
                'exclude_title_patterns' => ['ремінь', 'кріплення', 'до плитоноски', 'для плитоноски', 'панель'],
            ],
            'плитоноску' => [
                'expected_categories' => ['плитоноск', 'plate carrier', 'бронежилет'],
                'exclude_categories' => ['ремен', 'кріплен', 'аксесуар', '2-точков', '1-точков', 'панел'],
                'exclude_title_patterns' => ['ремінь', 'кріплення', 'до плитоноски', 'для плитоноски', 'панель'],
            ],
            'бронежилет' => [
                'expected_categories' => ['бронежилет', 'плитоноск', 'жилет', 'vest'],
                'exclude_categories' => ['ремен', 'аксесуар'],
                'exclude_title_patterns' => ['ремінь', 'кріплення'],
            ],
            // Helmets
            'шолом' => [
                'expected_categories' => ['шолом', 'каска', 'helmet'],
                'exclude_categories' => ['кріплен', 'аксесуар', 'кавер'],
                'exclude_title_patterns' => ['кріплення для', 'до шолома', 'на шолом'],
            ],
            'каска' => [
                'expected_categories' => ['шолом', 'каска', 'helmet'],
                'exclude_categories' => ['кріплен', 'аксесуар'],
                'exclude_title_patterns' => ['кріплення'],
            ],
            // Plates
            'бронеплита' => [
                'expected_categories' => ['плита', 'plate', 'бронеплит'],
                'exclude_categories' => ['чохол', 'кавер'],
                'exclude_title_patterns' => ['чохол', 'кавер'],
            ],
            // Boots
            'берци' => [
                'expected_categories' => ['берц', 'черевик', 'boot', 'взутт'],
                'exclude_categories' => [],
                'exclude_title_patterns' => [],
            ],
        ];
        
        // Check if query mentions a specific product type
        $typeConfig = null;
        $queryProductType = null;
        foreach ($productTypeMap as $queryKey => $config) {
            if (str_contains($query, $queryKey) || str_contains($original, $queryKey)) {
                $typeConfig = $config;
                $queryProductType = $queryKey;
                break;
            }
        }
        
        // If no specific product type detected, assume relevant
        if ($typeConfig === null) {
            return ['is_relevant' => true, 'reason' => 'generic_query'];
        }
        
        // Check if any product matches expected type (strict validation)
        $foundCategories = [];
        $matchCount = 0;
        
        foreach ($products as $product) {
            $title = mb_strtolower($product['title'] ?? '');
            $category = mb_strtolower($product['category_path'] ?? '');
            
            $foundCategories[] = $product['category_path'] ?? 'unknown';
            
            // First check exclusions - if product is an accessory, skip it
            $isExcluded = false;
            
            // Check category exclusions
            foreach ($typeConfig['exclude_categories'] as $excludePattern) {
                if (str_contains($category, $excludePattern)) {
                    $isExcluded = true;
                    break;
                }
            }
            
            // Check title exclusions (e.g., "ремінь до плитоноски" is NOT a plate carrier)
            if (!$isExcluded) {
                foreach ($typeConfig['exclude_title_patterns'] as $excludePattern) {
                    if (str_contains($title, $excludePattern)) {
                        $isExcluded = true;
                        break;
                    }
                }
            }
            
            if ($isExcluded) {
                continue; // Don't count this as a match
            }
            
            // Now check if category matches expected type
            foreach ($typeConfig['expected_categories'] as $expectedKeyword) {
                if (str_contains($category, $expectedKeyword)) {
                    $matchCount++;
                    break;
                }
            }
        }
        
        // If less than 30% of results match expected type, consider irrelevant
        $matchRatio = count($products) > 0 ? $matchCount / count($products) : 0;
        
        if ($matchRatio < 0.3) {
            // Build suggestion based on query type
            $suggestions = [
                'плитоноска' => 'На жаль, плитоносок у кольорі мультикам зараз немає в наявності. Перегляньте інші кольори або зверніться до менеджера.',
                'плитоноску' => 'На жаль, плитоносок у кольорі мультикам зараз немає в наявності. Перегляньте інші кольори або зверніться до менеджера.',
                'шолом' => 'Шоломів за вашим запитом не знайдено. Спробуйте: "шолом балістичний", "каска".',
                'каска' => 'Касок за вашим запитом не знайдено. Спробуйте: "шолом балістичний".',
                'бронеплита' => 'Бронеплит за вашим запитом не знайдено. Спробуйте: "бронеплита SAPI", "керамічна плита".',
            ];
            
            return [
                'is_relevant' => false,
                'reason' => 'category_mismatch',
                'match_ratio' => $matchRatio,
                'expected_type' => $queryProductType,
                'found_categories' => array_unique($foundCategories),
                'suggestion' => $suggestions[$queryProductType] ?? 'Спробуйте уточнити назву товару.',
            ];
        }
        
        return [
            'is_relevant' => true,
            'reason' => 'matched',
            'match_ratio' => $matchRatio,
        ];
    }

    private function guessCategoryFromProducts(array $products): ?string
    {
        if (empty($products)) {
            return null;
        }
        
        $categories = array_map(fn($p) => $this->extractMainCategory($p), $products);
        $categoryCounts = array_count_values($categories);
        arsort($categoryCounts);
        
        $topCategory = array_key_first($categoryCounts);
        
        $categoryNames = [
            'plates' => 'бронеплити',
            'helmets' => 'шоломи',
            'plate-carriers' => 'плитоноски',
            'armor' => 'бронезахист',
        ];
        
        return $categoryNames[$topCategory] ?? null;
    }

    /**
     * Extract filters from message (budget, color, etc.)
     */
    private function extractFiltersFromMessage(string $message): array
    {
        $filters = [];
        $lower = mb_strtolower($message);
        
        // Budget extraction: "до 5000", "до 5000 грн", "до 5к"
        if (preg_match('/до\s+(\d+)(?:\s*к)?(?:\s*грн)?/u', $lower, $matches)) {
            $filters['budget_max'] = (int)$matches[1];
            if ($filters['budget_max'] < 100) {
                $filters['budget_max'] *= 1000; // "до 5к" → 5000
            }
        }
        
        // Budget extraction: "від 1000", "від 1000 грн"
        if (preg_match('/від\s+(\d+)(?:\s*к)?(?:\s*грн)?/u', $lower, $matches)) {
            $filters['budget_min'] = (int)$matches[1];
            if ($filters['budget_min'] < 100) {
                $filters['budget_min'] *= 1000;
            }
        }
        
        // Color extraction
        $colors = [
            'чорний' => 'black',
            'чорна' => 'black',
            'чорне' => 'black',
            'зелений' => 'green',
            'зелена' => 'green',
            'зелене' => 'green',
            'олива' => 'olive',
            'олівковий' => 'olive',
            'мультикам' => 'multicam',
            'пісочний' => 'sand',
            'койот' => 'coyote',
        ];
        
        foreach ($colors as $ukr => $eng) {
            if (str_contains($lower, $ukr)) {
                $filters['color'] = $eng;
                break;
            }
        }
        // Size extraction (EU sizes 35-49)
        if (preg_match('/(розмір|size)\s*(\d{2})/u', $lower, $m)) {
            $size = (int) $m[2];
            if ($size >= 35 && $size <= 49) {
                $filters['size'] = $size;
            }
        }
        // Standalone two-digit size in footwear context
        if (!isset($filters['size'])) {
            if (preg_match('/\b(\d{2})\b/u', $lower, $m)) {
                $size = (int) $m[1];
                $footwearWords = ['берц', 'черевик', 'взутт', 'ботин', 'boots'];
                foreach ($footwearWords as $w) {
                    if ($size >= 35 && $size <= 49 && str_contains($lower, $w)) {
                        $filters['size'] = $size;
                        break;
                    }
                }
            }
        }
        
        return $filters;
    }

    private function detectCategoryFromMessage(string $message): ?string
    {
        $m = mb_strtolower($message);
        if (str_contains($m, 'плитоноск') || str_contains($m, 'carrier')) { return 'плитоноски'; }
        if (str_contains($m, 'плит') || str_contains($m, 'sapi') || str_contains($m, 'esapi')) { return 'бронеплити'; }
        if (str_contains($m, 'шолом') || str_contains($m, 'каск') || str_contains($m, 'helmet')) { return 'шоломи'; }
        if (str_contains($m, 'берц') || str_contains($m, 'черевик') || str_contains($m, 'взутт') || str_contains($m, 'boot')) { return 'взуття'; }
        return null;
    }

    /**
     * Detect if message is a follow-up asking for details about the last product
     */
    private function isDetailsFollowUp(string $message): bool
    {
        $m = mb_strtolower($message);
        $patterns = ['розкажи про', 'розкажи', 'опис', 'що за', 'характеристик', 'деталь', 'розмір', 'параметр'];
        foreach ($patterns as $p) {
            if (str_contains($m, $p)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle follow-up request for details about a product.
     * Searches Meilisearch by product name mentioned in message.
     * Example: "розкажи про схід 24" → search "схід 24" → verify 80%+ match → show details
     */
    private function handleProductDetailsFollowUp(string $originalMessage, array $sessionContext): array
    {
        // Extract search keywords from message (e.g., "розкажи про схід 24" → "схід 24")
        $messageKeywords = $this->extractSearchTermsFromMessage($originalMessage);
        if (empty($messageKeywords)) {
            return $this->handleNoResults($originalMessage, $originalMessage);
        }

        $searchQuery = implode(' ', $messageKeywords);
        
        // Search Meilisearch for product by name
        try {
            $results = $this->searchTool->search($searchQuery, [], 5);
            if (empty($results)) {
                return $this->handleNoResults($originalMessage, $searchQuery);
            }

            // Take top result
            $topResult = $results[0];
            
            // Confidence check: does the title contain enough keywords?
            $confidence = $this->calculateTitleMatchConfidence($topResult['title'] ?? '', $messageKeywords);
            
            Log::info('AgentOrchestrator: follow-up product match', [
                'query_keywords' => $messageKeywords,
                'found_title' => $topResult['title'],
                'confidence' => $confidence,
            ]);

            // Require 60%+ match confidence
            if ($confidence < 0.6) {
                Log::info('AgentOrchestrator: confidence too low, rejecting match', [
                    'confidence' => $confidence,
                    'threshold' => 0.6,
                ]);
                return $this->handleNoResults($originalMessage, $searchQuery);
            }

            // Fetch full product details
            $products = $this->detailsTool->getCards([$topResult['id']], 1);
            if (empty($products)) {
                return $this->handleNoResults($originalMessage, $searchQuery);
            }

            $product = $products[0];
            $message = $this->buildProductDetailMessage($product);

            return [
                'message' => $message,
                'products' => $products,
                'meta' => [
                    'intent' => 'product_details',
                    'is_followup' => true,
                    'confidence' => $confidence,
                    'chosen_ids' => [$product['id']],
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('AgentOrchestrator: follow-up search failed', ['error' => $e->getMessage()]);
            return $this->handleNoResults($originalMessage, $searchQuery ?? $originalMessage);
        }
    }

    /**
     * Calculate confidence that product title matches the keywords.
     * 1) First checks for exact substring match of search query → 100%
     * 2) Then filters generic terms and counts specific keyword matches
     * Returns 0.0 to 1.0 where 1.0 = perfect match
     */
    private function calculateTitleMatchConfidence(string $title, array $keywords): float
    {
        if (empty($keywords)) {
            return 0.0;
        }

        $titleLower = mb_strtolower($title);
        
        // Step 1: Exact substring check - if all non-generic keywords appear as continuous substring → 100%
        $genericTerms = [
            'плитоноск', 'шолом', 'каск', 'берц', 'черевик', 'куртк', 'штан',
            'жилет', 'рюкзак', 'підсумок', 'рукавиц', 'окуляр', 'взутт',
            'helmet', 'plate', 'carrier', 'armor', 'jacket', 'pants', 'boots',
        ];
        
        $specificKeywords = [];
        foreach ($keywords as $kw) {
            $isGeneric = false;
            foreach ($genericTerms as $generic) {
                if (str_contains($kw, $generic) || str_contains($generic, $kw)) {
                    $isGeneric = true;
                    break;
                }
            }
            if (!$isGeneric) {
                $specificKeywords[] = $kw;
            }
        }

        // If user searches for generic category only (e.g., "плитоноска"), any product matches
        if (empty($specificKeywords)) {
            return 1.0;
        }

        // Check if all specific keywords appear as substring
        $queryPhrase = implode(' ', $specificKeywords);
        if (str_contains($titleLower, $queryPhrase)) {
            return 1.0;
        }

        // Step 2: Token-based match - count how many specific keywords found
        $matches = 0;
        foreach ($specificKeywords as $kw) {
            if (str_contains($titleLower, $kw)) {
                $matches++;
            }
        }

        return $matches / count($specificKeywords);
    }

    /**
     * Extract key search terms from message (remove stop-words like "розкажи про", "опис")
     */
    private function extractSearchTermsFromMessage(string $message): array
    {
        $stopWords = ['розкажи', 'про', 'опис', 'що', 'за', 'характеристик', 'деталь', 'розмір', 'параметр', 'якої', 'для'];
        $words = preg_split('/\s+/u', mb_strtolower(trim($message))) ?: [];
        
        $filtered = [];
        foreach ($words as $word) {
            $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
            if (!empty($clean) && !in_array($clean, $stopWords) && mb_strlen($clean) > 1) {
                $filtered[] = $clean;
            }
        }
        
        return $filtered;
    }

    /**
     * Build detailed message about a single product using AI for natural formatting.
     * AI works ONLY with real data from product (no hallucinations).
     */
    private function buildProductDetailMessage(array $p): string
    {
        $title = $p['title'] ?? 'Товар';
        $price = $this->formatPrice($p['price'] ?? null);
        $category = $p['category_path'] ?? '';
        $chars = $p['characteristics'] ?? [];
        $desc = trim((string) ($p['description'] ?? ''));

        // Try AI formatting for natural language
        try {
            $config = config('services.openai', []);
            $apiKey = $config['key'] ?? null;
            
            if ($apiKey) {
                $charsText = is_array($chars) && !empty($chars) 
                    ? $this->formatCharacteristics($chars, 8) 
                    : '';
                
                $descSnippet = $desc !== '' 
                    ? mb_substr($desc, 0, 500) 
                    : '';

                $prompt = "
Ти — асистент магазину військового спорядження. Сформуй короткий інформативний опис товару для клієнта.

ВАЖЛИВО: використовуй ТІЛЬКИ надану інформацію, НЕ вигадуй нічого.

Товар: {$title}
Ціна: {$price}
Категорія: {$category}

Характеристики:
{$charsText}

Опис:
{$descSnippet}

Завдання: напиши 3-5 коротких речень про цей товар природною мовою (без bullet points). Використовуй ТІЛЬКИ факти з даних вище.
Закінчи питанням: чи потрібно порівняти з іншим товаром або показати аксесуари?
";

                $response = Http::timeout(5)->withToken($apiKey)
                    ->post(rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions', [
                        'model' => $config['model'] ?? 'gpt-4',
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'temperature' => 0.3,
                        'max_tokens' => 250,
                    ]);

                $data = $response->json();
                if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
                    $aiMessage = trim($data['choices'][0]['message']['content']);
                    if (!empty($aiMessage)) {
                        Log::info('AgentOrchestrator: AI formatted product details', ['product_id' => $p['id']]);
                        return $aiMessage;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AgentOrchestrator: AI formatting failed, using fallback', ['error' => $e->getMessage()]);
        }

        // Fallback: structured format
        $lines = [];
        $lines[] = $title;
        $lines[] = $price;

        if (!empty($category)) {
            $lines[] = 'Категорія: ' . $category;
        }

        if (is_array($chars) && !empty($chars)) {
            $charLines = $this->formatCharacteristics($chars, 6);
            if (!empty($charLines)) {
                $lines[] = 'Характеристики:';
                $lines[] = $charLines;
            }
        }

        if ($desc !== '') {
            $summary = $this->summarizeDescription($desc, 8, 900);
            if ($summary !== '') {
                $lines[] = 'З опису:';
                $lines[] = $summary;
            }
        }

        $lines[] = "\nПотрібно порівняти з іншим товаром або подивитися аксесуари?";

        return implode("\n", $lines);
    }

    /**
     * Build a short bullet summary from product description without hallucinations.
     */
    private function summarizeDescription(string $desc, int $maxBullets = 6, int $maxChars = 900): string
    {
        $trimmed = trim($desc);
        if ($trimmed === '') {
            return '';
        }

        // Cap length to avoid flooding chat
        if (mb_strlen($trimmed) > $maxChars) {
            $trimmed = mb_substr($trimmed, 0, $maxChars);
        }

        // Try newline-separated blocks first
        $parts = preg_split('/[\r\n]+/u', $trimmed) ?: [];
        $bullets = [];
        foreach ($parts as $part) {
            $clean = trim($part, " \t-•");
            if ($clean === '') {
                continue;
            }
            $bullets[] = '- ' . $clean;
            if (count($bullets) >= $maxBullets) {
                break;
            }
        }

        // Fallback: split by sentences if no newline structure was found
        if (empty($bullets)) {
            $sentences = preg_split('/(?<=[.!?])\s+/u', $trimmed) ?: [];
            foreach ($sentences as $s) {
                $clean = trim($s, " \t-•");
                if ($clean === '') {
                    continue;
                }
                $bullets[] = '- ' . $clean;
                if (count($bullets) >= $maxBullets) {
                    break;
                }
            }
        }

        return implode("\n", $bullets);
    }
}
