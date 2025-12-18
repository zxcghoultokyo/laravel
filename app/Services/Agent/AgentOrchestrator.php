<?php

namespace App\Services\Agent;

use App\Services\Ai\AiRouter;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Agent\Tools\DeduperTool;
use App\Services\Agent\Tools\AccessoryFilterTool;
use App\Services\Agent\Tools\AiRerankTool;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    public function __construct(
        private AiRouter $aiRouter,
        private MeiliProductSearchTool $searchTool,
        private ProductDetailsTool $detailsTool,
        private DeduperTool $deduperTool,
        private AccessoryFilterTool $accessoryFilterTool,
        private AiRerankTool $rerankTool
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
        
        Log::info('AgentOrchestrator: plan created', $plan);

        // Step 2: Execute based on intent
        $result = match($plan['intent']) {
            'product_search' => $this->handleProductSearch($message, $plan, $context),
            'order_status' => $this->handleOrderStatus($message, $plan, $context),
            'faq' => $this->handleFaq($message, $plan, $context),
            'smalltalk' => $this->handleSmallTalk($message, $plan, $context),
            default => $this->handleUnknown($message, $plan, $context),
        };

        Log::info('AgentOrchestrator: result generated', [
            'intent' => $plan['intent'],
            'products_count' => count($result['products'] ?? []),
            'message_length' => strlen($result['message'] ?? '')
        ]);

        return $result;
    }

    /**
     * Create execution plan using AI
     * Calls OpenAI once to understand intent + extract search hints
     */
    private function createPlan(string $message, array $context): array
    {
        // Use existing AiRouter for intent classification
        $classification = $this->aiRouter->classify($message);
        
        // Normalize intent to lowercase
        $intent = strtolower($classification['intent'] ?? 'unknown');
        
        // Normalize search query if product-related
        $searchQuery = null;
        $filters = [];
        $ambiguous = false;
        
        if ($intent === 'product_search') {
            $normalized = $this->aiRouter->normalizeSearchQuery($message);
            
            // normalizeSearchQuery returns a string, not array
            if (is_string($normalized)) {
                $searchQuery = $normalized;
            } else {
                $searchQuery = $normalized['query'] ?? $message;
                $filters = $normalized['filters'] ?? [];
            }
            
            // Extract filters from original message (budget, color, etc.)
            $filters = $this->extractFiltersFromMessage($message);
            
            // Check if query is ambiguous (needs clarification but AFTER showing products)
            $ambiguous = $this->detectAmbiguity($message, $searchQuery, $filters);
        }

        Log::info('AgentOrchestrator: plan created', [
            'intent' => $intent,
            'search_query' => $searchQuery,
            'filters' => $filters,
            'ambiguous' => $ambiguous,
            'confidence' => $classification['confidence'] ?? 0.8,
        ]);

        return [
            'intent' => $intent,
            'search_query' => $searchQuery,
            'filters' => $filters,
            'ambiguous' => $ambiguous,
            'confidence' => $classification['confidence'] ?? 0.8,
        ];
    }

    /**
     * Handle product search intent
     * ALWAYS show products, questions come after
     */
    private function handleProductSearch(string $originalMessage, array $plan, array $context): array
    {
        $searchQuery = $plan['search_query'] ?? $originalMessage;
        $filters = $plan['filters'] ?? [];
        
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
            return $this->handleNoResults($originalMessage, $searchQuery);
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
        
        // Step 3.5: AI Re-ranking (intelligent relevance scoring)
        $startTime = microtime(true);
        $reranked = $this->rerankTool->rerank($filtered, $searchQuery, $filters, 10);
        $debug['steps'][] = [
            'step' => 'ai_rerank',
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'before' => count($filtered),
            'after' => count($reranked),
        ];
        
        // Step 4: Get top 10 products with full details
        $startTime = microtime(true);
        $topIds = array_column($reranked, 'id');
        $products = $this->detailsTool->getCards($topIds, 10);
        $debug['steps'][] = [
            'step' => 'get_details',
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'ids' => $topIds,
            'products_fetched' => count($products),
        ];
        
        // Collect articles for debug
        $chosenArticles = array_column($products, 'article');
        
        // Step 5: Generate response message
        $message = $this->generateProductMessage($products, $originalMessage, $plan);
        
        // Step 6: Add follow-up question if ambiguous (AFTER products)
        if ($plan['ambiguous'] && count($products) > 5) {
            $message .= "\n\n" . $this->generateFollowUpQuestion($originalMessage, $searchQuery, $filters, $products);
        }

        return [
            'message' => $message,
            'products' => $products,
            'meta' => [
                'intent' => 'product_search',
                'ambiguous' => $plan['ambiguous'],
                'refined_query' => $searchQuery,
                'filters' => $filters,
                'chosen_ids' => $topIds,
                'chosen_articles' => $chosenArticles,
                'search_debug' => $debug,
            ],
        ];
    }

    /**
     * Handle "яку краще взяти?" style questions
     * Show products + explain 3 best options
     */
    private function generateProductMessage(array $products, string $originalMessage, array $plan): string
    {
        $isAdviceRequest = $this->isAdviceRequest($originalMessage);
        
        if ($isAdviceRequest && count($products) >= 3) {
            // Use AI to explain top 3 options
            return $this->explainTopOptions($products, $originalMessage);
        }
        
        // Standard response
        $count = count($products);
        $categoryHint = $this->guessCategoryFromProducts($products);
        
        if ($categoryHint) {
            return "Ось, що маємо по категорії «{$categoryHint}» 👇";
        }
        
        return "Ось варіанти 👇";
    }

    /**
     * Explain top 3 products with pros/cons
     */
    private function explainTopOptions(array $products, string $originalMessage): string
    {
        $top3 = array_slice($products, 0, 3);
        
        $options = [];
        $labels = ['A', 'B', 'C'];
        
        foreach ($top3 as $idx => $product) {
            $label = $labels[$idx] ?? ($idx + 1);
            $options[] = "({$label}) {$product['title']} — {$product['price']} ₴";
        }
        
        $explanation = "Ось 3 найкращі варіанти:\n\n" . implode("\n", $options);
        $explanation .= "\n\nВибирай за бюджетом і характеристиками 👇";
        
        return $explanation;
    }

    /**
     * Generate follow-up clarification question using AI
     * Only after products are shown AND есть разнообразие
     */
    private function generateFollowUpQuestion(string $originalMessage, string $searchQuery, array $filters, array $products): string
    {
        // Збираємо контекст про товари
        $productTitles = array_slice(array_column($products, 'title'), 0, 5);
        $categories = array_unique(array_column($products, 'category_path'));
        $colors = array_filter(array_unique(array_column($products, 'color')));
        $prices = array_column($products, 'price');
        
        $productsContext = implode(', ', array_slice($productTitles, 0, 3));
        $categoryContext = implode(', ', array_slice($categories, 0, 2));
        $colorContext = !empty($colors) ? implode(', ', array_slice($colors, 0, 3)) : 'різні';
        $priceRange = !empty($prices) ? round(min($prices)) . '-' . round(max($prices)) . ' грн' : '';
        
        $prompt = "Користувач шукав: \"{$searchQuery}\"
Знайдено товари: {$productsContext}
Категорії: {$categoryContext}
Кольори: {$colorContext}
Ціновий діапазон: {$priceRange}

Згенеруй ОДНЕ коротке уточнююче питання (до 15 слів) щоб допомогти вибрати товар.
Питай тільки про те, де є реальна різноманітність у знайдених товарах.

Поверни ТІЛЬКИ текст питання українською, без лапок, без пояснень.
Формат: 'Щоб точніше підібрати: ...'";

        try {
            $response = $this->aiRouter->callOpenAI($prompt, 0.7, 60);
            $question = trim($response, " \n\r\t\"'");
            
            // Fallback якщо AI повернула щось дивне
            if (empty($question) || mb_strlen($question) > 150) {
                return "Щоб підібрати точніше: є побажання щодо характеристик?";
            }
            
            return $question;
        } catch (\Exception $e) {
            Log::warning('generateFollowUpQuestion: AI failed', ['error' => $e->getMessage()]);
            return "Щоб підібрати точніше: є побажання щодо характеристик?";
        }
    }

    /**
     * Handle no results scenario
     */
    private function handleNoResults(string $originalMessage, string $searchQuery): array
    {
        // Пропонуємо популярні категорії
        $suggestions = [
            'Спробуйте:',
            '• Плитоноски та розвантажки',
            '• Тактичні каски та шоломи',
            '• Куртки та форма',
            '• Черевики та взуття',
            '• Рюкзаки та сумки',
        ];
        
        $suggestionText = "\n\n" . implode("\n", $suggestions);
        
        return [
            'message' => "Вибачте, не знайшов товарів за запитом «{$searchQuery}».{$suggestionText}\n\nАбо опишіть детальніше що саме вам потрібно.",
            'products' => [],
            'meta' => [
                'intent' => 'product_search',
                'ambiguous' => false,
                'refined_query' => $searchQuery,
                'filters' => [],
                'chosen_ids' => [],
                'search_debug' => ['candidates_found' => 0],
            ],
        ];
    }

    /**
     * Handle order status requests
     */
    private function handleOrderStatus(string $message, array $plan, array $context): array
    {
        // Extract order number from message
        preg_match('/\d{3,}/', $message, $matches);
        $orderNumber = $matches[0] ?? null;
        
        if (!$orderNumber) {
            return [
                'message' => "Щоб перевірити статус замовлення, напишіть, будь ласка, номер замовлення (наприклад, «статус 12345»).",
                'products' => [],
                'meta' => ['intent' => 'order_status', 'ambiguous' => true],
            ];
        }
        
        // Here should be real order lookup via HoroshopClient
        // For now, placeholder response
        return [
            'message' => "На жаль, я поки не можу перевірити статус замовлення #{$orderNumber}. Зверніться, будь ласка, до служби підтримки за номером телефону або email.",
            'products' => [],
            'meta' => ['intent' => 'order_status', 'order_number' => $orderNumber],
        ];
    }

    /**
     * Handle FAQ requests
     */
    private function handleFaq(string $message, array $plan, array $context): array
    {
        // Simple FAQ responses - should be moved to database
        $faqResponses = [
            'доставка' => "Доставка здійснюється Новою Поштою по всій Україні. Термін доставки 1-3 дні. Вартість згідно тарифів перевізника.",
            'оплата' => "Оплата: накладений платіж, оплата на карту, готівка при самовивозі.",
            'повернення' => "Повернення товару протягом 14 днів згідно Закону про захист прав споживачів.",
        ];
        
        $lowerMessage = mb_strtolower($message);
        
        foreach ($faqResponses as $keyword => $response) {
            if (str_contains($lowerMessage, $keyword)) {
                return [
                    'message' => $response,
                    'products' => [],
                    'meta' => ['intent' => 'faq', 'topic' => $keyword],
                ];
            }
        }
        
        return [
            'message' => "Якщо у вас є питання щодо доставки, оплати чи повернення — напишіть конкретніше. Або зверніться до підтримки.",
            'products' => [],
            'meta' => ['intent' => 'faq', 'ambiguous' => true],
        ];
    }

    /**
     * Handle small talk
     */
    private function handleSmallTalk(string $message, array $plan, array $context): array
    {
        return [
            'message' => "Радий спілкуватися! Якщо потрібна допомога з вибором екіпірування — пишіть, підкажу.",
            'products' => [],
            'meta' => ['intent' => 'smalltalk'],
        ];
    }

    /**
     * Handle unknown intent
     */
    private function handleUnknown(string $message, array $plan, array $context): array
    {
        return [
            'message' => "Не зовсім зрозумів запит. Можете уточнити: шукаєте товар, питаєте про замовлення чи потрібна інша інформація?",
            'products' => [],
            'meta' => ['intent' => 'unknown', 'ambiguous' => true],
        ];
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

    private function isAdviceRequest(string $message): bool
    {
        $adviceKeywords = ['яку', 'який', 'краще', 'порадь', 'підкажи', 'допоможи вибрати', 'що взяти'];
        $lowerMessage = mb_strtolower($message);
        
        foreach ($adviceKeywords as $keyword) {
            if (str_contains($lowerMessage, $keyword)) {
                return true;
            }
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
        
        return $filters;
    }
}
