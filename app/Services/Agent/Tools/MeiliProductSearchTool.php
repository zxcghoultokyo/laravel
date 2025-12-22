<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use App\Services\Search\BrandDetectionService;
use Illuminate\Support\Facades\Log;

class MeiliProductSearchTool
{
    public function __construct(
        private MeiliClient $meiliClient,
        private BrandDetectionService $brandDetection
    ) {}

    /**
     * Search products in Meilisearch
     * Returns raw candidates with minimal fields for scoring
     */
    public function search(string $query, array $filters = [], int $limit = 40): array
    {
        try {
            $index = $this->meiliClient->client()->index('products');
            
            // Detect brand in query and enhance search
            $brandInfo = $this->brandDetection->detectBrand($query);
            $enhancedQuery = $brandInfo['enhanced_query'];
            
            // Build filter string
            $filterParts = [];
            
            // Only include in-stock products
            $filterParts[] = 'in_stock = true';
            
            // Budget filters
            if (!empty($filters['budget_min'])) {
                $filterParts[] = "price >= {$filters['budget_min']}";
            }
            if (!empty($filters['budget_max'])) {
                $filterParts[] = "price <= {$filters['budget_max']}";
            }
            
            // Color filter
            if (!empty($filters['color'])) {
                $filterParts[] = "color = '{$filters['color']}'";
            }
            
            // Camo filter (would need to be in products table)
            // if (!empty($filters['camo'])) {
            //     $filterParts[] = "camo = '{$filters['camo']}'";
            // }
            
            $filterString = implode(' AND ', $filterParts);
            
            $searchParams = [
                'limit' => $limit,
                'attributesToRetrieve' => [
                    'id',
                    'article',
                    'parent_article',
                    'title',
                    'price',
                    'category_path',
                    'in_stock',
                    'popularity',
                    'ai_product_type',
                    'display_in_showcase',
                    'brand',
                ],
            ];
            
            if ($filterString) {
                $searchParams['filter'] = $filterString;
            }
            
            Log::info('MeiliProductSearchTool: searching', [
                'original_query' => $query,
                'is_brand_search' => $brandInfo['is_brand'],
                'detected_brand' => $brandInfo['brand'],
                'enhanced_query' => $enhancedQuery,
                'filter' => $filterString,
                'limit' => $limit
            ]);
            
            $result = $index->search($enhancedQuery, $searchParams);
            $hits = $result->getHits();
            
            Log::info('MeiliProductSearchTool: found', ['count' => count($hits)]);
            
            // Ensure ai_product_type is set
            foreach ($hits as &$hit) {
                if (empty($hit['ai_product_type'])) {
                    $hit['ai_product_type'] = '__unknown__';
                }
            }
            
            // Apply contextual accessory filtering
            $filtered = $this->filterAccessories($hits, $query);
            
            // If we filtered out too many (likely false positives), return originals
            if (count($filtered) === 0) {
                return $hits;
            }
            
            return $filtered;
            
        } catch (\Exception $e) {
            Log::error('MeiliProductSearchTool: error, falling back to Eloquent', [
                'error' => $e->getMessage(),
                'query' => $query
            ]);
            
            // Fallback to Eloquent search
            return $this->eloquentFallback($query, $filters, $limit);
        }
    }

    /**
     * Context-aware accessory filtering
     * If user searches for specific gear (straps, pouches), don't filter them out
     * Otherwise, REMOVE accessories if there are 3+ main products
     */
    private function filterAccessories(array $hits, string $query): array
    {
        $queryLower = mb_strtolower($query);
        
        // Detect if user is searching FOR accessories/specific gear
        $accessorySearchTerms = [
            'ремінь', 'ремен', 'слінг', 'sling', 'збройовий',
            'пояс', 'belt', 'рпс',
            'підсумок', 'pouch', 'підсумки',
            'кишеня', 'pocket',
            'панел', 'panel', 'камбербанд',
            'аксесуар', 'accessory', 'комплектуючі',
            'ліхтар', 'flashlight',
            'адаптер', 'adapter', 'кріплення', 'mount',
            'чохол', 'cover', 'кавер',
            'сумка', 'bag', 'напашник',
            'модуль', 'module',
        ];
        
        foreach ($accessorySearchTerms as $term) {
            if (str_contains($queryLower, $term)) {
                // User is searching FOR this type of gear, don't filter
                Log::info('MeiliProductSearchTool: user searching for accessories, skipping filter', [
                    'query' => $query,
                    'term' => $term
                ]);
                return $hits;
            }
        }
        
        // User is searching for main gear (plate carriers, helmets, etc.)
        // Categorize products into main/accessory
        $mainProducts = [];
        $accessories = [];
        
        $accessoryIndicators = [
            // Carrier accessories
            'чохл', 'чохол', 'cover', 'кавер',
            'сумка', 'сумк', 'bag', 'напашник',
            'модуль', 'module',
            'кишен', 'pocket',
            'панел', 'панель', 'panel',
            'камбербанд', 'cummerbund',
            'комплект чохл', 'комплект захист',
            'плечов захист', 'плечевой защит',
            'плечового захисту', 'shoulder',
            // Mounts/adapters
            'кріплен', 'mount', 'adapter', 'адаптер',
            'кронштейн', 'рейка', 'пряжка',
            'harness', 'затискач',
            // Lights/electronics
            'ліхтар', 'flashlight',
            // Pouches (when not main search)
            'підсумок', 'pouch',
            // Other accessories
            'ремінь', 'ремен', 'strap', 'sling',
        ];
        
        foreach ($hits as $hit) {
            $titleLower = mb_strtolower($hit['title'] ?? '');
            $categoryLower = mb_strtolower($hit['category_path'] ?? '');
            $combined = $titleLower . ' ' . $categoryLower;
            
            $isAccessory = false;
            
            foreach ($accessoryIndicators as $indicator) {
                if (str_contains($combined, $indicator)) {
                    // Additional check: if it has main product words, might be main product with accessory in description
                    $hasMainWords = str_contains($combined, 'плитоноск') || 
                                   str_contains($combined, 'жилет') ||
                                   str_contains($combined, 'шолом') ||
                                   str_contains($combined, 'броня');
                    
                    // If title starts with accessory word AND has main word → it's an accessory
                    // e.g. "Чохол для плитоноски", "Сумка напашник", "Модуль для жилету"
                    if (str_starts_with($titleLower, explode(' ', $indicator)[0]) || 
                        !$hasMainWords ||
                        str_contains($titleLower, 'для ') ||
                        str_contains($titleLower, ' для') ||
                        str_contains($titleLower, 'під ') ||
                        str_contains($titleLower, 'до ')) {
                        $isAccessory = true;
                        break;
                    }
                }
            }
            
            if ($isAccessory) {
                $accessories[] = $hit;
            } else {
                $mainProducts[] = $hit;
            }
        }
        
        Log::info('MeiliProductSearchTool: categorized products', [
            'main' => count($mainProducts),
            'accessories' => count($accessories),
            'query' => $query
        ]);
        
        // If we have 3+ main products, EXCLUDE all accessories
        if (count($mainProducts) >= 3) {
            Log::info('MeiliProductSearchTool: removing accessories, enough main products', [
                'main_count' => count($mainProducts),
                'removed_accessories' => count($accessories)
            ]);
            return $mainProducts;
        }
        
        // Otherwise return all (might need accessories to fill results)
        return $hits;
    }

    /**
     * Fallback to Eloquent when Meilisearch is unavailable
     */
    private function eloquentFallback(string $query, array $filters, int $limit): array
    {
        $builder = Product::query()->where('in_stock', true);
        
        // Text search in title
        if (!empty($query)) {
            $builder->where(function($q) use ($query) {
                $q->whereRaw('LOWER(title) LIKE ?', ['%' . mb_strtolower($query) . '%'])
                  ->orWhereRaw('LOWER(search_index) LIKE ?', ['%' . mb_strtolower($query) . '%']);
            });
        }
        
        // Budget filters
        if (!empty($filters['budget_min'])) {
            $builder->where('price', '>=', $filters['budget_min']);
        }
        if (!empty($filters['budget_max'])) {
            $builder->where('price', '<=', $filters['budget_max']);
        }
        
        // Order by popularity
        $builder->orderBy('popularity', 'desc');
        
        $products = $builder->limit($limit)->get();
        
        Log::info('MeiliProductSearchTool: Eloquent fallback found', ['count' => $products->count()]);
        
        // Map to same format as Meilisearch
        return $products->map(function($product) {
            return [
                'id' => $product->id,
                'article' => $product->article,
                'parent_article' => $product->parent_article,
                'title' => $product->title,
                'price' => $product->price,
                'category_path' => $product->category_path,
                'in_stock' => $product->in_stock,
                'popularity' => $product->popularity ?? 0,
                'ai_product_type' => $product->ai_product_type ?? '__unknown__',
                'display_in_showcase' => $product->display_in_showcase ?? false,
            ];
        })->toArray();
    }
}
