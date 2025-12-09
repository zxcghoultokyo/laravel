<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Horoshop\OrderService;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
    public function __construct(
        protected OrderService $orderService
    ) {}

    /**
     * Повертає інформацію про замовлення по його номеру (order_id).
     *
     * POST /api/order-status
     * {
     *   "order_id": 123
     * }
     */
    public function show(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
        ]);

        $orderId = (int) $data['order_id'];

        $rawOrder = $this->orderService->getById($orderId);

        if (! $rawOrder) {
            return response()->json([
                'type'    => 'not_found',
                'message' => "Замовлення №{$orderId} не знайдено.",
            ], 404);
        }

        $order = $this->orderService->normalize($rawOrder);

        return response()->json([
            'type'  => 'order_status',
            'order' => $order,
        ]);
    }
}
