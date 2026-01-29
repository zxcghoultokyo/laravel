<?php

namespace App\Services\Horoshop;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Service for flexible order search by phone, name, order_id, or combinations.
 * Supports smart pattern matching for chat-based queries.
 */
class OrderSearchService
{
    public function __construct(
        protected HoroshopClient $client,
        protected OrderService $orderService,
        protected DeliveryTrackingService $trackingService,
    ) {}
    
    /**
     * Check if Horoshop is configured
     */
    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Search orders by flexible criteria.
     *
     * @param array $criteria {
     *   'order_id' => int|null,
     *   'phone' => string|null,
     *   'name' => string|null,      // ім'я або прізвище
     *   'email' => string|null,
     *   'limit' => int (default 10)
     * }
     *
     * @return array ['orders' => [...], 'total' => int, 'search_type' => string]
     */
    public function search(array $criteria): array
    {
        $orderId = $criteria['order_id'] ?? null;
        $phone = $criteria['phone'] ?? null;
        $name = $criteria['name'] ?? null;
        $email = $criteria['email'] ?? null;
        $limit = (int) ($criteria['limit'] ?? 10);

        Log::info('OrderSearchService: searching', compact('orderId', 'phone', 'name', 'email', 'limit'));

        // Priority 1: exact order_id (but MUST verify phone if provided for security!)
        if (!empty($orderId)) {
            $raw = $this->orderService->getById($orderId);
            if ($raw) {
                // SECURITY: If phone was provided, MUST match order's phone
                // This prevents exposing ANY order by just knowing the order number
                if (!empty($phone)) {
                    $orderPhone = $raw['delivery_phone'] ?? '';
                    if (!$this->matchPhone($raw, $phone)) {
                        Log::warning('OrderSearchService: phone mismatch for order_id lookup', [
                            'order_id' => $orderId,
                            'provided_phone' => $phone,
                            'order_phone' => $orderPhone,
                        ]);
                        // Return empty - don't reveal that order exists with different phone
                        return ['orders' => [], 'total' => 0, 'search_type' => 'order_id', 'error' => 'phone_mismatch'];
                    }
                }
                
                return [
                    'orders' => [$this->orderService->normalize($raw)],
                    'total' => 1,
                    'search_type' => 'order_id',
                ];
            }
            return ['orders' => [], 'total' => 0, 'search_type' => 'order_id'];
        }

        // Priority 2: fetch all recent orders and filter locally
        // (Horoshop API doesn't support direct phone/name filtering)
        $allOrders = $this->fetchRecentOrders(50);

        if (empty($allOrders)) {
            return ['orders' => [], 'total' => 0, 'search_type' => 'none'];
        }

        $filtered = $allOrders;

        // Progressive search: try all criteria first, then relax if nothing found
        $hasPhone = !empty($phone);
        $hasName = !empty($name);
        $hasEmail = !empty($email);
        $criteriaCount = ($hasPhone ? 1 : 0) + ($hasName ? 1 : 0) + ($hasEmail ? 1 : 0);

        if ($criteriaCount > 1) {
            // Try all criteria together first
            $strictFiltered = $filtered;
            if ($hasPhone) {
                $strictFiltered = array_filter($strictFiltered, fn($o) => $this->matchPhone($o, $phone));
            }
            if ($hasName) {
                $strictFiltered = array_filter($strictFiltered, fn($o) => $this->matchName($o, $name));
            }
            if ($hasEmail) {
                $strictFiltered = array_filter($strictFiltered, fn($o) => $this->matchEmail($o, $email));
            }

            if (!empty($strictFiltered)) {
                $filtered = $strictFiltered;
            } else {
                // Nothing found with all criteria - try each individually
                Log::info('OrderSearchService: strict search returned 0, relaxing to OR logic');
                $relaxedFiltered = [];
                if ($hasPhone) {
                    $relaxedFiltered = array_merge($relaxedFiltered, array_filter($allOrders, fn($o) => $this->matchPhone($o, $phone)));
                }
                if ($hasName) {
                    $relaxedFiltered = array_merge($relaxedFiltered, array_filter($allOrders, fn($o) => $this->matchName($o, $name)));
                }
                if ($hasEmail) {
                    $relaxedFiltered = array_merge($relaxedFiltered, array_filter($allOrders, fn($o) => $this->matchEmail($o, $email)));
                }
                // Deduplicate by order ID
                $uniqueOrders = [];
                foreach ($relaxedFiltered as $order) {
                    $id = $order['id'] ?? null;
                    if ($id && !isset($uniqueOrders[$id])) {
                        $uniqueOrders[$id] = $order;
                    }
                }
                $filtered = array_values($uniqueOrders);
            }
        } else {
            // Single criterion - just filter
            if ($hasPhone) {
                $filtered = array_filter($filtered, fn($o) => $this->matchPhone($o, $phone));
            }
            if ($hasName) {
                $filtered = array_filter($filtered, fn($o) => $this->matchName($o, $name));
            }
            if ($hasEmail) {
                $filtered = array_filter($filtered, fn($o) => $this->matchEmail($o, $email));
            }
        }

        $filtered = array_values($filtered); // Reindex
        $total = count($filtered);
        $limited = array_slice($filtered, 0, $limit);

        // Normalize results
        $orders = array_map(fn($raw) => $this->orderService->normalize($raw), $limited);

        return [
            'orders' => $orders,
            'total' => $total,
            'search_type' => $this->detectSearchType($criteria),
        ];
    }

    /**
     * Parse a natural user message to extract order search criteria.
     * Examples:
     *   "номер замовлення 12345" => ['order_id' => 12345]
     *   "замовлення Іванова" => ['name' => 'Іванова']
     *   "статус замовлення +38 (095) 123-45-67" => ['phone' => '+38(095)12345467']
     *   "замовлення 12345 Іванова" => ['order_id' => 12345, 'name' => 'Іванова']
     */
    public function parseQuery(string $message): array
    {
        $criteria = [];

        // Extract phone FIRST (various formats) - priority over bare digits
        if (preg_match('/(?:\+?38)?\s*\(?(\d{3})\)?\s*(\d{3})\s*[-.]?(\d{2})\s*[-.]?(\d{2})/u', $message, $m)) {
            // Normalize: +38XXXXXXXXXX
            $digits = $m[1] . $m[2] . $m[3] . $m[4];
            $criteria['phone'] = '+38' . $digits;
        }

        // Extract order_id (digits, optionally with "замовлення/заказ" prefix, fuzzy matching for typos)
        if (preg_match('/(?:зам[оа][влу][лв]?ен[нь]?[яє]|заказ|order|№|#)\s*(\d{1,10})/ui', $message, $m)) {
            $criteria['order_id'] = (int) $m[1];
        }

        // If still no order_id AND no phone but message has bare digits (5-10 digits), grab as order_id
        if (empty($criteria['order_id']) && empty($criteria['phone']) && preg_match('/\b(\d{3,6})\b/u', $message, $m)) {
            $criteria['order_id'] = (int) $m[1];
        }

        // Extract email
        if (preg_match('/[\w\.-]+@[\w\.-]+\.\w+/', $message, $m)) {
            $criteria['email'] = $m[0];
        }

        // Extract name (anything that looks like a name: Cyrillic, capitalized, 3+ chars)
        // Pattern: capitalized Cyrillic word (not part of other words)
        if (preg_match('/\b([А-ЯІЇЄҐ][а-яіїєґ]{2,})(?:\s+([А-ЯІЇЄҐ][а-яіїєґ]{2,}))?\b/u', $message, $m)) {
            $namePart = trim($m[1] . (isset($m[2]) ? ' ' . $m[2] : ''));
            // Exclude common keywords that aren't names
            $excludeKeywords = ['Замовлення', 'Статус', 'Інформація', 'Доставка', 'Оплата', 'Повернення'];
            $isExcluded = false;
            foreach ($excludeKeywords as $kw) {
                if (mb_stripos($namePart, $kw) !== false) {
                    $isExcluded = true;
                    break;
                }
            }
            if (!$isExcluded && empty($criteria['name'])) {
                $criteria['name'] = $namePart;
            }
        }

        Log::info('OrderSearchService: parsed query', ['message' => $message, 'criteria' => $criteria]);

        return $criteria;
    }

    /**
     * Fetch recent orders (last N by limit, all statuses).
     */
    private function fetchRecentOrders(int $limit = 50): array
    {
        try {
            $response = $this->client->request('orders/get', [
                'limit' => min(100, $limit),
                'offset' => 0,
                'additionalData' => false,
            ]);

            $orders = $response['orders'] ?? [];

            Log::info('OrderSearchService: fetched recent orders', ['count' => count($orders)]);

            return $orders;
        } catch (\Exception $e) {
            Log::error('OrderSearchService: error fetching orders', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Match order phone against search phone.
     */
    private function matchPhone(array $order, string $searchPhone): bool
    {
        $orderPhone = $order['delivery_phone'] ?? '';
        if (empty($orderPhone)) {
            return false;
        }

        // Normalize both for comparison
        $orderPhoneNorm = $this->normalizePhone($orderPhone);
        $searchPhoneNorm = $this->normalizePhone($searchPhone);

        Log::debug('OrderSearchService: matchPhone', [
            'order_id' => $order['id'] ?? 'N/A',
            'order_phone_raw' => $orderPhone,
            'order_phone_norm' => $orderPhoneNorm,
            'search_phone_norm' => $searchPhoneNorm,
            'match' => (str_contains($orderPhoneNorm, $searchPhoneNorm) || str_contains($searchPhoneNorm, $orderPhoneNorm)),
        ]);

        return str_contains($orderPhoneNorm, $searchPhoneNorm) ||
               str_contains($searchPhoneNorm, $orderPhoneNorm);
    }

    /**
     * Match order name against search name.
     */
    private function matchName(array $order, string $searchName): bool
    {
        $orderName = mb_strtolower($order['delivery_name'] ?? '');
        $searchNameLower = mb_strtolower($searchName);

        // Exact substring match or word match
        return str_contains($orderName, $searchNameLower);
    }

    /**
     * Match order email against search email.
     */
    private function matchEmail(array $order, string $searchEmail): bool
    {
        $orderEmail = mb_strtolower($order['delivery_email'] ?? '');
        $searchEmailLower = mb_strtolower($searchEmail);

        return $orderEmail === $searchEmailLower || str_contains($orderEmail, $searchEmailLower);
    }

    /**
     * Normalize phone to digits only.
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    /**
     * Detect which search type was used (for logging/debugging).
     */
    private function detectSearchType(array $criteria): string
    {
        if (!empty($criteria['order_id'])) {
            return 'order_id';
        }
        if (!empty($criteria['phone'])) {
            return 'phone';
        }
        if (!empty($criteria['name'])) {
            return 'name';
        }
        if (!empty($criteria['email'])) {
            return 'email';
        }
        return 'none';
    }
}
