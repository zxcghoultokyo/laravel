<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FaqService;
use App\Services\Horoshop\ProductService;
use App\Services\Horoshop\OrderService;
use App\Services\Ai\AiRecommender;
use App\Services\Ai\AiRouter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        protected FaqService $faqService,
        protected ProductService $productService,
        protected OrderService $orderService,
        protected AiRecommender $aiRecommender,
        protected AiRouter $aiRouter,
    ) {
    }

    /**
     * Головна точка входу для веб-чату /api/chat.
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $message      = trim($data['message']);
        $messageLower = mb_strtolower($message, 'UTF-8');

        // 0) Питаємо AI-роутер: який намір у користувача?
        $routing = $this->aiRouter->classify($message);

        $intent          = strtoupper($routing['intent'] ?? 'UNKNOWN');
        $normalizedQuery = $routing['normalized_query'] ?? $message;
        $orderId         = $routing['order_id'] ?? null;

        // 1) Якщо це запит по замовленню і вже є номер — одразу працюємо з ним
        if ($intent === 'ORDER_STATUS' && $orderId) {
            return $this->handleOrderStatusFlow((int) $orderId);
        }

        // 1.1) Якщо фраза про замовлення, але без номера
        if ($intent === 'ORDER_STATUS' && ! $orderId) {
            return response()->json([
                'type'    => 'order_need_id',
                'message' => 'Вкажи, будь ласка, номер замовлення (наприклад: "замовлення 123").',
            ]);
        }

        // 2) FAQ / довідка
        if ($intent === 'FAQ') {
            if ($answer = $this->faqService->match($messageLower)) {
                return response()->json([
                    'type'    => 'faq',
                    'message' => $answer,
                ]);
            }

            // Якщо в FAQ нічого не знайшли – спробуємо fallback-пайплайн
            return $this->fallbackPipeline($message, $messageLower);
        }

        // 3) Пошук товарів
        if ($intent === 'PRODUCT_SEARCH') {
            // Беремо побільше кандидатів, потім AiRecommender їх відсортує
            $products = $this->productService->searchByText($normalizedQuery, 20);

            if (! empty($products)) {
                try {
                    // тут відбувається “даблчек” релевантності на стороні AI
                    $recommended = $this->aiRecommender->recommend($normalizedQuery, $products, 3);
                } catch (Throwable $e) {
                    report($e);
                    // На всякий випадок — повертаємо як є
                    $recommended = $products;
                }

                return response()->json([
                    'type'     => 'products',
                    'query'    => $normalizedQuery,
                    'products' => $recommended,
                ]);
            }

            // Нічого не знайшли — no_results
            return response()->json([
                'type'    => 'no_results',
                'message' => sprintf(
                    'Я не знайшов товарів за запитом: «%s». ' .
                    'Спробуй змінити формулювання або вкажи категорію/бренд.',
                    $normalizedQuery
                ),
            ]);
        }

        // 4) Small-talk / болталка
        if ($intent === 'SMALL_TALK') {
            // Поки без окремого AI-ендпоінта — проста відповідь
            return response()->json([
                'type'    => 'small_talk',
                'message' => 'Я тут, щоб допомогти з товарами і замовленнями 😊 Запитай про товар або замовлення.',
            ]);
        }

        // 5) Будь-що інше — fallback-пайплайн (FAQ → пошук товарів → no_results)
        return $this->fallbackPipeline($message, $messageLower);
    }

    /**
     * Fallback-пайплайн:
     * 1) спробувати FAQ
     * 2) спробувати пошук товарів + AI-сортування
     * 3) no_results
     */
    protected function fallbackPipeline(string $message, string $messageLower): JsonResponse
    {
        // 1) FAQ
        if ($answer = $this->faqService->match($messageLower)) {
            return response()->json([
                'type'    => 'faq',
                'message' => $answer,
            ]);
        }

        // 2) Пошук товарів
        $products = $this->productService->searchByText($message, 20);

        if (! empty($products)) {
            try {
                $recommended = $this->aiRecommender->recommend($message, $products, 3);
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
                'Я не знайшов відповіді на запит: «%s». ' .
                'Спробуй переформулювати або вкажи більше деталей.',
                $message
            ),
        ]);
    }

    /**
     * Обробка запиту по статусу замовлення за номером.
     */
    protected function handleOrderStatusFlow(int $orderId): JsonResponse
    {
        try {
            $order = $this->orderService->getById($orderId);

            if (! $order) {
                return response()->json([
                    'type'    => 'order_not_found',
                    'message' => "Я не знайшов замовлення №{$orderId}. Перевір, будь ласка, номер.",
                ]);
            }

            $status     = $order['status']['title'] ?? $order['status'] ?? 'обробка';
            $total      = $order['total'] ?? 0;
            $currency   = $order['currency'] ?? 'UAH';
            $createdAt  = $order['created_at'] ?? null;
            $items      = $order['items'] ?? [];

            $lines   = [];
            $lines[] = "Замовлення №{$orderId} зараз у статусі: «{$status}».";
            $lines[] = "Сума: {$total} {$currency}.";

            if ($createdAt) {
                $lines[] = "Оформлено: {$createdAt}.";
            }

            if (! empty($items)) {
                $lines[] = '';
                $lines[] = 'Товари в замовленні:';

                foreach ($items as $item) {
                    $name  = $item['name'] ?? 'Товар';
                    $qty   = $item['quantity'] ?? ($item['qty'] ?? 1);
                    $price = $item['price'] ?? 0;

                    $lines[] = sprintf('- %s × %s шт. (%s %s)', $name, $qty, $price, $currency);
                }
            }

            $statusText = implode("\n", $lines);

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
