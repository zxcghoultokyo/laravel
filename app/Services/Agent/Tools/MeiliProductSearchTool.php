<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use App\Services\Search\BrandDetectionService;
use App\Services\Search\ColorService;
use App\Services\Search\QueryExpander;
use Illuminate\Support\Facades\Log;

class MeiliProductSearchTool
{
    public function __construct(
        private MeiliClient $meiliClient,
        private BrandDetectionService $brandDetection,
        private ColorService $colorService,
        private QueryExpander $queryExpander
    ) {}

    /**
     * Search products in Meilisearch
     * Returns raw candidates with minimal fields for scoring
     */
    public function search(string $query, array $filters = [], int $limit = 40): array
    {
        try {
            $index = $this->meiliClient->client()->index('products');
            
            // Request more results to allow for deduplication by title
            // Since variants (size/color) are separate docs, we need 5x to get enough unique models
            $meiliLimit = $limit * 5;
            
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
            
            // Color filter: strictly by normalized field
            if (!empty($filters['color'])) {
                $canonical = strtolower((string) $filters['color']);
                $filterParts[] = "color_norm = '{$canonical}'";
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
                'limit' => $meiliLimit, // Request more to allow dedup
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
                'limit' => $meiliLimit
            ]);
            
            $result = $index->search($enhancedQuery, $searchParams);
            $hits = $result->getHits();
            
            // Retry without color filter if no hits OR if color_norm might be unreliable
            $shouldRetryWithoutColor = !empty($filters['color']) && (
                count($hits) === 0 || 
                count($hits) < 3  // Not enough results, try broader search
            );
            
            if ($shouldRetryWithoutColor) {
                Log::info('MeiliProductSearchTool: retrying without color_norm filter', [
                    'reason' => count($hits) === 0 ? 'zero_hits' : 'few_hits',
                    'original_count' => count($hits),
                ]);
                $filterPartsNoColor = array_filter($filterParts, fn($f) => !str_contains($f, 'color_norm ='));
                $filterStringNoColor = implode(' AND ', $filterPartsNoColor);
                if ($filterStringNoColor) {
                    $searchParams['filter'] = $filterStringNoColor;
                } else {
                    unset($searchParams['filter']);
                }
                $result = $index->search($enhancedQuery, $searchParams);
                $hitsNoColorFilter = $result->getHits();
                Log::info('MeiliProductSearchTool: retry without color returned', ['count' => count($hitsNoColorFilter)]);
                
                // Use broader results if we got more
                if (count($hitsNoColorFilter) > count($hits)) {
                    $hits = $hitsNoColorFilter;
                }
            }
            
            // ALWAYS post-filter by color if color filter was requested
            // This catches cases where color_norm is wrong/missing in index
            if (!empty($filters['color']) && count($hits) > 0) {
                $hitsBeforeFilter = count($hits);
                $hits = $this->postFilterByColor($hits, $filters['color']);
                Log::info('MeiliProductSearchTool: post-filtered by color', [
                    'color' => $filters['color'],
                    'before' => $hitsBeforeFilter,
                    'after' => count($hits),
                ]);
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
                $filtered = $hits;
            }
            
            // Deduplicate by title to show different models (not just size/color variants)
            $filtered = $this->dedupeByTitle($filtered, $limit);
            
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
        
        // Build list of accessory words mentioned IN THE QUERY - don't filter these out
        $queryAccessoryWords = [];
        $allAccessoryKeywords = [
            'напашник', 'сумка', 'сумк', 'bag',
            'чохол', 'чохл', 'cover', 'кавер',
            'ремінь', 'ремен', 'strap', 'sling',
            'підсумок', 'підсумки', 'pouch',
            'камбербанд', 'cummerbund',
            'панел', 'panel',
            'модуль', 'module',
            'адаптер', 'adapter',
        ];
        foreach ($allAccessoryKeywords as $keyword) {
            if (str_contains($queryLower, $keyword)) {
                $queryAccessoryWords[] = $keyword;
            }
        }
        if (!empty($queryAccessoryWords)) {
            Log::info('MeiliProductSearchTool: query contains accessory words, will preserve matching products', [
                'query' => $query,
                'accessory_words_in_query' => $queryAccessoryWords
            ]);
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
            
            // Check if this product matches any accessory word from the USER'S QUERY
            // If so, DON'T filter it out - user explicitly asked for this
            $matchesQueryAccessoryWord = false;
            foreach ($queryAccessoryWords as $queryWord) {
                if (str_contains($combined, $queryWord)) {
                    $matchesQueryAccessoryWord = true;
                    break;
                }
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
            // Only mark as accessory if it DOESN'T match query accessory words
            if (!$matchesQueryAccessoryWord) {
                foreach ($strictAccessoryWords as $word) {
                    if (str_contains($combined, $word)) {
                        $isAccessory = true;
                        break;
                    }
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
    
    /**
     * Post-filter products by color when color_norm filter didn't work.
     * Checks if color appears in title OR in color field.
     * Uses color synonyms for matching.
     */
    private function postFilterByColor(array $hits, string $requestedColor): array
    {
        $requestedColorLower = mb_strtolower($requestedColor);
        
        // Normalize color to get the canonical color group
        $colorGroup = $this->colorService->normalizeColor($requestedColorLower) ?? $requestedColorLower;
        
        // Get all synonyms for this color group from DB (via ColorService)
        $searchVariants = $this->colorService->getSynonymsForColor($colorGroup);
        
        // FALLBACK: built-in synonyms if DB is empty (critical colors for tactical store)
        if (empty($searchVariants)) {
            $searchVariants = $this->getFallbackColorSynonyms($requestedColorLower);
        }
        
        // Always include the original requested color and the color group
        $searchVariants = array_merge([$requestedColorLower, $colorGroup], $searchVariants);
        $searchVariants = array_unique(array_map('mb_strtolower', $searchVariants));
        
        Log::debug('MeiliProductSearchTool: postFilterByColor variants', [
            'requested' => $requestedColorLower,
            'color_group' => $colorGroup,
            'variants' => $searchVariants,
        ]);
        
        $filtered = [];
        foreach ($hits as $hit) {
            $title = mb_strtolower($hit['title'] ?? '');
            $colorField = mb_strtolower($hit['color'] ?? '');
            $searchIndex = mb_strtolower($hit['search_index'] ?? '');
            
            $matched = false;
            foreach ($searchVariants as $variant) {
                if (str_contains($title, $variant) || 
                    str_contains($colorField, $variant) ||
                    str_contains($searchIndex, $variant)) {
                    $matched = true;
                    break;
                }
            }
            
            if ($matched) {
                $filtered[] = $hit;
            }
        }
        
        return $filtered;
    }

    /**
     * Fallback color synonyms for when DB table is empty.
     * Critical colors for Ukrainian tactical store.
     */
    private function getFallbackColorSynonyms(string $color): array
    {
        $synonymMap = [
            // Pixel / MM14 camo
            'піксель' => ['pixel', 'пиксель', 'mm14', 'мм14', 'піксельний', 'пікселя', 'ua pixel', 'ukrainian pixel'],
            'pixel' => ['піксель', 'пиксель', 'mm14', 'мм14', 'піксельний', 'ua pixel'],
            'пиксель' => ['піксель', 'pixel', 'mm14', 'мм14', 'піксельний'],
            'mm14' => ['піксель', 'pixel', 'пиксель', 'мм14', 'піксельний'],
            
            // Multicam variations
            'мультикам' => ['multicam', 'мультікам', 'mc', 'мтк', 'multi'],
            'multicam' => ['мультикам', 'мультікам', 'mc', 'мтк'],
            'мультікам' => ['мультикам', 'multicam', 'mc'],
            
            // Olive / OD
            'олива' => ['olive', 'od', 'оливковий', 'оливка', 'ranger green', 'рейнджер грін'],
            'olive' => ['олива', 'od', 'оливковий', 'оливка'],
            'od' => ['олива', 'olive', 'оливковий'],
            
            // Black
            'чорний' => ['black', 'чорна', 'чорне', 'чёрный', 'чорного'],
            'black' => ['чорний', 'чорна', 'чорне', 'чёрный'],
            'чёрный' => ['чорний', 'black', 'чорна'],
            
            // Coyote / Tan
            'койот' => ['coyote', 'tan', 'пісочний', 'койоте', 'coyote brown', 'cb'],
            'coyote' => ['койот', 'tan', 'пісочний', 'cb', 'coyote brown'],
            'tan' => ['койот', 'coyote', 'пісочний', 'бежевий'],
            'пісочний' => ['койот', 'coyote', 'tan', 'бежевий', 'пісок'],
            
            // Khaki
            'хакі' => ['khaki', 'хаки', 'хакі'],
            'khaki' => ['хакі', 'хаки'],
            
            // Woodland
            'вудленд' => ['woodland', 'вудланд', 'ліс'],
            'woodland' => ['вудленд', 'вудланд'],
            
            // A-TACS variations
            'атакс' => ['a-tacs', 'atacs', 'а-такс'],
            'a-tacs' => ['атакс', 'atacs', 'а-такс'],
            
            // Grey
            'сірий' => ['grey', 'gray', 'сіра', 'сіре', 'серый', 'wolf grey'],
            'grey' => ['сірий', 'gray', 'сіра', 'серый'],
            'gray' => ['сірий', 'grey', 'сіра', 'серый'],
        ];
        
        // Direct match
        if (isset($synonymMap[$color])) {
            return $synonymMap[$color];
        }
        
        // Partial match (e.g., "піксел" matches "піксель")
        foreach ($synonymMap as $key => $synonyms) {
            if (str_contains($key, $color) || str_contains($color, $key)) {
                return array_merge([$key], $synonyms);
            }
        }
        
        return [];
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
     * Deduplicate results by title to show different models
     * Keeps first occurrence of each unique title
     */
    private function dedupeByTitle(array $results, int $limit): array
    {
        $seen = [];
        $deduped = [];
        foreach ($results as $item) {
            $titleKey = md5(mb_strtolower($item['title'] ?? ''));
            if (!isset($seen[$titleKey])) {
                $seen[$titleKey] = true;
                $deduped[] = $item;
                if (count($deduped) >= $limit) break;
            }
        }
        
        Log::info('MeiliProductSearchTool: deduped by title', [
            'before' => count($results),
            'after' => count($deduped)
        ]);
        
        return $deduped;
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
        
        // Get more results to allow deduplication by title
        $products = $builder->limit($limit * 5)->get();
        
        Log::info('MeiliProductSearchTool: Eloquent fallback found', [
            'count' => $products->count(),
            'keywords' => $keywords ?? []
        ]);
        
        // Deduplicate by title to show different models (not just size variants)
        $seen = [];
        $deduped = [];
        foreach ($products as $product) {
            $titleKey = md5(mb_strtolower($product->title));
            if (!isset($seen[$titleKey])) {
                $seen[$titleKey] = true;
                $deduped[] = $product;
                if (count($deduped) >= $limit) break;
            }
        }
        
        Log::info('MeiliProductSearchTool: After title dedup', [
            'before' => $products->count(),
            'after' => count($deduped)
        ]);
        
        // Map to same format as Meilisearch
        return array_map(function($product) {
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
        }, $deduped);
    }

    /**
     * Expand query with synonyms for better recall in Meili.
     */
    private function expandQuerySynonyms(string $query): string
    {
        $q = trim($query);
        $l = mb_strtolower($q);
        
        // 1. NORMALIZE query - replace user terms with standard terms (NOT expand!)
        // Meilisearch uses AND logic, so adding synonyms breaks search
        
        // Level 7 / ECWCS normalization
        if (preg_match('/\b(левел|лвл|lvl)\s*7\b/iu', $l)) {
            $q = preg_replace('/\b(левел|лвл|lvl)\s*7\b/iu', 'Level 7', $q);
        }
        if (preg_match('/\b(левел|лвл|lvl)\s*(\d)\b/iu', $l, $m)) {
            $q = preg_replace('/\b(левел|лвл|lvl)\s*(\d)\b/iu', 'Level $2', $q);
        }
        if (str_contains($l, 'еквкс')) {
            $q = str_ireplace('еквкс', 'ECWCS', $q);
        }
        
        // Russian → Ukrainian clothing terms
        if (preg_match('/\bбрюки\b/iu', $l)) {
            $q = preg_replace('/\bбрюки\b/iu', 'штани', $q);
        }
        if (preg_match('/\bфутболка\b/iu', $l)) {
            $q = preg_replace('/\bфутболка\b/iu', 'футболка', $q); // same, but ensure case
        }
        if (preg_match('/\bкуртка\b/iu', $l)) {
            $q = preg_replace('/\bкуртка\b/iu', 'куртка', $q);
        }
        if (preg_match('/\bперчатки\b/iu', $l)) {
            $q = preg_replace('/\bперчатки\b/iu', 'рукавички', $q);
        }
        if (preg_match('/\bрукавиц[іи]\b/iu', $l)) {
            $q = preg_replace('/\bрукавиц[іи]\b/iu', 'рукавички', $q);
        }
        if (preg_match('/\bботинки\b/iu', $l)) {
            $q = preg_replace('/\bботинки\b/iu', 'черевики', $q);
        }
        // Footwear synonyms - normalize to "черевики" which is in product titles
        if (preg_match('/\bберц[іиы]\b/iu', $l)) {
            $q = preg_replace('/\bберц[іиы]\b/iu', 'черевики', $q);
        }
        if (preg_match('/\bвзуття\b/iu', $l)) {
            $q = preg_replace('/\bвзуття\b/iu', 'черевики', $q);
        }
        if (preg_match('/\bобувь\b/iu', $l)) {
            $q = preg_replace('/\bобувь\b/iu', 'черевики', $q);
        }
        
        // Socks synonyms - normalize to "шкарпетки"
        if (preg_match('/\bноск[иі]\b/iu', $l)) {
            $q = preg_replace('/\bноск[иі]\b/iu', 'шкарпетки', $q);
        }
        if (preg_match('/\bшкарпетки\b/iu', $l)) {
            // already correct
        }
        
        // 2. Use QueryExpander ONLY for product type normalization (not expansion)
        // This helps with "бронік" → finds "плитоноска" products via search_index
        $expanded = $this->queryExpander->expandQueryWithDomainSynonyms($q, 'uk');
        
        // 3. Limit expansion - don't add too many words (causes AND problem)
        // Only keep first 2-3 synonym additions
        $originalWords = count(preg_split('/\s+/', $q));
        $expandedWords = preg_split('/\s+/', $expanded);
        if (count($expandedWords) > $originalWords + 3) {
            $expanded = implode(' ', array_slice($expandedWords, 0, $originalWords + 3));
        }
        
        Log::debug('MeiliProductSearchTool: normalized query', [
            'original' => $query,
            'normalized' => $expanded,
        ]);
        
        return $expanded;
    }
}
