<?php

namespace App\Services\Chat;

use App\Services\Ai\AiRouter;
use App\Services\Horoshop\ProductService;
use Illuminate\Support\Arr;
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
     * @return array — нормалізована відповідь для фронту
     */
    public function handleMessage(string $message, ?string $sessionId = null): array
    {
        $normalizedMessage = trim($message);

        // 🔥 ЛОГУЄМО ВХІДНЕ ПОВІДОМЛЕННЯ
        Log::info('ChatService::handleMessage incoming', [
            'message'    => $normalizedMessage,
            'session_id' => $sessionId,
        ]);

        // 1. Швидкі категорії
        $quickCategoryResponse = $this->handleQuickCategoryShortcuts($normalizedMessage);
        if ($quickCategoryResponse !== null) {
            Log::info('ChatService::quickCategoryResponse', ['response' => $quickCategoryResponse]);
            return $quickCategoryResponse;
        }

        // 2. Викликаємо AiRouter
        $aiData = $this->aiRouter->routeChatMessage($normalizedMessage, [
            'session_id' => $sessionId,
        ]);

        // 🔥 ЛОГУЄМО ВІДПОВІДЬ ВІД AiRouter
        Log::info('ChatService::AiRouter result', [
            'aiData' => $aiData,
        ]);

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

        // 3. Роутимо за інтенцією
        switch ($intent) {
            case 'product_search':
                $resp = $this->handleProductSearchIntent(
                    $normalizedMessage, $action, $confidence, $categoryKey, $slots, $messageOut
                );
                Log::info('ChatService::product_search response', ['response' => $resp]);
                return $resp;

            case 'order_status':
                $resp = $this->handleOrderStatusIntent($aiData);
                Log::info('ChatService::order_status response', ['response' => $resp]);
                return $resp;

            case 'shop_info':
                $resp = $this->handleShopInfoIntent($aiData);
                Log::info('ChatService::shop_info response', ['response' => $resp]);
                return $resp;

            case 'smalltalk':
                $resp = $this->simpleTextResponse($messageOut ?: "Я тут, слухаю 🙂");
                Log::info('ChatService::smalltalk response', ['response' => $resp]);
                return $resp;

            case 'abuse':
                $resp = $this->simpleTextResponse(
                    "Розумію, що може бути нервова ситуація. Якщо хочеш, я допоможу підібрати спорядження або підкажу по замовленню."
                );
                Log::info('ChatService::abuse response', ['response' => $resp]);
                return $resp;

            default:
                $resp = $this->simpleTextResponse(
                    $messageOut ?: "Я трохи не зрозумів запит. Спробуй сформулювати ще раз 🙏"
                );
                Log::info('ChatService::unknown response', ['response' => $resp]);
                return $resp;
        }
    }


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
            "Ось, що маємо по цій категорії 👇",
            $products,
            $categoryKey
        );
    }


    protected function handleProductSearchIntent(
        string $originalQuery,
        string $action,
        float $confidence,
        ?string $categoryKey,
        array $slots,
        string $messageOut
    ): array {
        if ($action === 'SHOW_PRODUCTS' && $categoryKey && $confidence >= 0.6) {

            $products = $this->productService->searchByCategoryKey($categoryKey, 3);

            if (empty($products)) {
                return $this->simpleTextResponse(
                    "Поки немає товарів у цій категорії 😔"
                );
            }

            return $this->productsResponse(
                $messageOut ?: "Ось, що можу запропонувати 👇",
                $products,
                $categoryKey
            );
        }

        if ($action === 'ASK_CLARIFICATION') {
            return $this->simpleTextResponse(
                $messageOut ?: "Уточни, будь ласка, який саме товар тобі потрібен."
            );
        }

        $products = $this->productService->searchByText($originalQuery, null, 'uk');

        if (! empty($products)) {
            return $this->productsResponse(
                $messageOut ?: "Ось, що я знайшов 👇",
                $products,
                $categoryKey
            );
        }

        return $this->simpleTextResponse(
            "Не знайшов нічого за твоїм запитом. Можеш трохи уточнити?"
        );
    }


    protected function handleOrderStatusIntent(array $aiData): array
    {
        $orderNumber = Arr::get($aiData, 'slots.order_number');

        if (! $orderNumber) {
            return $this->simpleTextResponse(
                "Напиши, будь ласка, номер замовлення, щоб я міг його перевірити."
            );
        }

        return $this->simpleTextResponse(
            "Ти вказав номер замовлення {$orderNumber}. У демо-версії статуси ще не прив’язані до CRM."
        );
    }


    protected function handleShopInfoIntent(array $aiData): array
    {
        $messageOut = Arr::get($aiData, 'message');

        return $this->simpleTextResponse(
            $messageOut ?: "Доставка — НП, оплата — на карту або післяплата."
        );
    }


    protected function simpleTextResponse(string $text): array
    {
        $resp = [
            'type' => 'text',
            'text' => $text,
            'data' => null,
        ];

        // 🔥 ЛОГУЄМО ФІНАЛЬНУ ВІДПОВІДЬ
        Log::info('ChatService::simpleTextResponse', ['response' => $resp]);

        return $resp;
    }


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

        // 🔥 ЛОГУЄМО ФІНАЛЬНУ ВІДПОВІДЬ З ТОВАРАМИ
        Log::info('ChatService::productsResponse', ['response' => $resp]);

        return $resp;
    }
}
