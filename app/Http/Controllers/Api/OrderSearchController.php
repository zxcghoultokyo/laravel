<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Horoshop\DeliveryTrackingService;
use App\Services\Horoshop\HoroshopClient;
use App\Services\Horoshop\OrderSearchService;
use App\Services\Horoshop\OrderService;
use Illuminate\Http\Request;

class OrderSearchController extends Controller
{
    /**
     * Search orders by flexible criteria.
     *
     * POST /api/orders/search
     * {
     *   "query": "замовлення 12345 Іванова",
     *   // or explicit criteria:
     *   "order_id": 12345,
     *   "phone": "+38 (095) 123-45-67",
     *   "name": "Іванова",
     *   "email": "ivan@example.com",
     *   "limit": 10
     * }
     */
    public function search(Request $request)
    {
        $searchService = $this->resolveSearchService();
        $trackingService = app(DeliveryTrackingService::class);

        // Accept either natural language query or structured criteria
        $query = $request->input('query');
        $orderIdParam = $request->input('order_id');
        $phone = $request->input('phone');
        $name = $request->input('name');
        $email = $request->input('email');
        $limit = (int) ($request->input('limit', 10));

        // If query provided, parse it
        $criteria = [];
        if (! empty($query)) {
            $criteria = $searchService->parseQuery($query);
        } else {
            if (! empty($orderIdParam)) {
                $criteria['order_id'] = (int) $orderIdParam;
            }
            if (! empty($phone)) {
                $criteria['phone'] = $phone;
            }
            if (! empty($name)) {
                $criteria['name'] = $name;
            }
            if (! empty($email)) {
                $criteria['email'] = $email;
            }
        }

        if (empty($criteria)) {
            return response()->json([
                'type' => 'orders_search',
                'status' => 'empty_query',
                'message' => 'Будь ласка, вкажіть номер замовлення, телефон, ім\'я або email.',
                'orders' => [],
                'total' => 0,
            ]);
        }

        $criteria['limit'] = $limit;

        $result = $searchService->search($criteria);

        $status = match ($result['total']) {
            0 => 'not_found',
            1 => 'single_result',
            default => 'multiple_results',
        };

        $enrichedOrders = array_map(function ($order) use ($trackingService) {
            $deliveryInfo = $trackingService->formatDeliveryInfo($order);

            return array_merge($order, [
                'delivery_tracking' => $deliveryInfo,
            ]);
        }, $result['orders']);

        return response()->json([
            'type' => 'orders_search',
            'status' => $status,
            'search_type' => $result['search_type'],
            'query' => $query,
            'criteria' => $criteria,
            'total' => $result['total'],
            'orders' => $enrichedOrders,
            'message' => $this->buildMessage($status, $result['total'], $result['search_type']),
        ]);
    }

    /**
     * Build tenant-specific OrderSearchService.
     */
    protected function resolveSearchService(): OrderSearchService
    {
        $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;

        if ($tenant && ! empty($tenant->platform_credentials)) {
            $creds = $tenant->platform_credentials;
            $domain = is_array($creds['domain'] ?? null) ? ($creds['domain']['value'] ?? '') : (string) ($creds['domain'] ?? '');
            $login = is_array($creds['login'] ?? null) ? ($creds['login']['value'] ?? '') : (string) ($creds['login'] ?? '');
            $password = is_array($creds['password'] ?? null) ? ($creds['password']['value'] ?? '') : (string) ($creds['password'] ?? '');
            if ($domain && $login && $password) {
                $client = new HoroshopClient($domain, $login, $password);
                $orderService = new OrderService($client);

                return new OrderSearchService($client, $orderService, app(DeliveryTrackingService::class));
            }
        }

        return app(OrderSearchService::class);
    }

    private function buildMessage(string $status, int $total, string $searchType): string
    {
        return match ($status) {
            'not_found' => "Замовлення не знайдено за цими параметрами ({$searchType}).",
            'single_result' => 'Знайдено 1 замовлення.',
            'multiple_results' => "Знайдено {$total} замовлень.",
            default => 'Пошук завершено.',
        };
    }
}
