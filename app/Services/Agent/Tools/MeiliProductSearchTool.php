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
                ],
            ];
            
            if ($filterString) {
                $searchParams['filter'] = $filterString;
            }
            
            Log::info('MeiliProductSearchTool: searching', [
                'query' => $query,
                'filter' => $filterString,
                'limit' => $limit
            ]);
            
            $result = $index->search($query, $searchParams);
            $hits = $result->getHits();
            
            Log::info('MeiliProductSearchTool: found', ['count' => count($hits)]);
            
            // Ensure ai_product_type is set and filter out obvious accessories
            $filtered = [];
            $accessoryKeywords = ['ремін', 'ремен', 'strap', 'sling', 'плечов', 'одноточков', 'двоточков', 'кріплен', 'harness', 'панел', 'cummerbund', 'камбербанд', 'shoulder', 'панель', 'ліхтар', 'ліхтарик', 'навушник', 'гарнітур', 'кавер', 'чохол', 'адаптер', 'кронштейн', 'кріплення'];
            
            foreach ($hits as &$hit) {
                if (empty($hit['ai_product_type'])) {
                    $hit['ai_product_type'] = '__unknown__';
                }
                
                // Skip obvious accessories on keyword match
                $titleLower = mb_strtolower($hit['title'] ?? '');
                $isAccessory = false;
                
                foreach ($accessoryKeywords as $keyword) {
                    if (str_contains($titleLower, $keyword)) {
                        $isAccessory = true;
                        break;
                    }
                }
                
                if (!$isAccessory) {
                    $filtered[] = $hit;
                }
            }
            
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
