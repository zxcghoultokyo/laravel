<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A/B Testing Service for Search Quality Comparison
 * 
 * Assigns users to experiment variants and tracks metrics.
 * 
 * Variants:
 * - control (A): Keyword search only (Meilisearch)
 * - treatment (B): Keyword + AI features (slang, semantic fallback)
 */
class ABTestingService
{
    // Experiment configurations
    protected array $experiments = [
        'search_ai_features' => [
            'name' => 'AI Search Features',
            'description' => 'Compare keyword-only vs AI-enhanced search',
            'variants' => [
                'control' => [
                    'name' => 'Keyword Only',
                    'weight' => 50, // 50% of traffic
                    'features' => [
                        'semantic_search' => false,
                        'slang_expansion' => false,
                        'ai_reranking' => false,
                    ],
                ],
                'treatment' => [
                    'name' => 'AI Enhanced',
                    'weight' => 50, // 50% of traffic
                    'features' => [
                        'semantic_search' => true,
                        'slang_expansion' => true,
                        'ai_reranking' => true,
                    ],
                ],
            ],
            'metrics' => [
                'zero_results_rate',
                'click_through_rate',
                'add_to_cart_rate',
                'query_refinement_rate',
                'avg_results_count',
            ],
            'enabled' => true,
            'start_date' => '2025-01-14',
            'end_date' => null, // ongoing
        ],
    ];

    protected const CACHE_PREFIX = 'ab_test_';
    protected const VARIANT_TTL = 60 * 60 * 24 * 30; // 30 days - consistent experience

    /**
     * Get variant for a session.
     * Assigns randomly if not yet assigned, then caches.
     */
    public function getVariant(string $sessionId, string $experiment = 'search_ai_features'): string
    {
        $config = $this->experiments[$experiment] ?? null;
        
        if (!$config || !$config['enabled']) {
            return 'treatment'; // Default to full features if experiment disabled
        }

        $cacheKey = self::CACHE_PREFIX . $experiment . '_' . $sessionId;
        
        return Cache::remember($cacheKey, self::VARIANT_TTL, function () use ($config) {
            return $this->assignVariant($config['variants']);
        });
    }

    /**
     * Randomly assign variant based on weights.
     */
    protected function assignVariant(array $variants): string
    {
        $totalWeight = array_sum(array_column($variants, 'weight'));
        $random = mt_rand(1, $totalWeight);
        
        $cumulative = 0;
        foreach ($variants as $key => $variant) {
            $cumulative += $variant['weight'];
            if ($random <= $cumulative) {
                return $key;
            }
        }
        
        return array_key_first($variants);
    }

    /**
     * Get feature flags for a variant.
     */
    public function getFeatures(string $sessionId, string $experiment = 'search_ai_features'): array
    {
        $variant = $this->getVariant($sessionId, $experiment);
        $config = $this->experiments[$experiment] ?? null;
        
        if (!$config) {
            return [
                'semantic_search' => true,
                'slang_expansion' => true,
                'ai_reranking' => true,
            ];
        }
        
        return $config['variants'][$variant]['features'] ?? [];
    }

    /**
     * Check if a specific feature is enabled for session.
     */
    public function isFeatureEnabled(string $sessionId, string $feature, string $experiment = 'search_ai_features'): bool
    {
        $features = $this->getFeatures($sessionId, $experiment);
        return $features[$feature] ?? true;
    }

    /**
     * Track a metric event.
     */
    public function trackEvent(
        string $sessionId,
        string $event,
        array $data = [],
        string $experiment = 'search_ai_features'
    ): void {
        $variant = $this->getVariant($sessionId, $experiment);
        
        try {
            DB::table('ab_test_events')->insert([
                'experiment' => $experiment,
                'variant' => $variant,
                'session_id' => $sessionId,
                'event' => $event,
                'data' => json_encode($data),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Table might not exist, log and continue
            Log::warning('ABTestingService: Failed to track event', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
            
            // Fallback to cache-based tracking
            $this->trackEventToCache($experiment, $variant, $event, $data);
        }
    }

    /**
     * Fallback: track events to cache when DB not available.
     */
    protected function trackEventToCache(string $experiment, string $variant, string $event, array $data): void
    {
        $key = self::CACHE_PREFIX . 'events_' . $experiment . '_' . $variant . '_' . $event;
        $count = Cache::get($key, 0);
        Cache::put($key, $count + 1, now()->addDays(30));
        
        // Track totals
        $totalKey = self::CACHE_PREFIX . 'total_' . $experiment . '_' . $variant;
        $total = Cache::get($totalKey, 0);
        Cache::put($totalKey, $total + 1, now()->addDays(30));
    }

    /**
     * Track search performed event.
     */
    public function trackSearch(
        string $sessionId,
        string $query,
        int $resultsCount,
        bool $usedSemantic = false,
        bool $usedSlang = false
    ): void {
        $this->trackEvent($sessionId, 'search_performed', [
            'query' => $query,
            'results_count' => $resultsCount,
            'zero_results' => $resultsCount === 0,
            'used_semantic' => $usedSemantic,
            'used_slang' => $usedSlang,
        ]);
    }

    /**
     * Track product click event.
     */
    public function trackProductClick(string $sessionId, int $productId, int $position): void
    {
        $this->trackEvent($sessionId, 'product_click', [
            'product_id' => $productId,
            'position' => $position,
        ]);
    }

    /**
     * Track add to cart event.
     */
    public function trackAddToCart(string $sessionId, int $productId): void
    {
        $this->trackEvent($sessionId, 'add_to_cart', [
            'product_id' => $productId,
        ]);
    }

    /**
     * Get experiment statistics.
     */
    public function getStats(string $experiment = 'search_ai_features'): array
    {
        $config = $this->experiments[$experiment] ?? null;
        
        if (!$config) {
            return ['error' => 'Experiment not found'];
        }

        $stats = [
            'experiment' => $experiment,
            'name' => $config['name'],
            'enabled' => $config['enabled'],
            'variants' => [],
        ];

        foreach (array_keys($config['variants']) as $variant) {
            $stats['variants'][$variant] = $this->getVariantStats($experiment, $variant);
        }

        // Calculate comparison
        $stats['comparison'] = $this->calculateComparison($stats['variants']);

        return $stats;
    }

    /**
     * Get statistics for a specific variant.
     */
    protected function getVariantStats(string $experiment, string $variant): array
    {
        // Try DB first
        try {
            $events = DB::table('ab_test_events')
                ->where('experiment', $experiment)
                ->where('variant', $variant)
                ->select('event', DB::raw('COUNT(*) as count'))
                ->groupBy('event')
                ->pluck('count', 'event')
                ->toArray();

            $searchEvents = DB::table('ab_test_events')
                ->where('experiment', $experiment)
                ->where('variant', $variant)
                ->where('event', 'search_performed')
                ->get();

            $totalSearches = $searchEvents->count();
            $zeroResults = $searchEvents->filter(function ($e) {
                $data = json_decode($e->data, true);
                return ($data['zero_results'] ?? false) === true;
            })->count();

            $avgResults = $searchEvents->avg(function ($e) {
                $data = json_decode($e->data, true);
                return $data['results_count'] ?? 0;
            });

            return [
                'total_searches' => $totalSearches,
                'zero_results' => $zeroResults,
                'zero_results_rate' => $totalSearches > 0 
                    ? round(($zeroResults / $totalSearches) * 100, 2) 
                    : 0,
                'product_clicks' => $events['product_click'] ?? 0,
                'click_through_rate' => $totalSearches > 0 
                    ? round((($events['product_click'] ?? 0) / $totalSearches) * 100, 2) 
                    : 0,
                'add_to_carts' => $events['add_to_cart'] ?? 0,
                'add_to_cart_rate' => ($events['product_click'] ?? 0) > 0 
                    ? round((($events['add_to_cart'] ?? 0) / $events['product_click']) * 100, 2) 
                    : 0,
                'avg_results_count' => round($avgResults ?? 0, 1),
                'unique_sessions' => DB::table('ab_test_events')
                    ->where('experiment', $experiment)
                    ->where('variant', $variant)
                    ->distinct('session_id')
                    ->count('session_id'),
            ];
        } catch (\Exception $e) {
            // Fallback to cache
            return $this->getVariantStatsFromCache($experiment, $variant);
        }
    }

    /**
     * Fallback: get stats from cache.
     */
    protected function getVariantStatsFromCache(string $experiment, string $variant): array
    {
        $searches = Cache::get(self::CACHE_PREFIX . 'events_' . $experiment . '_' . $variant . '_search_performed', 0);
        $clicks = Cache::get(self::CACHE_PREFIX . 'events_' . $experiment . '_' . $variant . '_product_click', 0);
        $carts = Cache::get(self::CACHE_PREFIX . 'events_' . $experiment . '_' . $variant . '_add_to_cart', 0);
        
        return [
            'total_searches' => $searches,
            'zero_results' => 0, // Not trackable in cache mode
            'zero_results_rate' => 0,
            'product_clicks' => $clicks,
            'click_through_rate' => $searches > 0 ? round(($clicks / $searches) * 100, 2) : 0,
            'add_to_carts' => $carts,
            'add_to_cart_rate' => $clicks > 0 ? round(($carts / $clicks) * 100, 2) : 0,
            'avg_results_count' => 0,
            'unique_sessions' => 0,
            'source' => 'cache', // Indicate data source
        ];
    }

    /**
     * Calculate comparison between variants.
     */
    protected function calculateComparison(array $variants): array
    {
        $control = $variants['control'] ?? [];
        $treatment = $variants['treatment'] ?? [];

        if (empty($control) || empty($treatment)) {
            return [];
        }

        $comparison = [];

        // Zero results rate (lower is better)
        if ($control['zero_results_rate'] > 0) {
            $comparison['zero_results_improvement'] = round(
                (($control['zero_results_rate'] - $treatment['zero_results_rate']) / $control['zero_results_rate']) * 100,
                1
            );
        }

        // CTR (higher is better)
        if ($control['click_through_rate'] > 0) {
            $comparison['ctr_improvement'] = round(
                (($treatment['click_through_rate'] - $control['click_through_rate']) / $control['click_through_rate']) * 100,
                1
            );
        }

        // Add to cart rate (higher is better)
        if ($control['add_to_cart_rate'] > 0) {
            $comparison['cart_rate_improvement'] = round(
                (($treatment['add_to_cart_rate'] - $control['add_to_cart_rate']) / $control['add_to_cart_rate']) * 100,
                1
            );
        }

        // Avg results (higher is better for semantic)
        if ($control['avg_results_count'] > 0) {
            $comparison['results_improvement'] = round(
                (($treatment['avg_results_count'] - $control['avg_results_count']) / $control['avg_results_count']) * 100,
                1
            );
        }

        // Winner determination
        $treatmentWins = 0;
        $controlWins = 0;

        if (($comparison['zero_results_improvement'] ?? 0) > 5) $treatmentWins++;
        elseif (($comparison['zero_results_improvement'] ?? 0) < -5) $controlWins++;

        if (($comparison['ctr_improvement'] ?? 0) > 5) $treatmentWins++;
        elseif (($comparison['ctr_improvement'] ?? 0) < -5) $controlWins++;

        if (($comparison['cart_rate_improvement'] ?? 0) > 5) $treatmentWins++;
        elseif (($comparison['cart_rate_improvement'] ?? 0) < -5) $controlWins++;

        $comparison['winner'] = $treatmentWins > $controlWins 
            ? 'treatment' 
            : ($controlWins > $treatmentWins ? 'control' : 'tie');
        
        $comparison['confidence'] = $this->calculateStatisticalSignificance($control, $treatment);

        return $comparison;
    }

    /**
     * Simple statistical significance calculation.
     */
    protected function calculateStatisticalSignificance(array $control, array $treatment): string
    {
        $totalSamples = ($control['total_searches'] ?? 0) + ($treatment['total_searches'] ?? 0);
        
        if ($totalSamples < 100) {
            return 'insufficient_data';
        } elseif ($totalSamples < 500) {
            return 'low';
        } elseif ($totalSamples < 1000) {
            return 'medium';
        } else {
            return 'high';
        }
    }

    /**
     * Get all experiments.
     */
    public function getExperiments(): array
    {
        return $this->experiments;
    }

    /**
     * Enable/disable experiment.
     */
    public function setExperimentEnabled(string $experiment, bool $enabled): void
    {
        if (isset($this->experiments[$experiment])) {
            $this->experiments[$experiment]['enabled'] = $enabled;
            Cache::put(self::CACHE_PREFIX . 'config_' . $experiment . '_enabled', $enabled, now()->addDays(30));
        }
    }

    /**
     * Force variant for testing.
     */
    public function forceVariant(string $sessionId, string $variant, string $experiment = 'search_ai_features'): void
    {
        $cacheKey = self::CACHE_PREFIX . $experiment . '_' . $sessionId;
        Cache::put($cacheKey, $variant, self::VARIANT_TTL);
    }

    /**
     * Reset experiment data (for testing).
     */
    public function resetExperiment(string $experiment): void
    {
        try {
            DB::table('ab_test_events')
                ->where('experiment', $experiment)
                ->delete();
        } catch (\Exception $e) {
            // Table might not exist
        }

        // Clear cache counters
        foreach (['control', 'treatment'] as $variant) {
            foreach (['search_performed', 'product_click', 'add_to_cart'] as $event) {
                Cache::forget(self::CACHE_PREFIX . 'events_' . $experiment . '_' . $variant . '_' . $event);
            }
            Cache::forget(self::CACHE_PREFIX . 'total_' . $experiment . '_' . $variant);
        }
    }
}
