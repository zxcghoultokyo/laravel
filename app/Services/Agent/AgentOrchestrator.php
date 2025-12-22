<?php

namespace App\Services\Agent;

use App\Services\Ai\AiRouter;
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
            'productsearch' => $this->handleProductSearch($message, $plan, $context),
            'orderstatus' => $this->handleOrderStatus($message, $plan, $context),
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
        
        // Normalize intent to lowercase and replace underscores with no separator
        $intent = str_replace('_', '', strtolower($classification['intent'] ?? 'unknown'));
        
        // Normalize search query if product-related
        $searchQuery = null;
        $filters = [];
        $ambiguous = false;
        
        // Fast-path: if user message clearly contains a brand word, force product search
        if ($this->containsBrandWord($message)) {
            $intent = 'productsearch';
        }

        if ($intent === 'productsearch') {
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
        
        // Step 5: Generate response message
        $message = $this->generateProductMessage($products, $originalMessage, $plan);
        
        // Step 6: Add follow-up question if ambiguous (AFTER products)
        if ($plan['ambiguous'] && count($products) > 5) {
            $question = $this->generateFollowUpQuestion($originalMessage, $searchQuery, $filters, $products);
            if (!empty($question)) {
                $message .= "\n\n" . $question;
            }
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
        
        $prompt = "Магазин Contractor — тактичне військове спорядження для ЗСУ, правоохоронців, добровольців.

Користувач шукав: \"{$searchQuery}\"
Знайдено товари: {$productsContext}
Категорії: {$categoryContext}
Кольори: {$colorContext}
Ціновий діапазон: {$priceRange}

Згенеруй ОДНЕ природне уточнююче питання (до 20 слів).

Питай про те, що допоможе вибрати конкретний товар з цього списку.
Ніяких загальних питань! Дивися на реальні товари.

Будь природним і корисним. Не використовуй шаблони.
Поверни ТІЛЬКИ текст питання українською, без лапок.";

        // Перевіряємо чи є реальна різноманітність
        if (!$this->hasProductDiversity($products)) {
            return ''; // Не питаємо якщо немає про що питати
        }
        
        try {
            $response = $this->aiRouter->callOpenAI($prompt, 0.7, 60);
            $question = trim($response, " \n\r\t\"'");
            
            // Fallback якщо AI повернула щось дивне
            if (empty($question) || mb_strlen($question) > 150) {
                return ''; // Краще нічого не питати, ніж показувати шаблон
            }
            
            return $question;
        } catch (\Exception $e) {
            Log::warning('generateFollowUpQuestion: AI failed', ['error' => $e->getMessage()]);
            return ''; // Просто не питаємо, якщо AI не працює
        }
    }

    /**
     * Check if products have real diversity worth asking questions about
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
    private function handleNoResults(string $originalMessage, string $searchQuery): array
    {
        return [
            'message' => "Вибачте, не знайшов товарів за запитом «{$searchQuery}». Спробуйте інші ключові слова або опишіть що саме вам потрібно.",
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
        // Parse query for order criteria
        $criteria = $this->orderSearchService->parseQuery($message);

        $settings = WidgetSettings::first();
        $supportLine = $this->buildSupportLine($settings);

        // If user is angry/rude, de-escalate politely
        if ($this->isAngry($message)) {
            return [
                'message' => "Я бот і хочу допомогти. Напишіть номер замовлення, і я перевірю статус. " . ($supportLine ?: ''),
                'products' => [],
                'meta' => ['intent' => 'order_status', 'angry' => true],
            ];
        }

        // If user described a problem, return support contacts immediately
        if ($this->deliveryTrackingService->isProblemReport($message)) {
            $issue = $this->deliveryTrackingService->getIssueResolutionInfo();
            return [
                'message' => $issue['message'],
                'products' => [],
                'meta' => [
                    'intent' => 'order_status',
                    'order_issue' => true,
                ],
            ];
        }

        if (empty($criteria)) {
            return [
                'message' => "Щоб перевірити статус замовлення, напишіть, будь ласка, номер замовлення. Наприклад: статус 12345",
                'products' => [],
                'meta' => ['intent' => 'order_status', 'ambiguous' => true],
            ];
        }

        $criteria['limit'] = $plan['order_limit'] ?? 5;

        try {
            $searchResult = $this->orderSearchService->search($criteria);
        } catch (\Throwable $e) {
            return [
                'message' => "Дякую. Ви питаєте про замовлення №" . ($criteria['order_id'] ?? '...') . ". Наразі я ще не маю прямого доступу до статусів. " . ($supportLine ?: ''),
                'products' => [],
                'meta' => ['intent' => 'order_status', 'error' => 'search_failed'],
            ];
        }

        $total = $searchResult['total'] ?? 0;
        $orders = $searchResult['orders'] ?? [];
        $searchType = $searchResult['search_type'] ?? 'none';
        $orderIdText = $criteria['order_id'] ?? null;

        // MVP / no integration scenario
        if ($total === 0 && $searchType === 'none') {
            return [
                'message' => "Дякую. Ви питаєте про замовлення №" . ($orderIdText ?? '...') . ". Наразі я ще не маю прямого доступу до статусів замовлень. " . ($supportLine ?: ''),
                'products' => [],
                'meta' => ['intent' => 'order_status', 'found' => 0, 'no_integration' => true, 'criteria' => $criteria],
            ];
        }

        if ($total === 0) {
            return [
                'message' => "Не вдалося знайти замовлення №" . ($orderIdText ?? '...') . ". Перевірте номер або зверніться до служби підтримки. " . ($supportLine ?: ''),
                'products' => [],
                'meta' => ['intent' => 'order_status', 'found' => 0, 'criteria' => $criteria],
            ];
        }

        // Enrich with delivery tracking info
        $orders = array_map(function ($order) {
            $delivery = $this->deliveryTrackingService->formatDeliveryInfo($order);
            $order['delivery_tracking'] = $delivery;
            return $order;
        }, $orders);

        $first = $orders[0];
        $delivery = $first['delivery_tracking'] ?? [];

        $msgParts = [];
        $orderIdOut = $first['id'] ?? ($orderIdText ?? '');

        if ($orderIdOut !== '') {
            $msgParts[] = "Замовлення №{$orderIdOut}";
        }

        $statusText = $delivery['status'] ?? null;
        $ttn = $delivery['nova_poshta_ttn'] ?? null;
        $tracking = $delivery['tracking_url'] ?? null;

        if (!empty($ttn)) {
            $msgParts[] = "Замовлення відправлено\nТТН: {$ttn}" . (!empty($tracking) ? "\nВідстежити: {$tracking}" : '');
            $msgParts[] = "Можете відстежити посилку у додатку або на сайті перевізника.";
        } elseif (!empty($statusText)) {
            $statusLower = mb_strtolower($statusText);
            $msgParts[] = "Статус: {$statusText}";
            
            if (str_contains($statusLower, 'доставлено') || str_contains($statusLower, 'delivered')) {
                $msgParts[] = "Якщо все отримали — дякуємо за покупку! Якщо є питання — напишіть.";
            } elseif (str_contains($statusLower, 'не доставлено') || str_contains($statusLower, 'відміна') || str_contains($statusLower, 'скасов')) {
                $msgParts[] = "Виникла проблема з доставкою. " . ($supportLine ?: 'Зв\'яжіться з підтримкою для уточнення.');
            } elseif (str_contains($statusLower, 'новий') || str_contains($statusLower, 'new')) {
                $msgParts[] = "Замовлення прийнято в обробку. Незабаром почнемо готувати до відправки.";
            } elseif (str_contains($statusLower, 'оброб') || str_contains($statusLower, 'processing')) {
                $msgParts[] = "Готуємо ваше замовлення до відправки. Після відправки ви отримаєте ТТН для відстеження.";
            } elseif (str_contains($statusLower, 'доставляється') || str_contains($statusLower, 'в дорозі')) {
                $msgParts[] = "Замовлення в дорозі. Очікуйте повідомлення від перевізника.";
            }
        } else {
            $msgParts[] = "Статус доставки зараз недоступний. " . ($supportLine ?: '');
        }

        $msgParts[] = "Якщо треба — можу перевірити інше замовлення. Напишіть номер або телефон.";

        return [
            'message' => implode("\n\n", $msgParts),
            'products' => [],
            'meta' => [
                'intent' => 'order_status',
                'found' => $searchResult['total'],
                'orders' => $orders,
                'criteria' => $criteria,
            ],
        ];
    }

    private function buildSupportLine(?WidgetSettings $settings): string
    {
        $parts = [];
        if (!empty($settings?->shop_phone)) {
            $parts[] = 'Телефон: ' . $settings->shop_phone;
        }
        if (!empty($settings?->callback_form_url)) {
            $parts[] = 'Заявка: ' . $settings->callback_form_url;
        }
        return implode(' | ', $parts);
    }

    private function isAngry(string $message): bool
    {
        $keywords = [
            'де моя посилка', 'скільки можна', 'ви знущаєтесь', 'обман', 'шахрай', 'лохотрон',
            'мошен', 'кинули', 'ненормальні', 'дурні', 'погано', 'ненавид', 'гнів', 'злость',
            'дебіл', 'придур', 'туп', 'fuck', 'shit', 'idiot'
        ];
        $m = mb_strtolower($message);
        foreach ($keywords as $kw) {
            if (str_contains($m, $kw)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle FAQ requests
     */
    private function handleFaq(string $message, array $plan, array $context): array
    {
        $settings = WidgetSettings::first();
        // Disable Horoshop FAQ/pages: use ONLY admin custom content
        $useHoroshop = false;
        $useCustom = ($settings?->enable_faq_custom_content ?? true) === true;

        $lowerMessage = mb_strtolower($message);

        // If custom FAQ enabled, try to answer directly by keywords (via AI summarization of ingested content)
        if ($useCustom) {
            $map = [
                'оплата' => ['text' => $settings?->faq_payment_delivery_text, 'url' => $settings?->faq_payment_delivery_url, 'title' => 'Оплата і доставка'],
                'доставка' => ['text' => $settings?->faq_payment_delivery_text, 'url' => $settings?->faq_payment_delivery_url, 'title' => 'Оплата і доставка'],
                'повернення' => ['text' => $settings?->faq_returns_text, 'url' => $settings?->faq_returns_url, 'title' => 'Обмін та повернення'],
                'обмін' => ['text' => $settings?->faq_returns_text, 'url' => $settings?->faq_returns_url, 'title' => 'Обмін та повернення'],
                'контакти' => ['text' => $settings?->faq_contacts_text, 'url' => $settings?->faq_contacts_url, 'title' => 'Контактна інформація'],
                'про нас' => ['text' => $settings?->faq_about_text, 'url' => $settings?->faq_about_url, 'title' => 'Про нас'],
            ];

            foreach ($map as $keyword => $info) {
                if (str_contains($lowerMessage, $keyword)) {
                    $contextText = trim((string) ($info['text'] ?? ''));
                    $url = $info['url'] ?? null;
                    if (!empty($contextText)) {
                        try {
                            $topic = 'general';
                            if ($keyword === 'доставка') { $topic = 'delivery'; }
                            elseif ($keyword === 'оплата') { $topic = 'payment'; }
                            elseif ($keyword === 'повернення' || $keyword === 'обмін') { $topic = 'returns'; }
                            elseif ($keyword === 'контакти') { $topic = 'contacts'; }

                            if ($topic === 'delivery') {
                                $prompt = "Користувач питає: \"{$message}\"\n\n" .
                                    "Контекст (з FAQ сторінки магазину, очищений):\n" . $contextText . "\n\n" .
                                    "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі ТІЛЬКИ про доставку.\n" .
                                    "Включи рядками: хто доставляє; куди; терміни (якщо є); вартість (якщо згадано — наприклад, за тарифами перевізника).\n" .
                                    "Завершуй одним простим CTA: 'Можу підібрати товар і перевірити наявність — написати?'.\n" .
                                    "Не вигадуй фактів. Користуйся лише контекстом. Не посилайся на менеджерів/підтримку. Макс 500 символів.";
                            } elseif ($topic === 'payment') {
                                $prompt = "Користувач питає: \"{$message}\"\n\n" .
                                    "Контекст (з FAQ сторінки магазину, очищений):\n" . $contextText . "\n\n" .
                                    "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі ТІЛЬКИ про оплату.\n" .
                                    "Включи рядками: доступні способи оплати; що найчастіше використовують (якщо згадано); чи безпечно (лише якщо є в контексті).\n" .
                                    "Завершуй CTA: 'Готові оформити? Можу підібрати товар — написати?'.\n" .
                                    "Не вигадуй фактів. Не юридична мова. Макс 500 символів.";
                            } elseif ($topic === 'returns') {
                                $prompt = "Користувач питає: \"{$message}\"\n\n" .
                                    "Контекст (з FAQ сторінки магазину, очищений):\n" . $contextText . "\n\n" .
                                    "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі ТІЛЬКИ про повернення.\n" .
                                    "Включи рядками: чи можливе повернення; термін; ключові умови (1-3 пункти).\n" .
                                    "Завершуй CTA: 'Потрібна допомога з поверненням — написати?'.\n" .
                                    "Не вигадуй фактів. Макс 450 символів.";
                            } elseif ($topic === 'contacts') {
                                $prompt = "Користувач питає: \"{$message}\"\n\n" .
                                    "Контекст (з FAQ сторінки магазину, очищений):\n" . $contextText . "\n\n" .
                                    "Завдання: дай КОРОТКУ відповідь українською БЕЗ Markdown/емодзі ТІЛЬКИ з контактами (1-3 способи).\n" .
                                    "Завершуй CTA: 'Написати тут?'. Макс 300 символів.";
                            } else {
                                $prompt = "Користувач питає: \"{$message}\"\n\n" .
                                    "Контекст (очищений):\n" . $contextText . "\n\n" .
                                    "Дай коротку корисну відповідь одним блоком, без Markdown/емодзі, з CTA наприкінці. Макс 500 символів.";
                            }

                            $reply = $this->aiRouter->callOpenAI($prompt, 0.15, 350);
                            $reply = trim($reply);
                            if (empty($reply)) {
                                $reply = mb_substr($contextText, 0, 1000);
                            } elseif (mb_strlen($reply) > 1600) {
                                $reply = mb_substr($reply, 0, 1600);
                            }

                            if (!empty($url)) {
                                $reply .= "\n\nПосилання: " . $url;
                            }

                            return [
                                'message' => $reply,
                                'products' => [],
                                'meta' => ['intent' => 'faq', 'topic' => $keyword],
                            ];
                        } catch (\Throwable $e) {
                            $fallback = !empty($url) ? ($info['title'] . ": " . $url) : ($info['title'] ?? 'FAQ');
                            return [
                                'message' => $fallback,
                                'products' => [],
                                'meta' => ['intent' => 'faq', 'topic' => $keyword, 'error' => 'ai-failed'],
                            ];
                        }
                    }
                    // No ingested text – return link or title
                    $fallback = !empty($url) ? ($info['title'] . ": " . $url) : ($info['title'] ?? 'FAQ');
                    return [
                        'message' => $fallback,
                        'products' => [],
                        'meta' => ['intent' => 'faq', 'topic' => $keyword, 'empty' => true],
                    ];
                }
            }
        }

        // If no keyword matched but we have ingested texts, provide a concise AI summary
        $allTexts = array_filter([
            trim((string) ($settings?->faq_payment_delivery_text ?? '')),
            trim((string) ($settings?->faq_returns_text ?? '')),
            trim((string) ($settings?->faq_contacts_text ?? '')),
            trim((string) ($settings?->faq_about_text ?? '')),
        ], fn($t) => !empty($t));

        if (!empty($allTexts)) {
            $joined = implode("\n\n---\n\n", array_map(fn($t) => mb_substr($t, 0, 2000), $allTexts));
            try {
                $prompt = "Користувач питає: \"{$message}\"\n\n" .
                    "Контекст (витяги з FAQ сторінок магазину, очищені):\n" . $joined . "\n\n" .
                    "Визнач одну найрелевантнішу тему: 'Оплата' або 'Доставка' або 'Повернення' або 'Контакти'.\n" .
                    "Відповідай ТІЛЬКИ по цій темі, коротко, без Markdown/емодзі, 3-5 рядків. Завершуй простим CTA. Макс 500 символів. Без вигадок — лише з контексту.";

                $reply = $this->aiRouter->callOpenAI($prompt, 0.15, 320);
                $reply = trim($reply);
                if (empty($reply)) {
                    $reply = mb_substr($joined, 0, 1000);
                } elseif (mb_strlen($reply) > 1600) {
                    $reply = mb_substr($reply, 0, 1600);
                }

                // Append the single most relevant link if we can infer it from question
                $link = null;
                $lm = mb_strtolower($message);
                if (str_contains($lm, 'достав')) { $link = $settings?->faq_payment_delivery_url; }
                elseif (str_contains($lm, 'оплат')) { $link = $settings?->faq_payment_delivery_url; }
                elseif (str_contains($lm, 'повернен') || str_contains($lm, 'обмін')) { $link = $settings?->faq_returns_url; }
                elseif (str_contains($lm, 'контакт')) { $link = $settings?->faq_contacts_url; }
                if (!empty($link)) { $reply .= "\n\nПосилання: " . $link; }

                return [
                    'message' => $reply,
                    'products' => [],
                    'meta' => ['intent' => 'faq', 'topic' => 'general'],
                ];
            } catch (\Throwable $e) {
                // fall through to link listing
            }
        }

        // Show list of available custom links if no keyword matched
        $links = [];
        if (!empty($settings?->faq_payment_delivery_url)) $links[] = 'Оплата і доставка: ' . $settings->faq_payment_delivery_url;
        if (!empty($settings?->faq_returns_url)) $links[] = 'Обмін та повернення: ' . $settings->faq_returns_url;
        if (!empty($settings?->faq_contacts_url)) $links[] = 'Контактна інформація: ' . $settings->faq_contacts_url;
        if (!empty($settings?->faq_about_url)) $links[] = 'Про нас: ' . $settings->faq_about_url;
        if (!empty($links)) {
            return [
                'message' => "Ось корисні сторінки:\n" . implode("\n", array_map(fn($l) => '• ' . $l, $links)),
                'products' => [],
                'meta' => ['intent' => 'faq'],
            ];
        }

        // Final fallback: prompt user
        return [
            'message' => 'Напишіть, що шукаєте... (доставка, оплата, повернення, контакти)',
            'products' => [],
            'meta' => ['intent' => 'faq'],
        ];
    }

    /**
     * Handle small talk
     */
    private function handleSmallTalk(string $message, array $plan, array $context): array
    {
        try {
            $prompt = "Користувач написав: \"{$message}\"

Це smalltalk (вітання, подяка, допобачення і т.д.). 

Згенеруй коротку природну відповідь українською (до 15 слів). Будь дружнім і готовим допомогти з підбором тактичного екіпірування.

Поверни ТІЛЬКИ текст відповіді, без лапок.";

            $response = $this->aiRouter->callOpenAI($prompt, 0.7, 50);
            $reply = trim($response, " \n\r\t\"'");
            
            if (empty($reply) || mb_strlen($reply) > 100) {
                $reply = "👋";
            }
            
            return [
                'message' => $reply,
                'products' => [],
                'meta' => ['intent' => 'smalltalk'],
            ];
        } catch (\Exception $e) {
            Log::warning('handleSmallTalk: AI failed', ['error' => $e->getMessage()]);
            return [
                'message' => "👋",
                'products' => [],
                'meta' => ['intent' => 'smalltalk'],
            ];
        }
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
