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
            $enhancedQuery = $this->expandQuerySynonyms($brandInfo['enhanced_query'] ?? $query);
            
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
            
            // Color filter: prefer normalized field, fallback to legacy `color` values (different locales/cases)
            if (!empty($filters['color'])) {
                $canonical = strtolower((string) $filters['color']);
                // normalized field first
                $clauses = ["color_norm = '{$canonical}'"];
                // include common literal variants for legacy `color` values
                try {
                    $variants = \App\Support\ColorNormalizer::literalVariants($canonical);
                } catch (\Throwable $e) {
                    $variants = [$canonical];
                }
                foreach ($variants as $v) {
                    $vv = str_replace("'", "\\'", (string) $v);
                    $clauses[] = "color = '{$vv}'";
                }
                $filterParts[] = '(' . implode(' OR ', $clauses) . ')';
            }
            
            // Camo filter (would need to be in products table)
            // if (!empty($filters['camo'])) {
            //     $filterParts[] = "camo = '{$filters['camo']}'";
            // }
            
            // If explicit brand search — strictly filter by brand in Meili
            if (!empty($brandInfo['is_brand']) && !empty($brandInfo['brand'])) {
                $brandValue = str_replace("'", "\\'", (string) $brandInfo['brand']);
                $filterParts[] = "brand = '{$brandValue}'";
            }

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
            
            // Retry without color filter if no hits (color fields may not match)
            if (count($hits) === 0 && !empty($filters['color'])) {
                Log::info('MeiliProductSearchTool: zero hits with color filter, retrying without color', [
                    'filter' => $filterString,
                ]);
                $filterPartsNoColor = array_filter($filterParts, fn($f) => !str_contains($f, 'color =') && !str_contains($f, 'color_norm ='));
                $filterStringNoColor = implode(' AND ', $filterPartsNoColor);
                if ($filterStringNoColor) {
                    $searchParams['filter'] = $filterStringNoColor;
                } else {
                    unset($searchParams['filter']);
                }
                $result = $index->search($enhancedQuery, $searchParams);
                $hits = $result->getHits();
                Log::info('MeiliProductSearchTool: retry without color returned', ['count' => count($hits), 'filter' => $filterStringNoColor]);
            }
            
            Log::info('MeiliProductSearchTool: found', ['count' => count($hits)]);
            
            // Ensure ai_product_type is set
            foreach ($hits as &$hit) {
                if (empty($hit['ai_product_type'])) {
                    $hit['ai_product_type'] = '__unknown__';
                }
            }
            
            // Apply contextual accessory filtering
            $filtered = $this->filterAccessories($hits, $query);

            // If footwear with explicit size → reorder by size closeness
            $queryLower = mb_strtolower($query);
            $primaryType = $this->detectPrimaryType($queryLower);
            if ($primaryType === 'footwear') {
                $targetSize = $this->parseFootwearSizeFromQuery($queryLower);
                if ($targetSize) {
                    $filtered = $this->reorderByFootwearSize($filtered, $targetSize);
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
     * Context-aware accessory filtering
     * If user searches for specific gear (straps, pouches), don't filter them out
     * Otherwise, REMOVE accessories if there are 3+ main products
     */
    private function filterAccessories(array $hits, string $query): array
    {
        $queryLower = mb_strtolower($query);
        $primaryType = $this->detectPrimaryType($queryLower);
        
        // Detect if user is SPECIFICALLY searching for accessories (not just mentioning them)
        // Must be primary search term, not just context word
        $accessorySearchPatterns = [
            // Primary accessory search (accessory word at start or standalone)
            '/^ремінь/', '/^ремен/', '/^слінг/', '/^sling/',
            '/^пояс/', '/^belt/',
            '/^підсумок/', '/^підсумки/', '/^pouch/',
            '/^кишен/', '/^кишені/', '/^pocket/',
            '/^панел/', '/^panel/', '/^камбербанд/',
            '/^ліхтар/', '/^flashlight/',
            '/^адаптер/', '/^adapter/', '/^кріплення/', '/^mount/',
            '/^чохол/', '/^cover/', '/^кавер/',
            '/^сумка/', '/^bag/', '/^напашник/',
            '/^модуль/', '/^module/',
            // Patterns with "для" (searching FOR accessory)
            '/ремінь для/', '/ремен для/', '/sling for/',
            '/панел для/', '/panel for/',
            '/кріплення для/', '/mount for/', '/кріплення на/', '/mount on/',
            '/чохол для/', '/cover for/',
            '/сумка для/', '/bag for/',
            '/адаптер для/', '/adapter for/',
        ];
        
        foreach ($accessorySearchPatterns as $pattern) {
            if (preg_match($pattern . 'u', $queryLower)) {
                // User is searching FOR this type of gear, don't filter
                Log::info('MeiliProductSearchTool: user searching for accessories, skipping filter', [
                    'query' => $query,
                    'pattern' => $pattern
                ]);
                return $hits;
            }
        }
        
        // User is searching for main gear (plate carriers, helmets, plates, etc.)
        // Categorize products into main/accessory with primary type awareness
        $mainProducts = [];
        $accessories = [];
        $others = [];
        
        foreach ($hits as $hit) {
            $titleLower = mb_strtolower($hit['title'] ?? '');
            $categoryLower = mb_strtolower($hit['category_path'] ?? '');
            $combined = $titleLower . ' ' . $categoryLower;

            // Detect side plates early
            $isSidePlate = false;
            foreach (['бокова', 'side', '15x15', '15х15', '15x20', '15х20'] as $hint) {
                if (str_contains($combined, $hint)) { $isSidePlate = true; break; }
            }
            
            // Strict accessory detection: these words ALWAYS mean accessory
            $strictAccessoryWords = [
                'камбербанд', 'cummerbund', 'каммербанд',
                'кап ', ' кап', 'cap ', ' cap', // side caps
                'комплект кап', 'комплект кріплень',
                'чохол', 'чохл', 'cover', 'кавер',
                'сумка', 'сумк', 'bag', 'напашник',
                'кішень', 'кишен', 'pocket',
                'кріплення ', 'кріплен ', 'mount ', 'mounting',
                'платформа кріплен', 'адаптер кріплен',
                'тримач ', 'holder ',
                'планка пікатінн', 'picatinny rail',
                'адаптер', 'adapter', 'переходник',
                'рейка ', 'rail ',
                'подушк', 'pad ', 'padding',
                'накладк', 'overlay',
                'ремінь', 'ремен', 'strap', 'sling',
                'модуль ', 'module ',
            ];
            
            $isAccessory = false;
            foreach ($strictAccessoryWords as $word) {
                if (str_contains($combined, $word)) {
                    $isAccessory = true;
                    break;
                }
            }
            
            // Additional check: if title has "для/під/до" + main product → accessory
            if (!$isAccessory && (
                str_contains($titleLower, 'для плитоноск') ||
                str_contains($titleLower, 'для шолом') ||
                str_contains($titleLower, 'до плитоноск') ||
                str_contains($titleLower, 'до шолом') ||
                str_contains($titleLower, 'під шолом') ||
                str_contains($titleLower, 'на шолом')
            )) {
                $isAccessory = true;
            }
            
            if ($isAccessory || ($primaryType === 'plates' && $isSidePlate)) {
                $accessories[] = $hit;
            } else {
                // If we know primary type, treat non-matching types as others
                if ($primaryType && !$this->matchesPrimaryType($combined, $primaryType)) {
                    $others[] = $hit;
                } else {
                    $mainProducts[] = $hit;
                }
            }
        }
        
        Log::info('MeiliProductSearchTool: categorized products', [
            'main' => count($mainProducts),
            'accessories' => count($accessories),
            'query' => $query
        ]);
        
        // If we have 3+ main products of the primary type, EXCLUDE accessories and others
        if (count($mainProducts) >= 3) {
            Log::info('MeiliProductSearchTool: removing accessories, enough main products', [
                'main_count' => count($mainProducts),
                'removed_accessories' => count($accessories)
            ]);
            return $mainProducts;
        }
        
        // Otherwise prefer mains first, then others, then accessories
        return array_merge($mainProducts, $others, $accessories);
    }

    private function detectPrimaryType(string $queryLower): ?string
    {
        $typeKeywords = [
            'plates' => ['плит', 'sapi', 'esapi', 'бронеплит'],
            'plate-carriers' => ['плитоноск', 'носій', 'carrier'],
            'helmets' => ['шолом', 'каск', 'helmet'],
            'footwear' => ['берц', 'черевик', 'взутт', 'ботин', 'boots'],
        ];
        
        // Critical: if query mentions "бокові" or "side" explicitly, NOT main plates
        if (str_contains($queryLower, 'бокові') || str_contains($queryLower, 'side') || str_contains($queryLower, '15x15') || str_contains($queryLower, '15x20')) {
            return null; // Let side plates through accessory filter
        }
        
        foreach ($typeKeywords as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($queryLower, $kw)) {
                    return $type;
                }
            }
        }
        return null;
    }

    private function parseFootwearSizeFromQuery(string $queryLower): ?int
    {
        if (preg_match('/(розмір|size)\s*(\d{2})/u', $queryLower, $m)) {
            $size = (int) $m[2];
            if ($size >= 35 && $size <= 49) { return $size; }
        }
        if (preg_match('/\b(\d{2})\b/u', $queryLower, $m)) {
            $size = (int) $m[1];
            if ($size >= 35 && $size <= 49) { return $size; }
        }
        return null;
    }

    private function extractFootwearSizeFromText(string $text): ?int
    {
        $l = mb_strtolower($text);
        // Explicit size patterns
        if (preg_match('/розмір\s*(\d{2})/u', $l, $m)) {
            $size = (int) $m[1];
            if ($size >= 35 && $size <= 49) { return $size; }
        }
        // EU size patterns (most specific)
        if (preg_match('/\beu\s*(\d{2})\b/u', $l, $m)) {
            $size = (int) $m[1];
            if ($size >= 35 && $size <= 49) { return $size; }
        }
        // Standalone two-digit as fallback (only if looks like EU size)
        if (preg_match('/\b(3[5-9]|4[0-9])\b/u', $l, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function reorderByFootwearSize(array $hits, int $targetSize): array
    {
        usort($hits, function($a, $b) use ($targetSize) {
            $ta = $this->extractFootwearSizeFromText(($a['title'] ?? '') . ' ' . ($a['category_path'] ?? ''));
            $tb = $this->extractFootwearSizeFromText(($b['title'] ?? '') . ' ' . ($b['category_path'] ?? ''));
            $da = is_null($ta) ? 99 : abs($ta - $targetSize);
            $db = is_null($tb) ? 99 : abs($tb - $targetSize);
            return $da <=> $db;
        });
        return $hits;
    }

    private function matchesPrimaryType(string $combinedLower, string $primaryType): bool
    {
        if ($primaryType === 'plates') {
            return (str_contains($combinedLower, 'плит') || str_contains($combinedLower, 'plate')) &&
                   !str_contains($combinedLower, 'чохол') && !str_contains($combinedLower, 'панел');
        }
        if ($primaryType === 'plate-carriers') {
            return str_contains($combinedLower, 'плитоноск') || str_contains($combinedLower, 'носій') || str_contains($combinedLower, 'carrier');
        }
        if ($primaryType === 'helmets') {
            return str_contains($combinedLower, 'шолом') || str_contains($combinedLower, 'каск') || str_contains($combinedLower, 'helmet');
        }
        if ($primaryType === 'footwear') {
            return str_contains($combinedLower, 'берц') || str_contains($combinedLower, 'черевик') || str_contains($combinedLower, 'взутт') || str_contains($combinedLower, 'boot');
        }
        return true;
    }

    /**
     * Fallback to Eloquent when Meilisearch is unavailable
     */
    private function eloquentFallback(string $query, array $filters, int $limit): array
    {
        $builder = Product::query()->where('in_stock', true);
        
        // Text search: extract keywords from query and search by EACH keyword
        if (!empty($query)) {
            // Split query into keywords, filter out small words
            $keywords = array_filter(
                preg_split('/\s+/', mb_strtolower(trim($query))),
                fn($w) => strlen($w) > 1
            );
            
            if (!empty($keywords)) {
                // Search for ANY keyword match (OR logic, like Meili)
                $builder->where(function($q) use ($keywords) {
                    foreach ($keywords as $keyword) {
                        $likePattern = '%' . $keyword . '%';
                        $q->orWhereRaw('LOWER(title) LIKE ?', [$likePattern])
                          ->orWhereRaw('LOWER(search_index) LIKE ?', [$likePattern]);
                    }
                });
            }
        }
        
        // Budget filters
        if (!empty($filters['budget_min'])) {
            $builder->where('price', '>=', $filters['budget_min']);
        }
        if (!empty($filters['budget_max'])) {
            $builder->where('price', '<=', $filters['budget_max']);
        }
        
        // Color filter (if present)
        if (!empty($filters['color'])) {
            $builder->where(function($q) use ($filters) {
                $color = mb_strtolower($filters['color']);
                // Try strict color match first
                $q->whereRaw('LOWER(color) = ?', [$color])
                  // Brand sometimes stores camo keywords
                  ->orWhereRaw('LOWER(brand) LIKE ?', ['%' . $color . '%'])
                  // If color column empty, fall back to title/search_index keyword match
                  ->orWhereRaw('LOWER(title) LIKE ?', ['%' . $color . '%'])
                  ->orWhereRaw('LOWER(search_index) LIKE ?', ['%' . $color . '%']);
            });
        }
        
        // Order by popularity & stock
        $builder->orderBy('popularity', 'desc')
                ->orderBy('quantity', 'desc');
        
        $products = $builder->limit($limit)->get();
        
        Log::info('MeiliProductSearchTool: Eloquent fallback found', [
            'count' => $products->count(),
            'keywords' => $keywords ?? []
        ]);
        
        // Map to same format as Meilisearch
        return $products->map(function($product) {
            return [
                'id' => $product->id,
                'article' => $product->article,
                'parent_article' => $product->parent_article,
                'title' => $product->title,
                'brand' => $product->brand,
                'price' => $product->price,
                'category_path' => $product->category_path,
                'in_stock' => $product->in_stock,
                'popularity' => $product->popularity ?? 0,
                'ai_product_type' => $product->ai_product_type ?? '__unknown__',
                'display_in_showcase' => $product->display_in_showcase ?? false,
            ];
        })->toArray();
    }

    /**
     * Expand query with synonyms for better recall in Meili.
     */
    private function expandQuerySynonyms(string $query): string
    {
        $q = trim($query);
        $l = mb_strtolower($q);
        $append = [];
        // Medical pouch / IFAK
        if (str_contains($l, 'підсумок') || str_contains($l, 'підсумки') || str_contains($l, 'аптечк')) {
            $append[] = 'IFAK';
            $append[] = 'med pouch';
            $append[] = 'medical';
        }
        // Multicam camo
        if (str_contains($l, 'мультикам') || str_contains($l, 'multicam')) {
            $append[] = 'Multicam';
        }
        // Boots
        if (str_contains($l, 'берц') || str_contains($l, 'черевик') || str_contains($l, 'взутт') || str_contains($l, 'boots')) {
            $append[] = 'boots';
        }
        if (!empty($append)) {
            $q .= ' ' . implode(' ', array_unique($append));
        }
        return $q;
    }
}
