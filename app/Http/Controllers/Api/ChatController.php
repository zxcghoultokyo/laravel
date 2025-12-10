<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FaqService;
use App\Services\Horoshop\ProductService;
use App\Services\Horoshop\OrderService;
use App\Services\Ai\AiRecommender;
use App\Services\Ai\AiRouter;
use Illuminate\Http\Request;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        protected FaqService $faqService,
        protected ProductService $productService,
        protected OrderService $orderService,
        protected AiRecommender $aiRecommender,
        protected AiRouter $aiRouter,
    ) {}

    public function handle(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $message      = trim($data['message']);
        $messageLower = mb_strtolower($message, 'UTF-8');

        // 0) Питаємо AI-роутер: що це за намір?
        $routing = $this->aiRouter->classify($message);

        $intent          = $routing['intent']           ?? 'UNKNOWN';
        $normalizedQuery = $routing['normalized_query'] ?? $message;
        $orderId         = $routing['order_id']         ?? null;

        // ---- ROUTING ПО ІНТЕНТАМ ----

        // 1) Якщо AI каже "не знаю, що це" — запускаємо fallback пайплайн
        if ($intent === 'FALLBACK' || $intent === 'UNKNOWN') {
            return $this->fallbackPipeline($message, $messageLower);
        }

        // 2) Статус замовлення
        if ($intent === 'ORDER_STATUS') {
            if ($orderId) {
                return $this->handleOrderStatusFlow($orderId);
            }

            return response()->json([
                'type'    => 'order_need_id',
                'message' => 'Вкажи, будь ласка, номер замовлення (наприклад: "замовлення 123").',
            ]);
        }

        // 3) FAQ / довідка
        if ($intent === 'FAQ') {
            if ($answer = $this->faqService->match($messageLower)) {
                return response()->json([
                    'type'    => 'faq',
                    'message' => $answer,
                ]);
            }

            // Якщо в FAQ нічого не знайшли – fallback
            return $this->fallbackPipeline($message, $messageLower);
        }

        // 4) Пошук товарів
        if ($intent === 'PRODUCT_SEARCH') {
            $products = $this->productService->searchByText($normalizedQuery);

            if (! empty($products)) {
                // Пропускаємо товари через "розумний" сортер
                try {
                    $recommended = $this->aiRecommender->recommend($normalizedQuery, $products);
                } catch (Throwable $e) {
                    report($e);
                    $recommended = $products; // на всякий випадок – віддамо, як є
                }

                return response()->json([
                    'type'     => 'products',
                    'query'    => $normalizedQuery,
                    'products' => $recommended,
                ]);
            }

            // Якщо нічого не знайшли – просто no_results
            return response()->json([
                'type'    => 'no_results',
                'message' => sprintf(
                    'Я не знайшов товарів за запитом: «%s». ' .
                    'Спробуй змінити формулювання або вкажи категорію/бренд.',
                    $normalizedQuery
                ),
            ]);
        }

        // 5) Small talk / болталка
        if ($intent === 'SMALL_TALK') {
            return response()->json([
                'type'    => 'small_talk',
                'message' => 'Я тут, щоб допомогти з товарами і замовленнями 😊 Запитай про товар або замовлення.',
            ]);
        }

        // 6) Все інше — через fallback (FAQ → пошук товарів → no_results)
        return $this->fallbackPipeline($message, $messageLower);
    }

    /**
     * Fallback-пайплайн:
     * 1) спробувати FAQ
     * 2) спробувати пошук товарів + AI-сортування
     * 3) no_results
     */
    protected function fallbackPipeline(string $message, string $messageLower)
    {
        // 1) FAQ
        if ($answer = $this->faqService->match($messageLower)) {
            return response()->json([
                'type'    => 'faq',
                'message' => $answer,
            ]);
        }

        // 2) Пошук товарів на Horoshop
        $products = $this->productService->searchByText($message);

        if (! empty($products)) {
            try {
                // Тут теж передаємо і текст, і масив товарів
                $recommended = $this->aiRecommender->recommend($message, $products);
            } catch (Throwable $e) {
                report($e);
                $recommended = $products;
            }

            return response()->json([
                'type'     => 'products',
                'query'    => $message,
                'products' => $recommended,
            ]);
        }

        // 3) Нічого не знайшли
        return response()->json([
            'type'    => 'no_results',
            'message' => sprintf(
                'Я не знайшов товарів за запитом: «%s». ' .
                'Спробуй змінити формулювання або вкажи категорію/бренд.',
                $message
            ),
        ]);
    }

    /**
     * Обробка флоу "статус замовлення"
     */
    protected function handleOrderStatusFlow(int $orderId)
    {
        try {
            $rawOrder = $this->orderService->getById($orderId);

            if (! $rawOrder) {
                return response()->json([
                    'type'    => 'order_not_found',
                    'message' => "Замовлення №{$orderId} не знайдено. Перевір номер або дату оформлення.",
                ], 404);
            }

            $order = $this->orderService->normalize($rawOrder);

            $statusText = sprintf(
                "Замовлення №%d зараз у статусі: «%s».\nСума: %s %s.\nОформлено: %s.",
                $order['order_id'],
                $order['status_label'],
                $order['total']['total_sum'] ?? '—',
                $order['currency'] ?? '',
                $order['created_at'] ?? 'невідомо'
            );

            return response()->json([
                'type'     => 'order_status',
                'message'  => $statusText,
                'order_id' => $orderId,
                'order'    => $order,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'type'    => 'order_error',
                'message' => "Не вдалося отримати інформацію про замовлення №{$orderId}. Спробуй пізніше або звернись до оператора.",
            ], 500);
        }
    }
}
