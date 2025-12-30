<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\FunctionCallingAgent;
use App\Services\Ai\AiRouter;
use App\Services\Horoshop\ProductService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatService
{
    // Feature flag: use new function calling agent
    private bool $useNewAgent;

    public function __construct(
        protected AiRouter $aiRouter,
        protected ProductService $productService,
        protected AgentOrchestrator $agentOrchestrator,
        protected ?FunctionCallingAgent $functionCallingAgent = null,
    ) {
        // Enable new agent via env variable (default: true for testing)
        $this->useNewAgent = config('services.openai.use_function_calling', true);
        
        // Lazy load function calling agent
        if ($this->useNewAgent && !$this->functionCallingAgent) {
            $this->functionCallingAgent = app(FunctionCallingAgent::class);
        }
    }

    /**
     * Головний метод: обробка одного повідомлення користувача.
     *
     * @param string $message     — текст від юзера
     * @param string|null $sessionId — ідентифікатор сесії (можна зберігати в кукі/LS)
     *
     * @return array — нормалізована відповідь для фронту
     */
    public function handleMessage(string $message, ?string $sessionId = null): array
    {
        $normalizedMessage = trim($message);

        // Якщо sessionId не передано, генеруємо новий
        if (! $sessionId) {
            $sessionId = (string) Str::uuid();
        }

        $sessionKey      = $this->buildSessionKey($sessionId);
        $sessionContext  = $this->loadSessionContext($sessionKey);
        
        // Load conversation history for context
        $conversationHistory = $this->loadConversationHistory($sessionId);

        Log::info('ChatService::handleMessage incoming', [
            'message'    => $normalizedMessage,
            'session_id' => $sessionId,
            'sessionKey' => $sessionKey,
            'ctx'        => $sessionContext,
            'use_new_agent' => $this->useNewAgent,
        ]);

        // Логуємо повідомлення користувача
        $this->logUserMessage($sessionId, $normalizedMessage);

        // Вибираємо агента на основі feature flag
        try {
            // Передаємо session_id та історію у контексті для follow-up запитів
            $contextWithSessionId = array_merge($sessionContext, [
                'session_id' => $sessionId,
                'history' => $conversationHistory,
            ]);
            
            // NEW: Use function calling agent if enabled
            if ($this->useNewAgent && $this->functionCallingAgent) {
                Log::info('ChatService: using FunctionCallingAgent');
                $agentResult = $this->functionCallingAgent->handle($normalizedMessage, $contextWithSessionId);
            } else {
                $agentResult = $this->agentOrchestrator->handle($normalizedMessage, $contextWithSessionId);
            }
            
            $intent = $agentResult['meta']['intent'] ?? null;
            $productCards = $agentResult['meta']['product_cards'] ?? null;
            $agentMessages = $agentResult['messages'] ?? [];

            // Формуємо відповідь у форматі очікуваному фронтом
            $response = [
                'type'       => 'products',
                'text'       => $agentResult['message'] ?? '',
                'data'       => [
                    'products' => $agentResult['products'] ?? [],
                    // NEW: product_cards for individual card+description display
                    'product_cards' => $productCards,
                    // NEW: messages array for sequential display
                    'messages' => $agentMessages,
                ],
                'session_id' => $sessionId,
                'meta'       => $agentResult['meta'] ?? [],
            ];

            // Спеціальні типи
            if ($intent === 'order_status') {
                // Фронт ще не підтримує спеціальний тип, тому лишаємо text + data
                $response['type'] = 'text';
                $response['data'] = [
                    'orders' => $agentResult['meta']['orders'] ?? [],
                    'criteria' => $agentResult['meta']['criteria'] ?? [],
                    'found' => $agentResult['meta']['found'] ?? 0,
                ];
            } elseif ($intent === 'faq') {
                $response['type'] = 'text';
                $response['data'] = [
                    'pages' => $agentResult['meta']['pages'] ?? [],
                ];
            } elseif (empty($agentResult['products'])) {
                $response['type'] = 'text';
            }
            
            // Оновлюємо контекст сесії
            $this->saveSessionContext($sessionKey, [
                'last_intent'       => $agentResult['meta']['intent'] ?? 'unknown',
                'last_query'        => $normalizedMessage,
                'last_refined_query' => $agentResult['meta']['refined_query'] ?? null,
                'ambiguous'         => $agentResult['meta']['ambiguous'] ?? false,
                'last_chosen_ids'   => $agentResult['meta']['chosen_ids'] ?? [],
                'last_chosen_articles' => $agentResult['meta']['chosen_articles'] ?? [],
            ]);
            
            Log::info('ChatService::AgentOrchestrator response', [
                'response' => $response,
            ]);
            
            // Логуємо відповідь асистента з метаданими
            $this->logAssistantMessage($sessionId, $response, $agentResult['meta'] ?? []);
            
            return $response;
            
        } catch (\Exception $e) {
            Log::error('ChatService::AgentOrchestrator error', [
                'error'   => $e->getMessage(),
                'message' => $normalizedMessage,
                'trace'   => $e->getTraceAsString(),
            ]);
            
            // Fallback на стару логіку якщо щось пішло не так
            return $this->handleMessageLegacy($normalizedMessage, $sessionId, $sessionKey, $sessionContext);
        }
    }
    
    /**
     * Legacy обробка повідомлень (fallback якщо AgentOrchestrator не працює)
     */
    protected function handleMessageLegacy(string $normalizedMessage, string $sessionId, string $sessionKey, array $sessionContext): array
    {

        // 1. Простий rule-based хендлер на чисті категорії (турнікети, шоломи, плитоноски)
        $quickCategoryResponse = $this->handleQuickCategoryShortcuts($normalizedMessage, $sessionKey);
        if ($quickCategoryResponse !== null) {
            Log::info('ChatService::quickCategoryResponse', [
                'response' => $quickCategoryResponse,
            ]);
            
            // Логуємо відповідь асистента
            $this->logAssistantMessage($sessionId, $quickCategoryResponse);
            
            return $quickCategoryResponse;
        }

        // 2. Викликаємо AiRouter, щоб отримати JSON із інтенцією / дією / категорією
        $aiData = $this->aiRouter->routeChatMessage($normalizedMessage, [
            'session_id' => $sessionId,
        ]);

        // Перестраховка: навіть якщо AiRouter верне щось криве – не ламаємо чат
        if (! is_array($aiData)) {
            $response = $this->simpleTextResponse(
                "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏",
                'unknown'
            );
            
            $this->logAssistantMessage($sessionId, $response);
            
            return $response;
        }

        $intent      = Arr::get($aiData, 'intent', 'unknown');
        $action      = Arr::get($aiData, 'action', 'NONE');
        $confidence  = (float) Arr::get($aiData, 'confidence', 0.0);
        $categoryKey = Arr::get($aiData, 'category_key');
        $slots       = Arr::get($aiData, 'slots', []);
        $messageOut  = Arr::get($aiData, 'message', '');

        Log::info('ChatService::AiRouter result', [
            'aiData' => $aiData,
        ]);

        // 3. Роутимо по інтенції / дії
        switch ($intent) {
            case 'product_search':
                $response = $this->handleProductSearchIntent(
                    originalQuery: $normalizedMessage,
                    action: $action,
                    confidence: $confidence,
                    categoryKey: $categoryKey,
                    slots: $slots,
                    messageOut: $messageOut,
                    sessionKey: $sessionKey,
                    sessionContext: $sessionContext
                );
                break;

            case 'order_status':
                $response = $this->handleOrderStatusIntent($aiData, $sessionKey, $sessionContext);
                break;

            case 'shop_info':
                $response = $this->handleShopInfoIntent($aiData, $sessionKey, $sessionContext);
                break;

            case 'smalltalk':
                $response = $this->simpleTextResponse(
                    $messageOut ?: "Я тут, слухаю 🙂",
                    'smalltalk'
                );
                $this->saveSessionContext($sessionKey, [
                    'last_intent'       => 'smalltalk',
                    'last_action'       => $action,
                    'last_category_key' => null,
                    'last_query'        => $normalizedMessage,
                    'slots'             => $slots,
                ]);
                Log::info('ChatService::smalltalk response', ['response' => $response]);
                $this->logAssistantMessage($sessionId, $response);
                break;

            case 'abuse':
                $response = $this->simpleTextResponse(
                    "Розумію, що може бути нервова ситуація. Якщо хочеш, я допоможу підібрати спорядження або підкажу по замовленню.",
                    'abuse'
                );
                $this->saveSessionContext($sessionKey, [
                    'last_intent'       => 'abuse',
                    'last_action'       => $action,
                    'last_category_key' => null,
                    'last_query'        => $normalizedMessage,
                    'slots'             => $slots,
                ]);
                $this->logAssistantMessage($sessionId, $response);
                break;

            case 'unknown':
            default:
                $response = $this->simpleTextResponse(
                    $messageOut ?: "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏",
                    'unknown'
                );
                $this->saveSessionContext($sessionKey, [
                    'last_intent'       => 'unknown',
                    'last_action'       => $action,
                    'last_category_key' => null,
                    'last_query'        => $normalizedMessage,
                    'slots'             => $slots,
                ]);
                break;
        }

        Log::info('ChatService::handleMessage outgoing', [
            'response' => $response,
        ]);

        // Логуємо відповідь асистента
        $this->logAssistantMessage($sessionId, $response);

        return $response;
    }

    /**
     * Швидкий хендлер для дуже явних запитів типу "турнікети", "шоломи".
     * Це працює навіть без AI, щоб юзер відразу бачив товар.
     */
    protected function handleQuickCategoryShortcuts(string $message, string $sessionKey): ?array
    {
        $norm = mb_strtolower($message);

        $map = [
            'турнікети'   => 'tourniquets',
            'турнікет'    => 'tourniquets',
            'шолом'       => 'helmets',
            'шоломи'      => 'helmets',
            'каска'       => 'helmets',
            'каски'       => 'helmets',
            'плитоноска'  => 'plate_carriers',
            'плитоноски'  => 'plate_carriers',
            'аптечка'     => 'ifak_kits',
            'аптечки'     => 'ifak_kits',
            'іфак'        => 'ifak_kits',
            'ifak'        => 'ifak_kits',
        ];

        if (! array_key_exists($norm, $map)) {
            return null;
        }

        $categoryKey = $map[$norm];

        $products = $this->productService->searchByCategoryKey($categoryKey, 10);

        $response = $this->productsResponse(
            text: "Ось, що маємо по цій категорії 👇",
            products: $products,
            categoryKey: $categoryKey,
            filters: null,
            normalizedQuery: $message
        );

        // Записуємо контекст сесії
        $this->saveSessionContext($sessionKey, [
            'last_intent'       => 'product_search',
            'last_action'       => 'SHOW_PRODUCTS',
            'last_category_key' => $categoryKey,
            'last_query'        => $message,
            'slots'             => [],
        ]);

        Log::info('ChatService::quickCategoryResponse', [
            'response' => $response,
        ]);

        return $response;
    }

    /**
     * Обробка інтенції product_search.
     */
    protected function handleProductSearchIntent(
        string $originalQuery,
        string $action,
        float $confidence,
        ?string $categoryKey,
        array $slots,
        string $messageOut,
        string $sessionKey,
        array $sessionContext = []
    ): array {
        $effectiveCategoryKey = $categoryKey;

        // 1) Якщо модель не дала category_key — пробуємо самі його вирахувати по тексту
        if (! $effectiveCategoryKey) {
            $detected = $this->productService->detectCategoryKeyFromText($originalQuery);
            if ($detected) {
                $effectiveCategoryKey = $detected;
                Log::info('ChatService::handleProductSearchIntent detected category from text', [
                    'query'            => $originalQuery,
                    'detected_category'=> $detected,
                ]);
            }
        }

        // 2) Якщо все ще немає категорії, але є попередній product_search з категорією
        if (! $effectiveCategoryKey && ($sessionContext['last_intent'] ?? null) === 'product_search') {
            $prevCategory = $sessionContext['last_category_key'] ?? null;
            if ($prevCategory && $this->isFollowupMoreRequest($originalQuery)) {
                $effectiveCategoryKey = $prevCategory;
                Log::info('ChatService::handleProductSearchIntent using previous category (followup "ще")', [
                    'query'          => $originalQuery,
                    'prev_category'  => $prevCategory,
                ]);
            }
        }

        // Якщо AI каже SHOW_PRODUCTS і є категорія + нормальна впевненість
        if ($action === 'SHOW_PRODUCTS' && $effectiveCategoryKey && $confidence >= 0.6) {
            $limit = 3;

            $priceFilters = [
                'min' => Arr::get($slots, 'budget_min'),
                'max' => Arr::get($slots, 'budget_max'),
            ];

            $products = $this->productService->searchByCategoryKey(
                categoryKey: $effectiveCategoryKey,
                limit: $limit,
                priceFilters: $priceFilters
            );

            if (empty($products)) {
                $this->saveSessionContext($sessionKey, [
                    'last_intent'       => 'product_search',
                    'last_action'       => $action,
                    'last_category_key' => $effectiveCategoryKey,
                    'last_query'        => $originalQuery,
                    'slots'             => $slots,
                ]);

                return $this->simpleTextResponse(
                    "Зараз по цій категорії немає товарів в наявності або я не зміг їх знайти 😔 Спробуй сформулювати інакше або обрати іншу категорію.",
                    'product_search'
                );
            }

            $response = $this->productsResponse(
                text: $messageOut ?: "Ось, що можу запропонувати 👇",
                products: $products,
                categoryKey: $effectiveCategoryKey,
                filters: $priceFilters,
                normalizedQuery: $originalQuery
            );

            $this->saveSessionContext($sessionKey, [
                'last_intent'       => 'product_search',
                'last_action'       => $action,
                'last_category_key' => $effectiveCategoryKey,
                'last_query'        => $originalQuery,
                'slots'             => $slots,
            ]);

            Log::info('ChatService::productsResponse', [
                'response' => $response,
            ]);

            return $response;
        }

        // ===== Session-aware search state =====
        $state = $this->loadSearchState($sessionKey);
        $state = $this->mergeSearchState($state, $effectiveCategoryKey, $slots, $originalQuery);

        // якщо юзер не хоче питань — або короткий follow-up — показуємо товари
        $forceShow = $this->shouldForceShowProducts($originalQuery, $sessionContext);

        // 1) якщо є категорія — шукаємо по категорії з більшим лімітом (внутрішньо 50), потім фільтр/дедуп до 10
        if ($effectiveCategoryKey) {
            $priceFilters = [
                'min' => $state['filters']['budget_min'] ?? Arr::get($slots, 'budget_min'),
                'max' => $state['filters']['budget_max'] ?? Arr::get($slots, 'budget_max'),
            ];

            $raw = $this->productService->searchByCategoryKey(
                categoryKey: $effectiveCategoryKey,
                limit: 50,
                priceFilters: $priceFilters
            );

            // AI вирішує які товари найрелевантніші
            $products = $this->aiRouter->rankProductsByRelevance(
                products: $raw,
                originalQuery: $originalQuery,
                categoryKey: $effectiveCategoryKey,
                sessionContext: $sessionContext,
                negativeTerms: $state['negative_terms'] ?? []
            );

            // Якщо AI не дав результатів — fallback на механічну фільтрацію
            if (empty($products)) {
                $products = $this->filterAndDedupProducts($raw, $state, 10);
            }

            if (!empty($products)) {
                // запам'ятали що показали (щоб "ще" не повторювало те саме)
                foreach ($products as $p) {
                    if (isset($p['id'])) $state['shown_ids'][] = $p['id'];
                }
                $state['shown_ids'] = array_values(array_unique($state['shown_ids']));

                $this->saveSearchState($sessionKey, $state);

                // навіть якщо AI хотів уточнення — покажемо товари, а уточнення (якщо треба) коротко після
                $text = "Ось варіанти 👇";
                if ($action === 'ASK_CLARIFICATION' && !$forceShow && $messageOut) {
                    // тільки 1 раз те саме питання
                    $hash = md5($messageOut);
                    if (($state['last_question'] ?? null) !== $hash) {
                        $state['last_question'] = $hash;
                        $this->saveSearchState($sessionKey, $state);
                        $text = "Ось варіанти 👇\n\n" . $messageOut;
                    }
                }

                $response = $this->productsResponse(
                    text: $text,
                    products: $products,
                    categoryKey: $effectiveCategoryKey,
                    filters: $state['filters'] ?? null,
                    normalizedQuery: $originalQuery
                );

                $this->saveSessionContext($sessionKey, [
                    'last_intent'       => 'product_search',
                    'last_action'       => 'SHOW_PRODUCTS',
                    'last_category_key' => $effectiveCategoryKey,
                    'last_query'        => $originalQuery,
                    'slots'             => $slots,
                ]);

                return $response;
            }
        }

        // 2) якщо категорії нема або порожньо — fallback текстовий (теж 50 -> AI-ранжування)
        $rawText = $this->productService->searchByText($originalQuery, null, 'uk');
        if (!empty($rawText)) {
            // AI вирішує які товари найрелевантніші
            $products = $this->aiRouter->rankProductsByRelevance(
                products: $rawText,
                originalQuery: $originalQuery,
                categoryKey: $effectiveCategoryKey,
                sessionContext: $sessionContext,
                negativeTerms: $state['negative_terms'] ?? []
            );

            // Якщо AI не дав результатів — fallback на механічну фільтрацію
            if (empty($products)) {
                $products = $this->filterAndDedupProducts($rawText, $state, 10);
            }

            if (!empty($products)) {
                foreach ($products as $p) {
                    if (isset($p['id'])) $state['shown_ids'][] = $p['id'];
                }
                $state['shown_ids'] = array_values(array_unique($state['shown_ids']));
                $this->saveSearchState($sessionKey, $state);

                $this->saveSessionContext($sessionKey, [
                    'last_intent'       => 'product_search',
                    'last_action'       => 'SHOW_PRODUCTS',
                    'last_category_key' => $effectiveCategoryKey,
                    'last_query'        => $originalQuery,
                    'slots'             => $slots,
                ]);

                return $this->productsResponse(
                    text: "Ось, що знайшов 👇",
                    products: $products,
                    categoryKey: $effectiveCategoryKey,
                    filters: $state['filters'] ?? null,
                    normalizedQuery: $originalQuery
                );
            }
        }

        // 3) тільки якщо реально 0 — тоді текст
        $this->saveSearchState($sessionKey, $state);
        $this->saveSessionContext($sessionKey, [
            'last_intent'       => 'product_search',
            'last_action'       => $action,
            'last_category_key' => $effectiveCategoryKey,
            'last_query'        => $originalQuery,
            'slots'             => $slots,
        ]);
        return $this->simpleTextResponse(
            $messageOut ?: "Поки не знайшов релевантних товарів. Напиши 1-2 слова: колір/бюджет/розмір — і я перешукаю.",
            'product_search'
        );
    }

    /**
     * Інтенція: статус замовлення.
     * Тут поки просто шаблон – ти можеш підключити свій CRM/ERP.
     */
    protected function handleOrderStatusIntent(array $aiData, string $sessionKey, array $sessionContext = []): array
    {
        $slots       = Arr::get($aiData, 'slots', []);
        $orderNumber = Arr::get($slots, 'order_number');

        if (! $orderNumber) {
            $response = $this->simpleTextResponse(
                "Напиши, будь ласка, номер замовлення, щоб я міг його перевірити.",
                'order_status'
            );

            $this->saveSessionContext($sessionKey, [
                'last_intent'       => 'order_status',
                'last_action'       => Arr::get($aiData, 'action', 'ASK_CLARIFICATION'),
                'last_category_key' => null,
                'last_query'        => null,
                'slots'             => $slots,
            ]);

            Log::info('ChatService::order_status response (no order number)', [
                'response' => $response,
            ]);

            return $response;
        }

        $response = $this->simpleTextResponse(
            "Ти вказав номер замовлення {$orderNumber}. Зараз у демо-версії статуси ще не прив'язані до CRM, але в проді тут буде відстеження посилки 😉",
            'order_status'
        );

        $this->saveSessionContext($sessionKey, [
            'last_intent'       => 'order_status',
            'last_action'       => Arr::get($aiData, 'action', 'NONE'),
            'last_category_key' => null,
            'last_query'        => null,
            'slots'             => $slots,
        ]);

        Log::info('ChatService::order_status response', [
            'response' => $response,
        ]);

        return $response;
    }

    /**
     * Інтенція: інформація про магазин (доставка/оплата/повернення).
     */
    protected function handleShopInfoIntent(array $aiData, string $sessionKey, array $sessionContext = []): array
    {
        $messageOut = Arr::get($aiData, 'message');

        $response = $this->simpleTextResponse(
            $messageOut ?: "Ми відправляємо замовлення Новою Поштою по всій Україні, оплата — на карту або післяплата. Якщо треба деталі — напиши, що цікавить: доставка, оплата чи повернення.",
            'shop_info'
        );

        $this->saveSessionContext($sessionKey, [
            'last_intent'       => 'shop_info',
            'last_action'       => Arr::get($aiData, 'action', 'NONE'),
            'last_category_key' => null,
            'last_query'        => null,
            'slots'             => Arr::get($aiData, 'slots', []),
        ]);

        Log::info('ChatService::shop_info response', [
            'response' => $response,
        ]);

        return $response;
    }

    /**
     * Простий формат відповіді тільки з текстом.
     */
    protected function simpleTextResponse(string $text, ?string $intent = null): array
    {
        $response = [
            'type' => 'text',
            'text' => $text,
            'data' => null,
            'intent' => $intent ?? 'unknown',
        ];

        Log::info('ChatService::simpleTextResponse', [
            'response' => $response,
        ]);

        return $response;
    }

    /**
     * Формат відповіді з товарами + текст.
     *
     * $products тут – масив з ProductService::normalizeProductForApi()
     */
    protected function productsResponse(string $text, array $products, ?string $categoryKey = null, ?array $filters = null, ?string $normalizedQuery = null): array
    {
        $response = [
            'type' => 'products',
            'text' => $text,
            'data' => [
                'category_key' => $categoryKey,
                'products'     => $products,
                'filters'      => $filters,
                'normalized_query' => $normalizedQuery,
            ],
            'intent' => 'product_search',
        ];

        Log::info('ChatService::productsResponse', [
            'response' => $response,
        ]);

        return $response;
    }

    /**
     * Будуємо ключ для кешу сесії.
     */
    protected function buildSessionKey(?string $sessionId): string
    {
        if ($sessionId && $sessionId !== '') {
            return 'chat_' . $sessionId;
        }

        // fallback по IP, щоб все одно була якась "сесія"
        $ip = request()->ip() ?: 'unknown_ip';

        return 'chat_ip_' . $ip;
    }

    protected function loadSessionContext(string $sessionKey): array
    {
        return Cache::get('chat_ctx_' . $sessionKey, []);
    }

    protected function saveSessionContext(string $sessionKey, array $data): void
    {
        Cache::put('chat_ctx_' . $sessionKey, $data, now()->addHours(6));

        Log::info('ChatService::saveSessionContext', [
            'sessionKey' => $sessionKey,
            'data'       => $data,
        ]);
    }

    /**
     * Очищує сесійні дані з кешу.
     */
    public function clearSession(string $sessionId): void
    {
        $sessionKey = $this->buildSessionKey($sessionId);
        
        Cache::forget('chat_ctx_' . $sessionKey);
        Cache::forget('chat_search_' . $sessionKey);
        
        Log::info('ChatService::clearSession', ['session_id' => $sessionId, 'sessionKey' => $sessionKey]);
    }

    /**
     * Визначаємо, чи запит виглядає як "ще", "ще покажи", "давай ще" і т.д.
     */
    protected function isFollowupMoreRequest(string $query): bool
    {
        $norm = mb_strtolower(trim($query));

        // абсолютно короткі варіанти
        $short = [
            'ще', 'еще', 'ещё', 'more', 'ще показати', 'ще покажи', 'покажи ще',
            'давай ще', 'давай ще варіанти',
        ];

        foreach ($short as $s) {
            if ($norm === $s) {
                return true;
            }
        }

        // якщо є слово "ще" + "варіант"/"покажи"/"давай"
        if (mb_stripos($norm, 'ще') !== false &&
            (mb_stripos($norm, 'варіант') !== false
                || mb_stripos($norm, 'покаж') !== false
                || mb_stripos($norm, 'давай') !== false)
        ) {
            return true;
        }

        if (mb_stripos($norm, 'more') !== false) {
            return true;
        }

        return false;
    }
    
    protected function shouldForceShowProducts(string $query, array $sessionContext = []): bool
    {
        $norm = mb_strtolower(trim($query));
        if ($norm === '') {
            return false;
        }

        $forcePhrases = [
            'будь що', 'що завгодно', 'похер', 'пофіг', 'пофиг',
            'не задавай питання', 'не питай', 'просто покажи', 'покажи варіанти',
            'any', 'anything', 'whatever',
            'чисто', 'окремо', 'саме', 'тільки', 'одразу', 'только',
        ];

        foreach ($forcePhrases as $p) {
            if (mb_stripos($norm, $p) !== false) {
                return true;
            }
        }

        // якщо юзер пише дуже коротко (1-2 слова) і в сесії вже був product_search — краще показати, ніж допитувати
        if (mb_strlen($norm) <= 10 && ($sessionContext['last_intent'] ?? null) === 'product_search') {
            return true;
        }

        return false;
    }

    protected function initSearchState(): array
    {
        return [
            'topic'         => null,
            'category_key'  => null,
            'filters'       => [
                'budget_min' => null,
                'budget_max' => null,
                'camo'       => null,   // multicam / pixel / olive ...
                'color'      => null,
            ],
            'negative_terms'=> [],      // що НЕ показувати
            'shown_ids'     => [],      // щоб не повторювати
            'last_question' => null,    // щоб не дрочити тим самим
        ];
    }

    protected function loadSearchState(string $sessionKey): array
    {
        return Cache::get('chat_search_' . $sessionKey, $this->initSearchState());
    }

    protected function saveSearchState(string $sessionKey, array $state): void
    {
        Cache::put('chat_search_' . $sessionKey, $state, now()->addHours(6));
    }

    protected function mergeSearchState(array $state, ?string $categoryKey, array $slots, string $originalQuery): array
    {
        // категорія
        if ($categoryKey) {
            $state['category_key'] = $categoryKey;
        }

        // бюджет
        $min = Arr::get($slots, 'budget_min');
        $max = Arr::get($slots, 'budget_max');
        if ($min !== null) $state['filters']['budget_min'] = $min;
        if ($max !== null) $state['filters']['budget_max'] = $max;

        // простенька детекція camo/color з тексту (без AI)
        $q = mb_strtolower($originalQuery);

        if (str_contains($q, 'мультикам') || str_contains($q, 'multicam')) $state['filters']['camo'] = 'multicam';
        if (str_contains($q, 'піксель') || str_contains($q, 'pixel'))       $state['filters']['camo'] = 'pixel';
        if (str_contains($q, 'олива') || str_contains($q, 'olive'))         $state['filters']['camo'] = 'olive';
        if (str_contains($q, 'койот') || str_contains($q, 'coyote'))        $state['filters']['color'] = 'coyote';
        if (str_contains($q, 'чорн') || str_contains($q, 'black'))          $state['filters']['color'] = 'black';

        // якщо юзер НЕ просив "панель/підсумок", додаємо як негативи для плитоноски
        // (це вирішує твоє "панель грьобана" без ручного хардкоду під кожну нішу)
        if (($state['category_key'] ?? null) === 'plate_carriers') {
            $defaultNegatives = ['панель','підсумок','pouch','cummerbund','камербанд','чохол','cover','модуль','клапан','кап'];
            $state['negative_terms'] = array_values(array_unique(array_merge($state['negative_terms'] ?? [], $defaultNegatives)));
        }

        return $state;
    }

    protected function filterAndDedupProducts(array $products, array $state, int $limit = 10): array
    {
        $neg = array_map(fn($x) => mb_strtolower($x), $state['negative_terms'] ?? []);
        $shown = array_flip($state['shown_ids'] ?? []);

        $out = [];
        $seen = [];

        foreach ($products as $p) {
            $id = $p['id'] ?? null;
            if ($id && isset($shown[$id])) continue;

            $title = mb_strtolower((string)($p['title'] ?? ''));
            $cat   = mb_strtolower((string)($p['category_path'] ?? ''));

            $blocked = false;
            foreach ($neg as $w) {
                if ($w !== '' && (str_contains($title, $w) || str_contains($cat, $w))) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) continue;

            // дедуп по title (без ціни, бо одна модель різними цінами)
            $key = md5($title);
            if (isset($seen[$key])) continue;

            $seen[$key] = true;
            $out[] = $p;

            if (count($out) >= $limit) break;
        }

        return $out;
    }

    /**
     * Логування повідомлення користувача до БД.
     */
    protected function logUserMessage(string $sessionId, string $content): void
    {
        try {
            Log::info('logUserMessage called', ['session_id' => $sessionId]);
            
            $session = ChatSession::firstOrCreate(
                ['session_id' => $sessionId],
                [
                    'language' => 'uk',
                    'status' => 'open',
                    'meta' => [],
                ]
            );

            Log::info('Session created/found', ['session_id' => $sessionId, 'db_id' => $session->id]);

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
                'meta' => [],
            ]);

            Log::info('User message logged', ['session_id' => $sessionId]);

            $session->increment('messages_count');
            $session->update([
                'last_user_query' => $content,
                'last_message_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log user message', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Логування відповіді асистента до БД.
     */
    protected function logAssistantMessage(string $sessionId, array $response, array $agentMeta = []): void
    {
        try {
            Log::info('logAssistantMessage called', ['session_id' => $sessionId]);
            
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (! $session) {
                Log::warning('Session not found for assistant message', ['session_id' => $sessionId]);
                return;
            }

            // Мета-дані з AgentOrchestrator мають пріоритет
            $meta = [
                'intent'            => $agentMeta['intent'] ?? $response['intent'] ?? 'unknown',
                'ambiguous'         => $agentMeta['ambiguous'] ?? false,
                'chosen_ids'        => $agentMeta['chosen_ids'] ?? [],
                'chosen_articles'   => $agentMeta['chosen_articles'] ?? [],
                'refined_query'     => $agentMeta['refined_query'] ?? null,
                'filters'           => $agentMeta['filters'] ?? [],
                'search_debug'      => $agentMeta['search_debug'] ?? [],
                // FIX: products are in data.products, not response.products
                'products_shown'    => count($response['data']['products'] ?? $response['products'] ?? []),
            ];

            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $response['text'] ?? '',
                'meta' => $meta,
            ]);

            Log::info('Assistant message logged', ['session_id' => $sessionId]);

            $session->increment('messages_count');
            $session->update([
                'last_intent' => $meta['intent'],
                'last_message_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log assistant message', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Load conversation history for GPT context
     * Returns last N messages in OpenAI format
     */
    protected function loadConversationHistory(string $sessionId, int $limit = 10): array
    {
        try {
            $session = ChatSession::where('session_id', $sessionId)->first();
            if (!$session) {
                return [];
            }

            $messages = ChatMessage::where('chat_session_id', $session->id)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();

            $history = [];
            foreach ($messages as $msg) {
                // Skip if no content
                if (empty($msg->content)) {
                    continue;
                }

                $role = $msg->role === 'user' ? 'user' : 'assistant';
                
                // For assistant messages, extract just text (not full JSON response)
                $content = $msg->content;
                if ($role === 'assistant' && is_array($content)) {
                    $content = $content['text'] ?? json_encode($content, JSON_UNESCAPED_UNICODE);
                }

                $history[] = [
                    'role' => $role,
                    'content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE),
                ];
            }

            Log::info('Loaded conversation history', [
                'session_id' => $sessionId,
                'messages_count' => count($history),
            ]);

            return $history;
        } catch (\Exception $e) {
            Log::error('Failed to load conversation history', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}