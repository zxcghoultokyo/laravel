<?php

namespace App\Services\Chat;

use App\Services\Ai\AiRouter;
use App\Services\Horoshop\ProductService;
use Illuminate\Support\Arr;

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

        // 1. Простий rule-based хендлер на чисті категорії (турнікети, шоломи, плитоноски)
        $quickCategoryResponse = $this->handleQuickCategoryShortcuts($normalizedMessage);
        if ($quickCategoryResponse !== null) {
            return $quickCategoryResponse;
        }

        // 2. Викликаємо AiRouter, щоб отримати JSON із інтенцією / дією / категорією
        $aiData = $this->aiRouter->routeChatMessage($normalizedMessage, [
            'session_id' => $sessionId,
        ]);

        // Перестраховка: навіть якщо AiRouter верне щось криве – не ламаємо чат
        if (! is_array($aiData)) {
            return $this->simpleTextResponse(
                "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏"
            );
        }

        $intent     = Arr::get($aiData, 'intent', 'unknown');
        $action     = Arr::get($aiData, 'action', 'NONE');
        $confidence = (float) Arr::get($aiData, 'confidence', 0.0);
        $categoryKey= Arr::get($aiData, 'category_key');
        $slots      = Arr::get($aiData, 'slots', []);
        $messageOut = Arr::get($aiData, 'message', '');

        // 3. Роутимо по інтенції / дії
        switch ($intent) {
            case 'product_search':
                return $this->handleProductSearchIntent(
                    $normalizedMessage,
                    $action,
                    $confidence,
                    $categoryKey,
                    $slots,
                    $messageOut
                );

            case 'order_status':
                return $this->handleOrderStatusIntent($aiData);

            case 'shop_info':
                return $this->handleShopInfoIntent($aiData);

            case 'smalltalk':
                return $this->simpleTextResponse($messageOut ?: "Я тут, слухаю 🙂");

            case 'abuse':
                // Можна м’яко відповідати або ігнорити – залежить від політики
                return $this->simpleTextResponse(
                    "Розумію, що може бути нервова ситуація. Якщо хочеш, я допоможу підібрати спорядження або підкажу по замовленню."
                );

            case 'unknown':
            default:
                return $this->simpleTextResponse(
                    $messageOut ?: "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏"
                );
        }
    }

    /**
     * Швидкий хендлер для дуже явних запитів типу "турнікети", "шоломи".
     * Це працює навіть без AI, щоб юзер відразу бачив товар.
     */
    protected function handleQuickCategoryShortcuts(string $message): ?array
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
        string $messageOut
    ): array {
        // Якщо AI каже SHOW_PRODUCTS і є категорія + нормальна впевненість
        if ($action === 'SHOW_PRODUCTS' && $categoryKey && $confidence >= 0.6) {
            $limit = 3;

            // Можемо зчитати бюджет із slots, якщо треба
            $priceFilters = [
                'min' => Arr::get($slots, 'budget_min'),
                'max' => Arr::get($slots, 'budget_max'),
            ];

            $products = $this->productService->searchByCategoryKey(
                categoryKey: $categoryKey,
                limit: $limit,
                priceFilters: $priceFilters
            );

            if (empty($products)) {
                return $this->simpleTextResponse(
                    "Зараз по цій категорії немає товарів в наявності або я не зміг їх знайти 😔 Спробуй сформулювати інакше або обрати іншу категорію."
                );
            }

            return $this->productsResponse(
                text: $messageOut ?: "Ось, що можу запропонувати 👇",
                products: $products,
                categoryKey: $categoryKey
            );
        }

        // Якщо AI хоче уточнення
        if ($action === 'ASK_CLARIFICATION') {
            return $this->simpleTextResponse(
                $messageOut ?: "Уточни, будь ласка, який саме товар або під які задачі тобі потрібен."
            );
        }

        // Fallback – просто спробуємо текстовий пошук
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
     * Тут поки просто шаблон – ти можеш підключити свій CRM/ERP.
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

        // TODO: тут ти підключаєшся до CRM / Horoshop / NovaPoshta API і т.д.
        // Поки що просто шаблонна відповідь.
        return $this->simpleTextResponse(
            "Ти вказав номер замовлення {$orderNumber}. Зараз у демо-версії статуси ще не прив’язані до CRM, але в проді тут буде відстеження посилки 😉"
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
        return [
            'type' => 'text',
            'text' => $text,
            'data' => null,
        ];
    }

    /**
     * Формат відповіді з товарами + текст.
     *
     * $products тут – масив з ProductService::normalizeProductForApi()
     */
    protected function productsResponse(string $text, array $products, ?string $categoryKey = null): array
    {
        return [
            'type' => 'products',
            'text' => $text,
            'data' => [
                'category_key' => $categoryKey,
                'products'     => $products,
            ],
        ];
    }
}
