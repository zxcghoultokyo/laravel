<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Horoshop\OrderSearchService;
use Illuminate\Http\Request;

class OrderSearchController extends Controller
{
    public function __construct(
        protected OrderSearchService $searchService
    ) {}

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
        // Accept either natural language query or structured criteria
        $query = $request->input('query');
        $orderIdParam = $request->input('order_id');
        $phone = $request->input('phone');
        $name = $request->input('name');
        $email = $request->input('email');
        $limit = (int) ($request->input('limit', 10));

        // If query provided, parse it
        $criteria = [];
        if (!empty($query)) {
            $criteria = $this->searchService->parseQuery($query);
        } else {
            // Use explicit criteria
            if (!empty($orderIdParam)) {
                $criteria['order_id'] = (int) $orderIdParam;
            }
            if (!empty($phone)) {
                $criteria['phone'] = $phone;
            }
            if (!empty($name)) {
                $criteria['name'] = $name;
            }
            if (!empty($email)) {
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

        // Perform search
        $result = $this->searchService->search($criteria);

        $status = match ($result['total']) {
            0 => 'not_found',
            1 => 'single_result',
            default => 'multiple_results',
        };

        return response()->json([
            'type' => 'orders_search',
            'status' => $status,
            'search_type' => $result['search_type'],
            'query' => $query,
            'criteria' => $criteria,
            'total' => $result['total'],
            'orders' => $result['orders'],
            'message' => $this->buildMessage($status, $result['total'], $result['search_type']),
        ]);
    }

    private function buildMessage(string $status, int $total, string $searchType): string
    {
        return match ($status) {
            'not_found' => "Замовлення не знайдено за цими параметрами ({$searchType}).",
            'single_result' => "Знайдено 1 замовлення.",
            'multiple_results' => "Знайдено {$total} замовлень.",
            default => "Пошук завершено.",
        };
    }
}
