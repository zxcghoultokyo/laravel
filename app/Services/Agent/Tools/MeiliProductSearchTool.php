<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use Illuminate\Support\Facades\Log;

class MeiliProductSearchTool
{
    public function __construct(private MeiliClient $meiliClient)
    {}

    /**
     * Search products in Meilisearch
     * Returns raw candidates with minimal fields for scoring
     */
    public function search(string $query, array $filters = [], int $limit = 40): array
    {
        try {
            $index = $this->meiliClient->client()->index('products');
            
            // Detect brand in query and enhance search
            $enhancedQuery = $this->enhanceQueryWithBrand($query);
            
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
     * Enhance query with brand detection
     * Recognizes brand names and adds them to search query multiple times for boosting
     */
    private function enhanceQueryWithBrand(string $query): string
    {
        $queryLower = mb_strtolower($query);
        
        // Remove "бренд" prefix
        $queryClean = preg_replace('/^бренд\s+/u', '', $queryLower);
        
        // Known brands (expand as needed)
        $brandMap = [
            'атака' => 'АТАКА',
            'ataka' => 'АТАКА',
            'а.т.а.к.а' => 'АТАКА',
            'абрамс' => 'Abrams',
            'abrams' => 'Abrams',
            'хоффман' => 'Hoffmann',
            'hoffman' => 'Hoffmann',
            'hoffmann' => 'Hoffmann',
            'елмон' => 'ELMON',
            'elmon' => 'ELMON',
            'ragnarok' => 'RAGNAROK',
            'рагнарок' => 'RAGNAROK',
            'condor' => 'Condor',
            'кондор' => 'Condor',
            '5.11' => '5.11',
            'саломан' => 'Salomon',
            'salomon' => 'Salomon',
            'kombat' => 'KOMBAT',
            'комбат' => 'KOMBAT',
            'карінтія' => 'Carinthia',
            'carinthia' => 'Carinthia',
        ];
        
        // Check if query is a brand name
        foreach ($brandMap as $search => $brand) {
            if ($queryClean === $search || str_contains($queryClean, ' ' . $search . ' ') || str_starts_with($queryClean, $search . ' ') || str_ends_with($queryClean, ' ' . $search)) {
                // Boost brand by repeating it in query (Meili ranks by term frequency)
                Log::info('MeiliProductSearchTool: brand detected', [
                    'original' => $query,
                    'brand' => $brand
                ]);
                return $brand . ' ' . $brand . ' ' . $brand . ' ' . $queryClean;
            }
        }
        
        return $query;
    }

    /**
     * Context-aware accessory filtering
     * If user searches for specific gear (straps, pouches), don't filter them out
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
        // Filter out accessories that are NOT the main product
        $filtered = [];
        $accessoryKeywords = [
            // Straps/webbing (ONLY filter if attached to main gear)
            'кріплен до', 'до плитоноски', 'до шолом', 'плечов ремінь', 'одноточков ремінь', 'двоточков ремінь',
            // Panels/covers/pouches (generic accessories)
            'панел', 'панель', 'чохол', 'кавер', 'кишеня',
            // Lights/electronics (accessories, not main)
            'ліхтар на', 'ліхтарик на', 'на шолом',
            // Adapters/mounts (always accessories)
            'адаптер', 'кронштейн', 'кріплення для', 'рейка', 'пряжка', 'затискач',
            // Modular add-ons
            'модуль для', 'комплект кріплень',
        ];
        
        foreach ($hits as $hit) {
            $titleLower = mb_strtolower($hit['title'] ?? '');
            $categoryLower = mb_strtolower($hit['category_path'] ?? '');
            $combined = $titleLower . ' ' . $categoryLower;
            
            $isAccessory = false;
            
            foreach ($accessoryKeywords as $keyword) {
                if (str_contains($combined, $keyword)) {
                    $isAccessory = true;
                    break;
                }
            }
            
            if (!$isAccessory) {
                $filtered[] = $hit;
            }
        }
        
        Log::info('MeiliProductSearchTool: filtered accessories', [
            'before' => count($hits),
            'after' => count($filtered)
        ]);
        
        return $filtered;
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
