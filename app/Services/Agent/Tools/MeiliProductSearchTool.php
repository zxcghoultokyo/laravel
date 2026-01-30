<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use App\Services\Search\BrandDetectionService;
use App\Services\Search\ColorService;
use App\Services\Search\QueryExpander;
use App\Services\Search\SemanticSearchService;
use App\Services\Search\SlangDictionaryService;
use App\Services\Analytics\ABTestingService;
use Illuminate\Support\Facades\Log;

class MeiliProductSearchTool
{
    protected ?ABTestingService $abTesting = null;
    protected ?string $currentSessionId = null;
    protected array $searchMeta = [];
    protected ?SlangDictionaryService $slangDictionary = null;

    public function __construct(
        private MeiliClient $meiliClient,
        private BrandDetectionService $brandDetection,
        private ColorService $colorService,
        private QueryExpander $queryExpander,
        private ?SemanticSearchService $semanticSearch = null
    ) {
        // Try to get semantic search service (may not be bound if embeddings disabled)
        if (!$this->semanticSearch) {
            try {
                $this->semanticSearch = app(SemanticSearchService::class);
            } catch (\Throwable $e) {
                $this->semanticSearch = null;
            }
        }
        
        // Try to get A/B testing service
        try {
            $this->abTesting = app(ABTestingService::class);
        } catch (\Throwable $e) {
            $this->abTesting = null;
        }
        
        // Initialize slang dictionary
        try {
            $this->slangDictionary = app(SlangDictionaryService::class);
        } catch (\Throwable $e) {
            $this->slangDictionary = null;
        }
    }

    /**
     * Set session ID for A/B testing.
     */
    public function setSessionId(?string $sessionId): self
    {
        $this->currentSessionId = $sessionId;
        return $this;
    }

    /**
     * Get metadata about last search (for A/B tracking).
     */
    public function getSearchMeta(): array
    {
        return $this->searchMeta;
    }

    /**
     * Search products in Meilisearch
     * Returns raw candidates with minimal fields for scoring
     */
    public function search(string $query, array $filters = [], int $limit = 40): array
    {
        // Reset search meta
        $this->searchMeta = [
            'query' => $query,
            'used_semantic' => false,
            'used_slang' => false,
            'variant' => 'treatment', // default
        ];
        
        // Get A/B variant features
        $features = $this->getABFeatures();
        $this->searchMeta['variant'] = $this->getABVariant();
        
        try {
            $index = $this->meiliClient->client()->index('products');
            
            // Determine how many results to request from Meili
            // For queries that need heavy post-filtering (helmets, plate carriers),
            // we need more raw results to ensure we find the actual products
            $queryLower = mb_strtolower($query);
            $meiliMultiplier = $this->getMeiliLimitMultiplier($queryLower);
            $meiliLimit = $limit * $meiliMultiplier;
            
            // First: transliterate cyrillic brand names to latin (e.g., "опс кор" → "Ops-Core")
            $normalizedQuery = $this->brandDetection->normalizeBrandsInQuery($query);
            
            // Detect brand in query and enhance search
            $brandInfo = $this->brandDetection->detectBrand($normalizedQuery);
            $enhancedQuery = $this->expandQuerySynonyms($brandInfo['enhanced_query'] ?? $normalizedQuery);
            
            Log::debug('MeiliProductSearchTool: brand normalization', [
                'original' => $query,
                'normalized' => $normalizedQuery,
                'enhanced' => $enhancedQuery,
                'brand_detected' => $brandInfo['brand'] ?? null,
            ]);
            
            // Build filter string
            $filterParts = [];
            
            // Tenant isolation: only show products from current tenant
            $tenantId = $filters['tenant_id'] ?? $this->getCurrentTenantId();
            if ($tenantId) {
                $filterParts[] = "tenant_id = {$tenantId}";
            }
            
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
            
            // Filter out accessory types when searching for main products (helmets, plate carriers, etc.)
            // This is done at Meili level for efficiency - no need to fetch accessories just to filter them out
            $queryLower = mb_strtolower($query);
            $accessoryFilter = $this->buildAccessoryExclusionFilter($queryLower);
            if ($accessoryFilter) {
                $filterParts[] = $accessoryFilter;
            }
            
            // If explicit brand-ONLY search — strictly filter by brand in Meili
            // BUT don't filter if query contains multiple words (likely model/series name)
            // e.g. "Aegis ESAPI Elmon" should NOT filter by Aegis - user wants specific model
            $queryWords = preg_split('/\s+/', trim($query));
            $isBrandOnlyQuery = !empty($brandInfo['is_brand']) 
                && !empty($brandInfo['brand']) 
                && count($queryWords) <= 2; // Only filter for short brand queries
            
            if ($isBrandOnlyQuery) {
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
                    'orders_count',
                    'ai_product_type',
                    'ai_keywords',
                    'display_in_showcase',
                    'brand',
                ],
            ];
            
            // Add sorting if specified
            if (!empty($filters['sort_by'])) {
                $sortBy = $filters['sort_by'];
                $searchParams['sort'] = match($sortBy) {
                    'popularity' => ['orders_count:desc', 'popularity:desc'],
                    'price_asc' => ['price:asc'],
                    'price_desc' => ['price:desc'],
                    'newest', 'recent', 'new' => ['updated_at_ts:desc'],
                    default => null,
                };
                if ($searchParams['sort'] === null) {
                    unset($searchParams['sort']);
                }
                Log::info('MeiliProductSearchTool: sorting by', ['sort_by' => $sortBy, 'sort' => $searchParams['sort'] ?? 'default']);
            }
            
            if ($filterString) {
                $searchParams['filter'] = $filterString;
            }
            
            Log::info('MeiliProductSearchTool: searching', [
                'original_query' => $query,
                'is_brand_search' => $brandInfo['is_brand'],
                'is_brand_only_query' => $isBrandOnlyQuery,
                'detected_brand' => $brandInfo['brand'],
                'enhanced_query' => $enhancedQuery,
                'filter' => $filterString,
                'limit' => $meiliLimit
            ]);
            
            $result = $index->search($enhancedQuery, $searchParams);
            $hits = $result->getHits();
            
            // DEBUG: Log raw hits to see if ai_product_type is present
            if (count($hits) > 0) {
                $debugHits = array_map(fn($h) => [
                    'id' => $h['id'] ?? null,
                    'ai_product_type' => $h['ai_product_type'] ?? '__MISSING__',
                    'title_short' => mb_substr($h['title'] ?? '', 0, 30),
                ], array_slice($hits, 0, 3));
                Log::info('MeiliProductSearchTool: raw hits sample (before any processing)', [
                    'count' => count($hits),
                    'sample' => $debugHits,
                ]);
            }
            
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
            
            // Retry without ai_product_type filter if we got zero hits
            // This handles cases where AI enrichment hasn't classified products yet
            $shouldRetryWithoutTypeFilter = $accessoryFilter && count($hits) === 0;
            if ($shouldRetryWithoutTypeFilter) {
                Log::info('MeiliProductSearchTool: retrying without ai_product_type filter', [
                    'reason' => 'zero_hits_with_type_filter',
                    'removed_filter' => $accessoryFilter,
                ]);
                $filterPartsNoType = array_filter($filterParts, fn($f) => !str_contains($f, 'ai_product_type'));
                $filterStringNoType = implode(' AND ', $filterPartsNoType);
                if ($filterStringNoType) {
                    $searchParams['filter'] = $filterStringNoType;
                } else {
                    unset($searchParams['filter']);
                }
                $result = $index->search($enhancedQuery, $searchParams);
                $hits = $result->getHits();
                Log::info('MeiliProductSearchTool: retry without type filter returned', ['count' => count($hits)]);
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
            
            // Filter products where search term only appears in description, not in title/category
            // This prevents "куртка" from appearing in "термобілизна" search results just because
            // the jacket description mentions "в комбінації з термобілизною"
            $filtered = $this->filterByTitleRelevance($filtered, $enhancedQuery);
            
            // Filter side plates when user asks for main plates (not "бокові")
            $filtered = $this->filterSidePlates($filtered, $query);

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
            
            // If keyword search returned few results, try semantic search fallback
            // BUT only if A/B variant allows semantic search
            // IMPORTANT: Skip semantic fallback for specific category queries (helmets, plate carriers, etc.)
            // because semantic search often returns unrelated products
            $semanticEnabled = $features['semantic_search'] ?? true;
            
            // Detect if this is a specific category query where we should NOT use semantic fallback
            $isCategoryQuery = preg_match('/(шолом|каска|helmet|плитоноск|plate.?carrier|бронежилет|жилет)/ui', $query);
            
            if (count($filtered) < 3 && $semanticEnabled && !$isCategoryQuery && $this->semanticSearch?->isAvailable()) {
                Log::info('MeiliProductSearchTool: trying semantic search fallback', [
                    'keyword_results' => count($filtered),
                    'query' => $query,
                    'ab_variant' => $this->searchMeta['variant'],
                ]);
                
                $semanticResults = $this->semanticSearchFallback($query, $filters, $limit);
                
                if (count($semanticResults) > count($filtered)) {
                    Log::info('MeiliProductSearchTool: semantic search found more results', [
                        'semantic_results' => count($semanticResults),
                    ]);
                    
                    $this->searchMeta['used_semantic'] = true;
                    
                    // Merge semantic results with keyword results (keyword first)
                    $mergedIds = array_column($filtered, 'id');
                    foreach ($semanticResults as $result) {
                        if (!in_array($result['id'], $mergedIds) && count($filtered) < $limit) {
                            $filtered[] = $result;
                            $mergedIds[] = $result['id'];
                        }
                    }
                }
            } elseif ($isCategoryQuery && count($filtered) < 3) {
                Log::info('MeiliProductSearchTool: skipping semantic fallback for category query', [
                    'keyword_results' => count($filtered),
                    'query' => $query,
                ]);
            }
            
            // DEBUG: Log final results to see if ai_product_type survived
            if (count($filtered) > 0) {
                $debugFiltered = array_map(fn($h) => [
                    'id' => $h['id'] ?? null,
                    'ai_product_type' => $h['ai_product_type'] ?? '__MISSING__',
                ], array_slice($filtered, 0, 3));
                Log::info('MeiliProductSearchTool: final results sample (before return)', [
                    'count' => count($filtered),
                    'sample' => $debugFiltered,
                ]);
            }
            
            // Track search for A/B testing
            $this->trackSearchForAB($query, count($filtered));
            
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
     * Build Meili filter to exclude accessory types when searching for main products.
     * Returns null if no filtering needed, otherwise returns filter string.
     * 
     * Strategy: Use POSITIVE filtering (IN) for main product types rather than
     * excluding accessories. This is more reliable because:
     * 1. Word "шолом" appears in search_index of accessories ("кріплення НА шолом")
     * 2. Ranking rules alone can't distinguish between "шолом" (product) and "на шолом" (accessory)
     * 3. AI enrichment correctly marks actual helmets as "helmet" type
     */
    private function buildAccessoryExclusionFilter(string $queryLower): ?string
    {
        // Define main product queries and their ALLOWED product types (positive filtering)
        // This is more reliable than excluding accessories because the main type is well-defined
        $mainProductPatterns = [
            // Helmets: only include actual helmets
            '/(шолом|каска|helmet)/ui' => [
                'include' => ['helmet', 'ballistic_helmet', 'tactical_helmet'],
            ],
            // Plate carriers: include main products
            '/(плитоноск|plate\s*carrier)/ui' => [
                'include' => ['plate_carrier', 'tactical_vest', 'body_armor'],
            ],
        ];
        
        foreach ($mainProductPatterns as $pattern => $config) {
            if (preg_match($pattern, $queryLower)) {
                // Use positive IN filter - more reliable than NOT IN
                $includeTypes = $config['include'] ?? [];
                if (!empty($includeTypes)) {
                    $includeList = array_map(fn($t) => "'{$t}'", $includeTypes);
                    return 'ai_product_type IN [' . implode(', ', $includeList) . ']';
                }
            }
        }
        
        return null;
    }

    /**
     * Get the Meili limit multiplier based on query type.
     * 
     * Some queries need more raw results because:
     * - High-popularity accessories rank above actual products
     * - AI enrichment may have misclassified some products
     * - We need enough results to find actual matches after filtering
     * 
     * @return int Multiplier for meiliLimit (5 = default, 15 = heavy filtering needed)
     */
    private function getMeiliLimitMultiplier(string $queryLower): int
    {
        // Queries that need heavy post-filtering due to accessories ranking high
        $heavyFilteringPatterns = [
            '/(шолом|каска|helmet)/ui',           // Helmets: many accessories (mounts, covers, pads)
            '/(плитоноск|plate\s*carrier)/ui',    // Plate carriers: pouches, panels rank high
            '/(бронежилет|жилет)/ui',             // Body armor: accessories mixed in
        ];
        
        foreach ($heavyFilteringPatterns as $pattern) {
            if (preg_match($pattern, $queryLower)) {
                return 15; // Need 15x to find actual products after filtering
            }
        }
        
        return 5; // Default: 5x for size/color variants dedup
    }

    /**
     * Context-aware accessory filtering using ai_product_type
     * Groups products by dominant ai_product_type and filters out accessories when there are enough main products
     */
    private function filterAccessories(array $hits, string $query): array
    {
        if (empty($hits)) {
            return $hits;
        }

        $queryLower = mb_strtolower($query);
        
        // Count products by ai_product_type
        $typeCounts = [];
        foreach ($hits as $hit) {
            $type = $hit['ai_product_type'] ?? '__unknown__';
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }
        
        // Find the dominant type (most common)
        arsort($typeCounts);
        $dominantType = array_key_first($typeCounts);
        $dominantCount = $typeCounts[$dominantType] ?? 0;
        
        Log::info('MeiliProductSearchTool: filterAccessories ai_product_type analysis', [
            'query' => $query,
            'type_counts' => $typeCounts,
            'dominant_type' => $dominantType,
            'dominant_count' => $dominantCount,
        ]);
        
        // Use ai_product_type, category_path, AND title to determine what's an accessory
        // Combines AI enrichment types with category structure and title patterns for better accuracy
        $isAccessoryType = function($type, ?string $categoryPath = null, ?string $title = null) {
            if (empty($type) || $type === '__unknown__') return true;
            $t = mb_strtolower($type);
            
            // Check category_path first - most reliable indicator
            // "Аксесуари на шоломи" contains accessories, not actual helmets
            if ($categoryPath) {
                $catLower = mb_strtolower($categoryPath);
                if (str_contains($catLower, 'аксесуар')) {
                    return true;
                }
                if (str_contains($catLower, 'комплектуюч')) {
                    return true;
                }
            }
            
            // Check title for accessory patterns (critical when category is wrong!)
            // Many accessories are placed in "Бронезахист/Шоломи" instead of "Аксесуари"
            if ($title) {
                $titleLower = mb_strtolower($title);
                // Accessory patterns in Ukrainian/English
                $accessoryTitlePatterns = [
                    'кріплення', 'адаптер', 'планка', 'подушк', 'противаг',
                    'кавер', 'чохол', 'велкро', 'панел', 'тримач',
                    'маска', 'візор', 'visor', 'mount', 'adapter', 'cover',
                    'pad', 'panel', 'strap', 'clip', 'rail', 'ліхтар',
                    'захист обличчя', 'захист нижньої', 'набір', 'комплект монтаж',
                    'система захист', 'липучк', 'нейлонов', 'платформ',
                ];
                foreach ($accessoryTitlePatterns as $pattern) {
                    if (str_contains($titleLower, $pattern)) {
                        return true;
                    }
                }
            }
            
            // AI enrichment marks accessories with these patterns in ai_product_type
            return str_contains($t, 'accessory') 
                || str_contains($t, 'adapter')
                || str_contains($t, 'mount')
                || str_contains($t, 'strap')
                || str_contains($t, 'cover')
                || str_contains($t, 'patch')
                || str_contains($t, '_pads')   // helmet_pads, etc
                || str_starts_with($t, 'side_');
        };
        
        // Recount with proper category-aware and title-aware detection
        $mainCount = 0;
        $accessoryCount = 0;
        foreach ($hits as $hit) {
            $type = $hit['ai_product_type'] ?? '__unknown__';
            $cat = $hit['category_path'] ?? null;
            $title = $hit['title'] ?? null;
            if ($isAccessoryType($type, $cat, $title)) {
                $accessoryCount++;
            } else {
                $mainCount++;
            }
        }
        
        Log::info('MeiliProductSearchTool: filterAccessories revised analysis', [
            'query' => $query,
            'main_count' => $mainCount,
            'accessory_count' => $accessoryCount,
        ]);
        
        // Determine if query is specifically asking for a main product type (not accessories)
        // If user asks "шоломи" or "плитоноски" - they want main products, not accessories
        // Note: \b word boundaries don't work with Cyrillic in PHP regex
        $isMainProductQuery = preg_match('/(шолом|каска|helmet|плитоноск|plate\s*carrier|бронежилет|жилет)/ui', $queryLower);
        
        // Extract the main product keyword from query for title matching
        $mainProductKeywords = [];
        if (preg_match('/(шолом|каска|helmet)/ui', $queryLower)) {
            $mainProductKeywords = ['шолом', 'каска', 'helmet', 'fast', 'mich', 'ach'];
        } elseif (preg_match('/(плитоноск|plate\s*carrier)/ui', $queryLower)) {
            $mainProductKeywords = ['плитоноска', 'плитоноска', 'plate carrier', 'плейт'];
        } elseif (preg_match('/(бронежилет|жилет)/ui', $queryLower)) {
            $mainProductKeywords = ['бронежилет', 'жилет', 'vest'];
        }
        
        // Filter out accessories if:
        // 1. We have at least 1 main product AND user is asking for main products OR
        // 2. We have at least 2 main products (old logic)
        $shouldFilter = ($mainCount >= 1 && $isMainProductQuery) || ($mainCount >= 2);
        
        if ($shouldFilter) {
            $filtered = array_filter($hits, function ($hit) use ($isAccessoryType, $mainProductKeywords) {
                $type = $hit['ai_product_type'] ?? '__unknown__';
                $cat = $hit['category_path'] ?? null;
                $title = $hit['title'] ?? null;
                
                // First: must not be an accessory
                if ($isAccessoryType($type, $cat, $title)) {
                    return false;
                }
                
                // Second: if we have main product keywords, title MUST contain one of them
                // This prevents unrelated products (plates, headphones) from appearing in helmet results
                if (!empty($mainProductKeywords) && $title) {
                    $titleLower = mb_strtolower($title);
                    $hasKeyword = false;
                    foreach ($mainProductKeywords as $keyword) {
                        if (str_contains($titleLower, $keyword)) {
                            $hasKeyword = true;
                            break;
                        }
                    }
                    if (!$hasKeyword) {
                        return false;
                    }
                }
                
                return true;
            });
            
            Log::info('MeiliProductSearchTool: filtered accessories', [
                'query' => $query,
                'before' => count($hits),
                'after' => count($filtered),
                'is_main_product_query' => $isMainProductQuery,
                'keywords' => $mainProductKeywords,
            ]);
            
            if (count($filtered) >= 1) {
                return array_values($filtered);
            }
        }
        
        // Fallback: sort by putting main products first, accessories last
        usort($hits, function ($a, $b) use ($isAccessoryType) {
            $aIsAccessory = $isAccessoryType($a['ai_product_type'] ?? '__unknown__', $a['category_path'] ?? null, $a['title'] ?? null);
            $bIsAccessory = $isAccessoryType($b['ai_product_type'] ?? '__unknown__', $b['category_path'] ?? null, $b['title'] ?? null);
            
            if ($aIsAccessory === $bIsAccessory) {
                return 0;
            }
            return $aIsAccessory ? 1 : -1;
        });
        
        return $hits;
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
     * Filter products where search term only appears in description but NOT in title/category.
     * 
     * This prevents products from appearing in search results when the search term is only
     * mentioned tangentially in the description (e.g., jacket description mentioning
     * "в комбінації з термобілизною" should not appear in search for "термобілизна").
     * 
     * Products where the search term appears in title, category_path, or ai_keywords are kept.
     */
    private function filterByTitleRelevance(array $hits, string $query): array
    {
        if (empty($hits) || empty($query)) {
            return $hits;
        }
        
        $queryLower = mb_strtolower(trim($query));
        $queryWords = preg_split('/\s+/', $queryLower);
        
        // Only filter for single-word product type queries
        // Multi-word queries (like "чорна плитоноска") need different handling
        if (count($queryWords) > 2) {
            return $hits;
        }
        
        // Define product-type specific terms that should be in title/category
        // If query contains these terms, the product title/category should also contain them
        $productTypeTerms = [
            'термобілизна' => ['термобілизна', 'термобілизни', 'термо', 'level 1', 'level 2', 'ecwcs'],
            'навушники' => ['навушники', 'навушників', 'гарнітура', 'peltor', 'комтак', 'comtac', 'earmor', 'sordin'],
            'рукавички' => ['рукавички', 'рукавиць', 'перчатки', 'gloves'],
            'шкарпетки' => ['шкарпетки', 'шкарпеток', 'socks'],
            'шолом' => ['шолом', 'каска', 'helmet', 'fast', 'mich'],
            'плитоноска' => ['плитоноска', 'плитоносці', 'plate carrier', 'бронежилет', 'жилет'],
            'турнікет' => ['турнікет', 'джгут', 'cat', 'sof', 'tq'],
            'рюкзак' => ['рюкзак', 'рюкзака', 'backpack', 'сумка'],
        ];
        
        // Check if query contains any product type term
        $relevantTerms = null;
        foreach ($productTypeTerms as $term => $variants) {
            foreach ($variants as $variant) {
                if (str_contains($queryLower, $variant)) {
                    $relevantTerms = $variants;
                    break 2;
                }
            }
        }
        
        // If query doesn't match any product type, don't filter
        if (!$relevantTerms) {
            return $hits;
        }
        
        $filtered = [];
        $removed = [];
        
        foreach ($hits as $hit) {
            $title = mb_strtolower($hit['title'] ?? '');
            $category = mb_strtolower($hit['category_path'] ?? '');
            $aiKeywords = mb_strtolower($hit['ai_keywords'] ?? '');
            $aiSlang = mb_strtolower($hit['ai_slang'] ?? '');
            
            // Check if any relevant term appears in title, category, or AI fields
            $foundInRelevantField = false;
            foreach ($relevantTerms as $term) {
                if (str_contains($title, $term) || 
                    str_contains($category, $term) ||
                    str_contains($aiKeywords, $term) ||
                    str_contains($aiSlang, $term)) {
                    $foundInRelevantField = true;
                    break;
                }
            }
            
            if ($foundInRelevantField) {
                $filtered[] = $hit;
            } else {
                $removed[] = $hit['title'] ?? 'unknown';
            }
        }
        
        // If we filtered out everything, return original results
        if (empty($filtered)) {
            Log::info('MeiliProductSearchTool: filterByTitleRelevance would remove all results, keeping originals', [
                'query' => $query,
                'hits_count' => count($hits),
            ]);
            return $hits;
        }
        
        if (!empty($removed)) {
            Log::info('MeiliProductSearchTool: filterByTitleRelevance removed products', [
                'query' => $query,
                'removed_count' => count($removed),
                'removed_titles' => array_slice($removed, 0, 3),
                'kept_count' => count($filtered),
            ]);
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
    
    /**
     * Filter out side plates when user asks for main body armor plates.
     * Side plates (бокові плити) should only appear when explicitly requested.
     */
    private function filterSidePlates(array $hits, string $query): array
    {
        $queryLower = mb_strtolower($query);
        
        // If user explicitly asks for side plates, don't filter them
        $askingForSidePlates = str_contains($queryLower, 'бокові') 
            || str_contains($queryLower, 'бокова')
            || str_contains($queryLower, 'side')
            || str_contains($queryLower, '15x15')
            || str_contains($queryLower, '15x20')
            || str_contains($queryLower, '15х15')
            || str_contains($queryLower, '15х20');
        
        if ($askingForSidePlates) {
            Log::info('MeiliProductSearchTool: user asking for side plates, keeping them', [
                'query' => $query,
            ]);
            return $hits;
        }
        
        // Check if this is a plates/armor query
        $isPlatesQuery = str_contains($queryLower, 'плит') 
            || str_contains($queryLower, 'бронеплит')
            || str_contains($queryLower, 'sapi')
            || str_contains($queryLower, 'esapi');
        
        if (!$isPlatesQuery) {
            return $hits; // Not a plates query, don't filter
        }
        
        // Filter out side plates - they have "бокова/бокові" or "side" or small sizes in title
        $filtered = array_filter($hits, function ($hit) {
            $title = mb_strtolower($hit['title'] ?? '');
            
            // Side plate indicators
            $isSidePlate = str_contains($title, 'бокова')
                || str_contains($title, 'бокові')
                || str_contains($title, 'side')
                || str_contains($title, '15x15')
                || str_contains($title, '15х15')
                || str_contains($title, '15x20')
                || str_contains($title, '15х20')
                || str_contains($title, '6x6')
                || str_contains($title, '6х6');
            
            return !$isSidePlate;
        });
        
        Log::info('MeiliProductSearchTool: filtered side plates', [
            'query' => $query,
            'before' => count($hits),
            'after' => count($filtered),
        ]);
        
        // Return filtered if we still have results, otherwise original
        return count($filtered) >= 2 ? array_values($filtered) : $hits;
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
            // Extract images from product
            $images = $this->extractProductImages($product);
            
            return [
                'id' => $product->id,
                'article' => $product->article,
                'parent_article' => $product->parent_article,
                'title' => $product->title,
                'brand' => $product->brand,
                'price' => $product->price,
                'category_path' => $product->category_path,
                'in_stock' => $product->in_stock,
                'link' => $product->link,
                'images' => $images,
                'popularity' => $product->popularity ?? 0,
                'ai_product_type' => $product->ai_product_type ?? '__unknown__',
                'display_in_showcase' => $product->display_in_showcase ?? false,
            ];
        }, $deduped);
    }
    
    /**
     * Extract images from product (raw or images field).
     */
    private function extractProductImages(Product $product): array
    {
        $images = [];

        // 1. Try raw['pictures'] first (Horoshop format)
        if ($product->raw && is_array($product->raw) && !empty($product->raw['pictures'])) {
            $images = collect($product->raw['pictures'])
                ->map(fn($pic) => is_array($pic) ? ($pic['url'] ?? null) : $pic)
                ->filter()
                ->values()
                ->toArray();
        }

        // 2. Try raw['images']
        if (empty($images) && $product->raw && is_array($product->raw) && !empty($product->raw['images'])) {
            $imgs = $product->raw['images'];
            if (is_array($imgs)) {
                $images = collect($imgs)
                    ->map(fn($img) => is_array($img) ? ($img['url'] ?? $img['src'] ?? null) : $img)
                    ->filter()
                    ->values()
                    ->toArray();
            }
        }

        // 3. Fallback to images field
        if (empty($images) && $product->images) {
            $imgs = $product->images;
            if (is_string($imgs)) {
                $imgs = json_decode($imgs, true) ?: [$imgs];
            }
            if (is_array($imgs)) {
                $images = array_values(array_filter($imgs));
            }
        }

        // 4. Single image fallbacks
        if (empty($images) && $product->raw && is_array($product->raw)) {
            if (!empty($product->raw['image'])) {
                $images = [$product->raw['image']];
            } elseif (!empty($product->raw['main_image'])) {
                $images = [$product->raw['main_image']];
            }
        }

        return $images;
    }

    /**
     * Expand query with synonyms for better recall in Meili.
     */
    private function expandQuerySynonyms(string $query): string
    {
        $q = trim($query);
        $l = mb_strtolower($q);
        
        // 0. SLANG DICTIONARY — expand user slang to standard product terms
        // This is the PRIMARY source for Ukrainian military/tactical slang
        if ($this->slangDictionary) {
            $words = preg_split('/\s+/', $q);
            $expandedWords = [];
            $usedSlang = false;
            
            foreach ($words as $word) {
                // Check if this word is slang for a product type
                $productType = $this->slangDictionary->findTypeByTerm($word);
                
                if ($productType) {
                    // Replace slang with canonical Ukrainian term
                    // e.g., "термуха" → "термобілизна", "пц" → "плитоноска"
                    $entry = config('slang_dictionary.' . $productType, []);
                    $canonical = $entry['synonyms'][0] ?? $productType;
                    
                    // Some canonical replacements
                    $canonicalMap = [
                        'thermal_underwear' => 'термобілизна',
                        'plate_carrier' => 'плитоноска',
                        'headset' => 'навушники',
                        'helmet' => 'шолом',
                        'boots' => 'черевики',
                        'tourniquet' => 'турнікет',
                        'backpack' => 'рюкзак',
                        'pouch' => 'підсумок',
                        'gloves' => 'рукавички',
                        'uniform_pants' => 'штани',
                        'socks' => 'шкарпетки',
                        'jacket' => 'куртка',
                    ];
                    
                    $canonical = $canonicalMap[$productType] ?? $canonical;
                    $expandedWords[] = $canonical;
                    $usedSlang = true;
                    
                    Log::debug('MeiliProductSearchTool: slang expanded', [
                        'original' => $word,
                        'product_type' => $productType,
                        'canonical' => $canonical,
                    ]);
                } else {
                    $expandedWords[] = $word;
                }
            }
            
            if ($usedSlang) {
                $q = implode(' ', $expandedWords);
                $l = mb_strtolower($q);
                $this->searchMeta['used_slang'] = true;
            }
        }
        
        // 1. ENGLISH → UKRAINIAN translation for common tactical terms
        // This enables English queries to find Ukrainian products
        $enToUk = [
            'plate carrier' => 'плитоноска',
            'plate carriers' => 'плитоноска',
            'tactical vest' => 'плитоноска тактичний жилет',
            'body armor' => 'бронежилет',
            'boots' => 'черевики',
            'tactical boots' => 'черевики тактичні',
            'helmet' => 'шолом',
            'gloves' => 'рукавички',
            'tactical gloves' => 'рукавички тактичні',
            'backpack' => 'рюкзак',
            'pouch' => 'підсумок',
            'tourniquet' => 'турнікет',
            'first aid' => 'аптечка',
            'ifak' => 'аптечка IFAK',
            'belt' => 'пояс',
            'pants' => 'штани',
            'jacket' => 'куртка',
            'shirt' => 'сорочка',
            't-shirt' => 'футболка',
            'cap' => 'кепка',
            'magazine' => 'магазин',
            'holster' => 'кобура',
            'sling' => 'ремінь',
            'knee pads' => 'наколінники',
            'elbow pads' => 'налокітники',
        ];
        
        foreach ($enToUk as $en => $uk) {
            if (str_contains($l, $en)) {
                $q = str_ireplace($en, $uk, $q);
                $l = mb_strtolower($q);
            }
        }
        
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
        
        // 2. Use QueryExpander for product type normalization via product_synonyms table
        // This handles domain-specific synonyms configured per tenant
        $expanded = $this->queryExpander->expandQueryWithDomainSynonyms($q, 'uk', null, $this->tenantId);
        
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

    /**
     * Semantic search fallback using embeddings.
     * Used when keyword search returns few/no results.
     */
    private function semanticSearchFallback(string $query, array $filters, int $limit): array
    {
        if (!$this->semanticSearch) {
            return [];
        }

        try {
            $semanticFilters = [
                'in_stock' => true,
            ];

            if (!empty($filters['budget_min'])) {
                $semanticFilters['price_min'] = $filters['budget_min'];
            }
            if (!empty($filters['budget_max'])) {
                $semanticFilters['price_max'] = $filters['budget_max'];
            }

            $results = $this->semanticSearch->search($query, $limit, $semanticFilters, 0.35);

            // Map to same format as Meilisearch results
            return $results->map(function ($product) {
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
                    'ai_product_type' => $product->aiIndex->product_type ?? '__unknown__',
                    'display_in_showcase' => $product->display_in_showcase ?? false,
                    '_semantic_score' => $product->semantic_similarity ?? 0,
                ];
            })->toArray();

        } catch (\Throwable $e) {
            Log::warning('MeiliProductSearchTool: semantic search fallback failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get A/B testing features for current session.
     */
    protected function getABFeatures(): array
    {
        if (!$this->abTesting || !$this->currentSessionId) {
            return [
                'semantic_search' => true,
                'slang_expansion' => true,
                'ai_reranking' => true,
            ];
        }

        return $this->abTesting->getFeatures($this->currentSessionId);
    }

    /**
     * Get A/B variant name for current session.
     */
    protected function getABVariant(): string
    {
        if (!$this->abTesting || !$this->currentSessionId) {
            return 'treatment';
        }

        return $this->abTesting->getVariant($this->currentSessionId);
    }

    /**
     * Track search event for A/B testing.
     */
    protected function trackSearchForAB(string $query, int $resultsCount): void
    {
        if (!$this->abTesting || !$this->currentSessionId) {
            return;
        }

        try {
            $this->abTesting->trackSearch(
                $this->currentSessionId,
                $query,
                $resultsCount,
                $this->searchMeta['used_semantic'] ?? false,
                $this->searchMeta['used_slang'] ?? false
            );
        } catch (\Throwable $e) {
            // Don't let tracking errors break search
            Log::debug('MeiliProductSearchTool: A/B tracking failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get current tenant ID from TenantContext.
     * Returns null for super admin or when no tenant context (shows all products).
     */
    public function getCurrentTenantId(): ?int
    {
        $context = app(\App\Services\Tenant\TenantContext::class);
        
        // Super admin without specific tenant context sees all
        if ($context->isSuperAdmin() && !$context->hasTenant()) {
            return null;
        }
        
        $tenantId = $context->getTenantId();
        
        // Default to main tenant (Contractor, id=2) for widget calls without context
        // This ensures backwards compatibility for old widget installations
        return $tenantId ?? 2;
    }
    
    /**
     * Get current merchant_id (tenant slug) for legacy analytics tables.
     */
    public function getCurrentMerchantId(): ?string
    {
        $context = app(\App\Services\Tenant\TenantContext::class);
        return $context->getMerchantId();
    }
}
// Deploy trigger 1769760953
