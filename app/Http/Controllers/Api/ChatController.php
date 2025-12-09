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

        // 0) питаємо AI: що це за намір?
        $routing = $this->aiRouter->classify($message);

        $intent           = $routing['intent']           ?? 'UNKNOWN';
        $normalizedQuery  = $routing['normalized_query'] ?? $message;
        $orderId          = $routing['order_id']         ?? null;

        if ($intent === 'FALLBACK') {
            return $this->fallbackPipeline($message, $messageLower);
        }

        if ($intent === 'ORDER_STATUS') {
            if ($orderId) {
                return $this->handleOrderStatusFlow($orderId);
            }

            return response()->json([
                'type'    => 'order_need_id',
                'message' => 'Вкажи, будь ласка, номер замовлення (наприклад: "замовлення 123").',
            ]);
        }

        if ($intent === 'FAQ') {
            if ($answer = $this->faqService->match($messageLower)) {
                return response()->json([
                    'type'    => 'faq',
                    'message' => $answer,
                ]);
            }

            return $this->fallbackPipeline($message, $messageLower);
        }

        if ($intent === 'PRODUCT_SEARCH') {
            $products = $this->productService->searchByText($normalizedQuery);

            if (!empty($products)) {
                return response()->json([
                    'type'     => 'products',
                    'query'    => $normalizedQuery,
                    'products' => $products,
                ]);
            }

            try {
                $aiResult = $this->aiRecommender->recommend($normalizedQuery);

                if ($aiResult) {
                    return response()->json([
                        'type' => 'ai_recommendation',
                        'data' => $aiResult,
                    ]);
                }
            } catch (Throwable $e) {
                report($e);
            }

            return response()->json([
                'type'    => 'no_results',
                'message' => sprintf(
                    'Я не знайшов товарів за запитом: «%s». Спробуй змінити формулювання або вкажи категорію/бренд.',
                    $normalizedQuery
                ),
            ]);
        }

        if ($intent === 'SMALL_TALK') {
            return response()->json([
                'type'    => 'small_talk',
                'message' => 'Я тут, щоб допомогти з товарами і замовленнями 😊 Запитай про товар або замовлення.',
            ]);
        }

        return $this->fallbackPipeline($message, $messageLower);
    }

    protected function fallbackPipeline(string $message, string $messageLower)
    {
        if ($answer = $this->faqService->match($messageLower)) {
            return response()->json([
                'type'    => 'faq',
                'message' => $answer,
            ]);
        }

        $products = $this->productService->searchByText($message);

        if (!empty($products)) {
            return response()->json([
                'type'     => 'products',
                'query'    => $message,
                'products' => $products,
            ]);
        }

        try {
            $aiResult = $this->aiRecommender->recommend($message);

            if ($aiResult) {
                return response()->json([
                    'type' => 'ai_recommendation',
                    'data' => $aiResult,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json([
            'type'    => 'no_results',
            'message' => sprintf(
                'Я не знайшов товарів за запитом: «%s». Спробуй змінити формулювання або вкажи категорію/бренд.',
                $message
            ),
        ]);
    }

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
