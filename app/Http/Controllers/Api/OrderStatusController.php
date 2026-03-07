<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Horoshop\HoroshopClient;
use App\Services\Horoshop\OrderService;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
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

        // Resolve tenant-specific HoroshopClient
        $orderService = $this->resolveOrderService();
        if (! $orderService) {
            return response()->json([
                'type' => 'error',
                'message' => 'Horoshop не налаштований для цього магазину.',
            ], 503);
        }

        $rawOrder = $orderService->getById($orderId);

        if (! $rawOrder) {
            return response()->json([
                'type' => 'not_found',
                'message' => "Замовлення №{$orderId} не знайдено.",
            ], 404);
        }

        $order = $orderService->normalize($rawOrder);

        return response()->json([
            'type' => 'order_status',
            'order' => $order,
        ]);
    }

    /**
     * Resolve OrderService with tenant-specific Horoshop credentials.
     */
    protected function resolveOrderService(): ?OrderService
    {
        $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;

        if ($tenant && ! empty($tenant->platform_credentials)) {
            $creds = $tenant->platform_credentials;
            $domain = is_array($creds['domain'] ?? null) ? ($creds['domain']['value'] ?? '') : (string) ($creds['domain'] ?? '');
            $login = is_array($creds['login'] ?? null) ? ($creds['login']['value'] ?? '') : (string) ($creds['login'] ?? '');
            $password = is_array($creds['password'] ?? null) ? ($creds['password']['value'] ?? '') : (string) ($creds['password'] ?? '');
            if ($domain && $login && $password) {
                $client = new HoroshopClient($domain, $login, $password);

                return new OrderService($client);
            }
        }

        // Fallback to default (legacy single-tenant)
        return app(OrderService::class);
    }
}
