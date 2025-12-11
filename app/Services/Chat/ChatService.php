<?php

namespace App\Services\Chat;

use App\Services\Ai\AiRouter;
use App\Services\Horoshop\ProductService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public function __construct(
        protected AiRouter $aiRouter,
        protected ProductService $productService,
    ) {
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

        $sessionKey      = $this->buildSessionKey($sessionId);
        $sessionContext  = $this->loadSessionContext($sessionKey);

        Log::info('ChatService::handleMessage incoming', [
            'message'    => $normalizedMessage,
            'session_id' => $sessionId,
            'sessionKey' => $sessionKey,
            'ctx'        => $sessionContext,
        ]);

        // 1. Простий rule-based хендлер на чисті категорії (турнікети, шоломи, плитоноски)
        $quickCategoryResponse = $this->handleQuickCategoryShortcuts($normalizedMessage, $sessionKey);
        if ($quickCategoryResponse !== null) {
            Log::info('ChatService::quickCategoryResponse', [
                'response' => $quickCategoryResponse,
            ]);
            return $quickCategoryResponse;
        }

        // 2. Викликаємо AiRouter, щоб отримати JSON із інтенцією / дією / категорією
        $aiData = $this->aiRouter->routeChatMessage($normalizedMessage, [
            'session_id' => $sessionId,
        ]);

        // Перестраховка: навіть якщо AiRouter верне щось криве – не ламаємо чат
        if (! is_array($aiData)) {
            $response = $this->simpleTextResponse(
                "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏"
            );
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
                    $messageOut ?: "Я тут, слухаю 🙂"
                );
                $this->saveSessionContext($sessionKey, [
                    'last_intent'       => 'smalltalk',
                    'last_action'       => $action,
                    'last_category_key' => null,
                    'last_query'        => $normalizedMessage,
                    'slots'             => $slots,
                ]);
                Log::info('ChatService::smalltalk response', ['response' => $response]);
                break;

            case 'abuse':
                $response = $this->simpleTextResponse(
                    "Розумію, що може бути нервова ситуація. Якщо хочеш, я допоможу підібрати спорядження або підкажу по замовленню."
                );
                $this->saveSessionContext($sessionKey, [
                    'last_intent'       => 'abuse',
                    'last_action'       => $action,
                    'last_category_key' => null,
                    'last_query'        => $normalizedMessage,
                    'slots'             => $slots,
                ]);
                break;

            case 'unknown':
            default:
                $response = $this->simpleTextResponse(
                    $messageOut ?: "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏"
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

        $products = $this->productService->searchByCategoryKey($categoryKey, 3);

        $response = $this->productsResponse(
            text: "Ось, що маємо по цій категорії 👇",
            products: $products,
            categoryKey: $categoryKey
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
                    "Зараз по цій категорії немає товарів в наявності або я не зміг їх знайти 😔 Спробуй сформулювати інакше або обрати іншу категорію."
                );
            }

            $response = $this->productsResponse(
                text: $messageOut ?: "Ось, що можу запропонувати 👇",
                products: $products,
                categoryKey: $effectiveCategoryKey
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

        // Якщо AI хоче уточнення
        if ($action === 'ASK_CLARIFICATION') {
            $this->saveSessionContext($sessionKey, [
                'last_intent'       => 'product_search',
                'last_action'       => $action,
                'last_category_key' => $effectiveCategoryKey,
                'last_query'        => $originalQuery,
                'slots'             => $slots,
            ]);

            return $this->simpleTextResponse(
                $messageOut ?: "Уточни, будь ласка, який саме товар або під які задачі тобі потрібен."
            );
        }

        // Fallback – просто спробуємо текстовий пошук
        $products = $this->productService->searchByText($originalQuery, null, 'uk');

        $this->saveSessionContext($sessionKey, [
            'last_intent'       => 'product_search',
            'last_action'       => $action,
            'last_category_key' => $effectiveCategoryKey,
            'last_query'        => $originalQuery,
            'slots'             => $slots,
        ]);

        if (! empty($products)) {
            $response = $this->productsResponse(
                text: $messageOut ?: "Ось, що знайшов за твоїм запитом 👇",
                products: $products,
                categoryKey: $effectiveCategoryKey
            );

            Log::info('ChatService::productsResponse (fallback text search)', [
                'response' => $response,
            ]);

            return $response;
        }

        return $this->simpleTextResponse(
            $messageOut ?: "Я не знайшов підходящих товарів за цим запитом. Можеш трохи конкретизувати, будь ласка?"
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
                "Напиши, будь ласка, номер замовлення, щоб я міг його перевірити."
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
            "Ти вказав номер замовлення {$orderNumber}. Зараз у демо-версії статуси ще не прив’язані до CRM, але в проді тут буде відстеження посилки 😉"
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
            $messageOut ?: "Ми відправляємо замовлення Новою Поштою по всій Україні, оплата — на карту або післяплата. Якщо треба деталі — напиши, що цікавить: доставка, оплата чи повернення."
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
    protected function simpleTextResponse(string $text): array
    {
        $response = [
            'type' => 'text',
            'text' => $text,
            'data' => null,
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
    protected function productsResponse(string $text, array $products, ?string $categoryKey = null): array
    {
        $response = [
            'type' => 'products',
            'text' => $text,
            'data' => [
                'category_key' => $categoryKey,
                'products'     => $products,
            ],
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
}
