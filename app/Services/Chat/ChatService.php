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
     * @param string $message
     * @param string|null $sessionId
     * @return array
     */
    public function handleMessage(string $message, ?string $sessionId = null): array
    {
        $normalizedMessage = trim($message);

        $sessionKey       = $sessionId ?: request()->ip();
        $contextCacheKey  = 'chat_ctx_' . $sessionKey;

        $context = Cache::get($contextCacheKey, [
            'last_intent'       => null,
            'last_category_key' => null,
            'last_slots'        => [],
        ]);

        Log::info('ChatService::handleMessage incoming', [
            'message'    => $message,
            'session_id' => $sessionId,
            'context'    => $context,
        ]);

        // 1. Швидкі категорії «турнікет», «плитоноска» і т.д.
        $quickCategoryResponse = $this->handleQuickCategoryShortcuts($normalizedMessage);
        if ($quickCategoryResponse !== null) {
            Log::info('ChatService::quickCategoryResponse', [
                'response' => $quickCategoryResponse,
            ]);

            $context['last_intent']       = 'product_search';
            $context['last_category_key'] = $quickCategoryResponse['data']['category_key'] ?? null;
            $context['last_slots']        = [];

            Cache::put($contextCacheKey, $context, now()->addMinutes(30));

            return $quickCategoryResponse;
        }

        // 2. Викликаємо AiRouter
        $aiData = $this->aiRouter->routeChatMessage($normalizedMessage, [
            'session_id' => $sessionKey,
            'context'    => $context,
        ]);

        Log::info('ChatService::AiRouter result', [
            'aiData' => $aiData,
        ]);

        if (! is_array($aiData)) {
            $resp = $this->simpleTextResponse(
                "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏"
            );
            Log::info('ChatService::fallback non-array AiRouter', [
                'response' => $resp,
            ]);

            return $resp;
        }

        $intent      = Arr::get($aiData, 'intent', 'unknown');
        $action      = Arr::get($aiData, 'action', 'NONE');
        $confidence  = (float) Arr::get($aiData, 'confidence', 0.0);
        $categoryKey = Arr::get($aiData, 'category_key');
        $slots       = Arr::get($aiData, 'slots', []);
        $messageOut  = Arr::get($aiData, 'message', '');

        // 3. Оновлюємо контекст
        if ($intent === 'product_search') {
            if ($categoryKey) {
                $context['last_category_key'] = $categoryKey;
            }
            $context['last_intent'] = 'product_search';
            $context['last_slots']  = $slots;
        } elseif (in_array($intent, ['order_status', 'shop_info', 'smalltalk', 'abuse'])) {
            $context['last_intent'] = $intent;
        }

        Cache::put($contextCacheKey, $context, now()->addMinutes(30));

        Log::info('ChatService::updated context', [
            'context' => $context,
        ]);

        // 4. Роутимо по інтенціях
        switch ($intent) {
            case 'product_search':
                $response = $this->handleProductSearchIntent(
                    $normalizedMessage,
                    $action,
                    $confidence,
                    $categoryKey ?: $context['last_category_key'],
                    $slots,
                    $messageOut,
                    $context
                );
                Log::info('ChatService::product_search response', ['response' => $response]);
                return $response;

            case 'order_status':
                $response = $this->handleOrderStatusIntent($aiData);
                Log::info('ChatService::order_status response', ['response' => $response]);
                return $response;

            case 'shop_info':
                $response = $this->handleShopInfoIntent($aiData);
                Log::info('ChatService::shop_info response', ['response' => $response]);
                return $response;

            case 'smalltalk':
                $response = $this->simpleTextResponse($messageOut ?: "Я тут, слухаю 🙂");
                Log::info('ChatService::smalltalk response', ['response' => $response]);
                return $response;

            case 'abuse':
                $response = $this->simpleTextResponse(
                    "Розумію, що може бути нервова ситуація. Якщо хочеш, я допоможу підібрати спорядження або підкажу по замовленню."
                );
                Log::info('ChatService::abuse response', ['response' => $response]);
                return $response;

            case 'unknown':
            default:
                $response = $this->simpleTextResponse(
                    $messageOut ?: "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏"
                );
                Log::info('ChatService::unknown response', ['response' => $response]);
                return $response;
        }
    }

    /**
     * Швидкий хендлер явно сформульованих категорій.
     */
    protected function handleQuickCategoryShortcuts(string $message): ?array
    {
        $norm = mb_strtolower(trim($message));

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
            'бронеплита'  => 'plates',
            'бронеплити'  => 'plates',
            'плита'       => 'plates',
        ];

        if (! array_key_exists($norm, $map)) {
            return null;
        }

        $categoryKey = $map[$norm];

        $products = $this->productService->searchByCategoryKey($categoryKey, 3);

        return $this->productsResponse(
            text: "Ось, що маємо по цій категорії 👇",
            products: $products,
            categoryKey: $categoryKey
        );
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
        array $context = []
    ): array {
        $normalized = mb_strtolower($originalQuery);

        $isMoreRequest =
            str_contains($normalized, 'ще')
            || str_contains($normalized, 'покажи ще')
            || str_contains($normalized, 'ще варіант')
            || str_contains($normalized, 'будь-як');

        // Якщо юзер просить "ще" → використовуємо попередню категорію
        if ($isMoreRequest && ! $categoryKey) {
            $categoryKey = $context['last_category_key'] ?? null;
            if ($categoryKey) {
                $action     = 'SHOW_PRODUCTS';
                $confidence = max($confidence, 0.7);
            }
        }

        // SHOW_PRODUCTS + є категорія → показуємо товари
        if ($action === 'SHOW_PRODUCTS' && $categoryKey && $confidence >= 0.4) {
            $limit = 3;

            $priceFilters = [
                'min' => Arr::get($slots, 'budget_min'),
                'max' => Arr::get($slots, 'budget_max'),
            ];

            $products = $this->productService->searchByCategoryKey(
                categoryKey: $categoryKey,
                limit: $limit,
                priceFilters: $priceFilters
            );

            // Якщо по категорії тихо – пробуємо текстовий пошук
            if (empty($products)) {
                $products = $this->productService->searchByText($originalQuery, null, 'uk');
            }

            if (! empty($products)) {
                return $this->productsResponse(
                    text: $messageOut ?: "Ось, що можу запропонувати 👇",
                    products: $products,
                    categoryKey: $categoryKey
                );
            }

            return $this->simpleTextResponse(
                "Зараз по цій категорії немає товарів в наявності або я не зміг їх знайти 😔 Спробуй сформулювати інакше або обрати іншу категорію."
            );
        }

        // ASK_CLARIFICATION, але є категорія в контексті → показуємо базові варіанти
        if ($action === 'ASK_CLARIFICATION' && ($categoryKey ?: ($context['last_category_key'] ?? null))) {
            $effectiveCategoryKey = $categoryKey ?: $context['last_category_key'];
            $products             = $this->productService->searchByCategoryKey($effectiveCategoryKey, 3);

            if (! empty($products)) {
                return $this->productsResponse(
                    text: $messageOut ?: "Поки ти уточнюєш — ось кілька базових варіантів 👇",
                    products: $products,
                    categoryKey: $effectiveCategoryKey
                );
            }
        }

        // Fallback: текстовий пошук
        $products = $this->productService->searchByText($originalQuery, null, 'uk');

        if (! empty($products)) {
            return $this->productsResponse(
                text: $messageOut ?: "Ось, що знайшов за твоїм запитом 👇",
                products: $products,
                categoryKey: $categoryKey
            );
        }

        return $this->simpleTextResponse(
            $messageOut ?: "Я не знайшов підходящих товарів за цим запитом. Можеш трохи конкретизувати, будь ласка?"
        );
    }

    /**
     * Інтенція: статус замовлення.
     */
    protected function handleOrderStatusIntent(array $aiData): array
    {
        $slots       = Arr::get($aiData, 'slots', []);
        $orderNumber = Arr::get($slots, 'order_number');

        if (! $orderNumber) {
            return $this->simpleTextResponse(
                "Напиши, будь ласка, номер замовлення, щоб я міг його перевірити."
            );
        }

        // TODO: інтеграція з CRM / Horoshop
        return $this->simpleTextResponse(
            "Ти вказав номер замовлення {$orderNumber}. У демо-версії статуси ще не прив’язані до CRM, але в проді тут буде відстеження посилки 😉"
        );
    }

    /**
     * Інтенція: інформація про магазин (доставка/оплата/повернення).
     */
    protected function handleShopInfoIntent(array $aiData): array
    {
        $messageOut = Arr::get($aiData, 'message');

        return $this->simpleTextResponse(
            $messageOut ?: "Ми відправляємо замовлення Новою Поштою по всій Україні, оплата — на карту або післяплата. Якщо треба деталі — напиши, що цікавить: доставка, оплата чи повернення."
        );
    }

    /**
     * Простий формат відповіді тільки з текстом.
     */
    protected function simpleTextResponse(string $text): array
    {
        $resp = [
            'type' => 'text',
            'text' => $text,
            'data' => null,
        ];

        Log::info('ChatService::simpleTextResponse', ['response' => $resp]);

        return $resp;
    }

    /**
     * Формат відповіді з товарами + текст.
     */
    protected function productsResponse(string $text, array $products, ?string $categoryKey = null): array
    {
        $resp = [
            'type' => 'products',
            'text' => $text,
            'data' => [
                'category_key' => $categoryKey,
                'products'     => $products,
            ],
        ];

        Log::info('ChatService::productsResponse', ['response' => $resp]);

        return $resp;
    }
}
