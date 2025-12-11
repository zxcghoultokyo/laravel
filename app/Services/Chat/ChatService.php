<?php

namespace App\Services\Chat;

use App\Services\Ai\AiRouter;
use App\Services\Horoshop\ProductService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ChatService
{
    public function __construct(
        protected AiRouter $aiRouter,
        protected ProductService $productService,
    ) {}

    public function handleMessage(string $message, ?string $sessionId = null): array
    {
        $normalizedMessage = trim($message);

        // якщо sessionId немає — хоч якось стабілізуємо (але краще передавати з фронта)
        $sessionKey = $sessionId ?: request()->ip();
        $contextCacheKey = 'chat_ctx_'.$sessionKey;

        $context = Cache::get($contextCacheKey, [
            'last_intent'       => null,
            'last_category_key' => null,
            'last_slots'        => [],
        ]);

        // 1. Швидкі категорії (турнікет, плитоноска і т.д.)
        $quickCategoryResponse = $this->handleQuickCategoryShortcuts($normalizedMessage);
        if ($quickCategoryResponse !== null) {
            // зберігаємо контекст категорії
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

        if (! is_array($aiData)) {
            return $this->simpleTextResponse(
                "Я трохи не зрозумів запит. Спробуй сформулювати ще раз, будь ласка 🙏"
            );
        }

        $intent      = Arr::get($aiData, 'intent', 'unknown');
        $action      = Arr::get($aiData, 'action', 'NONE');
        $confidence  = (float) Arr::get($aiData, 'confidence', 0.0);
        $categoryKey = Arr::get($aiData, 'category_key');
        $slots       = Arr::get($aiData, 'slots', []);
        $messageOut  = Arr::get($aiData, 'message', '');

        // Оновлюємо контекст (навіть якщо AiRouter не дав categoryKey – ми це врахуємо)
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

        // 3. Роутимо
        switch ($intent) {
            case 'product_search':
                return $this->handleProductSearchIntent(
                    $normalizedMessage,
                    $action,
                    $confidence,
                    $categoryKey ?: $context['last_category_key'], // важливе місце
                    $slots,
                    $messageOut,
                    $context
                );

            case 'order_status':
                return $this->handleOrderStatusIntent($aiData);

            case 'shop_info':
                return $this->handleShopInfoIntent($aiData);

            case 'smalltalk':
                return $this->simpleTextResponse($messageOut ?: "Я тут, слухаю 🙂");

            case 'abuse':
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
