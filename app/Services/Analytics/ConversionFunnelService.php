<?php

namespace App\Services\Analytics;

use App\Models\ChatEvent;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Conversion Funnel Service - track and analyze sales funnel.
 * 
 * Funnel stages:
 * 1. widget_opened - User opened chat widget
 * 2. chat_started - User sent first message
 * 3. product_viewed - AI showed products
 * 4. product_clicked - User clicked on product
 * 5. add_to_cart - User added to cart (from merchant callback)
 * 6. purchase - User completed purchase (from merchant callback)
 */
class ConversionFunnelService
{
    /**
     * Funnel stages in order.
     */
    public const STAGES = [
        'widget_opened',
        'chat_started',
        'product_viewed',
        'product_clicked',
        'add_to_cart',
        'purchase',
    ];

    /**
     * Get funnel data for dashboard.
     */
    public function getFunnelData(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30)->toDateString();
        $dateTo = $dateTo ?? now()->toDateString();

        $cacheKey = "funnel_{$tenantId}_{$dateFrom}_{$dateTo}";
        
        return Cache::remember($cacheKey, 300, function () use ($tenantId, $dateFrom, $dateTo) {
            $stages = $this->getStagesCounts($tenantId, $dateFrom, $dateTo);
            $conversionRates = $this->calculateConversionRates($stages);
            $trends = $this->getDailyTrends($tenantId, $dateFrom, $dateTo);
            
            return [
                'stages' => $stages,
                'conversion_rates' => $conversionRates,
                'trends' => $trends,
                'summary' => $this->getSummary($stages, $conversionRates),
            ];
        });
    }

    /**
     * Get counts for each funnel stage.
     */
    public function getStagesCounts(
        int $tenantId,
        string $dateFrom,
        string $dateTo
    ): array {
        $query = ChatEvent::where('tenant_id', $tenantId)
            ->whereIn('event_type', self::STAGES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->select('event_type', DB::raw('COUNT(*) as count'))
            ->groupBy('event_type');

        $results = $query->get()->pluck('count', 'event_type');

        $stages = [];
        foreach (self::STAGES as $stage) {
            $stages[$stage] = [
                'name' => $this->getStageName($stage),
                'count' => $results[$stage] ?? 0,
            ];
        }

        return $stages;
    }

    /**
     * Calculate conversion rates between stages.
     */
    public function calculateConversionRates(array $stages): array
    {
        $rates = [];
        $previousCount = null;

        foreach (self::STAGES as $index => $stage) {
            $currentCount = $stages[$stage]['count'] ?? 0;
            
            if ($index === 0) {
                $rates[$stage] = [
                    'rate' => 100,
                    'label' => '100%',
                ];
            } elseif ($previousCount > 0) {
                $rate = round(($currentCount / $previousCount) * 100, 1);
                $rates[$stage] = [
                    'rate' => $rate,
                    'label' => "{$rate}%",
                    'drop' => round(100 - $rate, 1),
                ];
            } else {
                $rates[$stage] = [
                    'rate' => 0,
                    'label' => '0%',
                    'drop' => 100,
                ];
            }

            $previousCount = $currentCount;
        }

        return $rates;
    }

    /**
     * Get daily trends for each stage.
     */
    public function getDailyTrends(
        int $tenantId,
        string $dateFrom,
        string $dateTo
    ): array {
        $query = ChatEvent::where('tenant_id', $tenantId)
            ->whereIn('event_type', self::STAGES)
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->select(
                DB::raw('DATE(created_at) as date'),
                'event_type',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy(DB::raw('DATE(created_at)'), 'event_type')
            ->orderBy('date');

        $results = $query->get();

        // Group by date
        $trends = [];
        foreach ($results as $row) {
            if (!isset($trends[$row->date])) {
                $trends[$row->date] = ['date' => $row->date];
            }
            $trends[$row->date][$row->event_type] = $row->count;
        }

        // Fill missing values with 0
        foreach ($trends as &$day) {
            foreach (self::STAGES as $stage) {
                if (!isset($day[$stage])) {
                    $day[$stage] = 0;
                }
            }
        }

        return array_values($trends);
    }

    /**
     * Get summary metrics.
     */
    private function getSummary(array $stages, array $rates): array
    {
        $widgetOpened = $stages['widget_opened']['count'] ?? 0;
        $purchases = $stages['purchase']['count'] ?? 0;
        $addToCart = $stages['add_to_cart']['count'] ?? 0;
        $productClicked = $stages['product_clicked']['count'] ?? 0;

        return [
            'overall_conversion' => $widgetOpened > 0 
                ? round(($purchases / $widgetOpened) * 100, 2) 
                : 0,
            'cart_conversion' => $addToCart > 0 
                ? round(($purchases / $addToCart) * 100, 1) 
                : 0,
            'engagement_rate' => $widgetOpened > 0 
                ? round(($productClicked / $widgetOpened) * 100, 1) 
                : 0,
            'biggest_drop' => $this->findBiggestDrop($rates),
        ];
    }

    /**
     * Find stage with biggest conversion drop.
     */
    private function findBiggestDrop(array $rates): ?array
    {
        $maxDrop = 0;
        $dropStage = null;

        foreach ($rates as $stage => $data) {
            if (isset($data['drop']) && $data['drop'] > $maxDrop) {
                $maxDrop = $data['drop'];
                $dropStage = $stage;
            }
        }

        if ($dropStage) {
            return [
                'stage' => $dropStage,
                'name' => $this->getStageName($dropStage),
                'drop' => $maxDrop,
                'recommendation' => $this->getRecommendation($dropStage, $maxDrop),
            ];
        }

        return null;
    }

    /**
     * Get stage human-readable name.
     */
    private function getStageName(string $stage): string
    {
        return match($stage) {
            'widget_opened' => 'Відкрили віджет',
            'chat_started' => 'Почали чат',
            'product_viewed' => 'Побачили товари',
            'product_clicked' => 'Клік по товару',
            'add_to_cart' => 'Додали в кошик',
            'purchase' => 'Покупка',
            default => $stage,
        };
    }

    /**
     * Get recommendation for improving conversion at stage.
     */
    private function getRecommendation(string $stage, float $drop): string
    {
        if ($drop < 30) {
            return 'Конверсія в нормі';
        }

        return match($stage) {
            'chat_started' => 'Додайте привітальне повідомлення або промо-банер у віджеті',
            'product_viewed' => 'Покращіть розуміння запитів або додайте більше товарів',
            'product_clicked' => 'Перегляньте якість карток товарів та їх релевантність',
            'add_to_cart' => 'Перевірте ціни та наявність товарів',
            'purchase' => 'Спростіть процес оформлення замовлення',
            default => 'Проаналізуйте цей етап детальніше',
        };
    }

    /**
     * Track funnel event.
     */
    public function trackEvent(
        int $tenantId,
        string $sessionId,
        string $eventType,
        array $data = []
    ): void {
        if (!in_array($eventType, self::STAGES)) {
            return;
        }

        ChatEvent::create([
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'event_data' => $data,
        ]);

        // Invalidate cache for tenant
        $this->invalidateCache($tenantId);
    }

    /**
     * Track purchase from external callback.
     */
    public function trackPurchase(
        int $tenantId,
        string $sessionId,
        float $orderAmount,
        string $orderId,
        array $products = []
    ): void {
        $this->trackEvent($tenantId, $sessionId, 'purchase', [
            'order_id' => $orderId,
            'amount' => $orderAmount,
            'products' => $products,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Get attribution data for a session.
     */
    public function getSessionAttribution(int $tenantId, string $sessionId): array
    {
        $events = ChatEvent::where('tenant_id', $tenantId)
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get();

        $attribution = [
            'session_id' => $sessionId,
            'first_event' => null,
            'last_event' => null,
            'events_count' => $events->count(),
            'converted' => false,
            'journey' => [],
        ];

        foreach ($events as $event) {
            $attribution['journey'][] = [
                'stage' => $event->event_type,
                'name' => $this->getStageName($event->event_type),
                'timestamp' => $event->created_at->toISOString(),
                'data' => $event->event_data,
            ];

            if ($event->event_type === 'purchase') {
                $attribution['converted'] = true;
            }
        }

        if ($events->isNotEmpty()) {
            $attribution['first_event'] = $events->first()->created_at->toISOString();
            $attribution['last_event'] = $events->last()->created_at->toISOString();
        }

        return $attribution;
    }

    /**
     * Get top converting products.
     */
    public function getTopConvertingProducts(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 10
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30)->toDateString();
        $dateTo = $dateTo ?? now()->toDateString();

        // Get products that led to purchases
        $purchaseSessions = ChatEvent::where('tenant_id', $tenantId)
            ->where('event_type', 'purchase')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->pluck('session_id');

        if ($purchaseSessions->isEmpty()) {
            return [];
        }

        // Get products clicked in those sessions
        $productClicks = ChatEvent::where('tenant_id', $tenantId)
            ->where('event_type', 'product_clicked')
            ->whereIn('session_id', $purchaseSessions)
            ->get();

        // Count conversions per product
        $productStats = [];
        foreach ($productClicks as $click) {
            $productId = $click->event_data['product_id'] ?? null;
            if (!$productId) continue;

            if (!isset($productStats[$productId])) {
                $productStats[$productId] = [
                    'product_id' => $productId,
                    'product_title' => $click->event_data['product_title'] ?? '',
                    'conversions' => 0,
                ];
            }
            $productStats[$productId]['conversions']++;
        }

        // Sort by conversions and return top N
        usort($productStats, fn($a, $b) => $b['conversions'] <=> $a['conversions']);

        return array_slice($productStats, 0, $limit);
    }

    /**
     * Invalidate funnel cache for tenant.
     */
    private function invalidateCache(int $tenantId): void
    {
        // Clear recent cache entries
        $dates = [
            now()->subDays(7)->toDateString(),
            now()->subDays(30)->toDateString(),
        ];

        foreach ($dates as $dateFrom) {
            $cacheKey = "funnel_{$tenantId}_{$dateFrom}_" . now()->toDateString();
            Cache::forget($cacheKey);
        }
    }
}
