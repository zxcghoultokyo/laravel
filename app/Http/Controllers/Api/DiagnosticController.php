<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Search\MeiliClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic API for debugging production issues
 * All endpoints require ?key=diagnostic_secret_key_2025
 */
class DiagnosticController extends Controller
{
    private string $secretKey = 'diagnostic_secret_key_2025';

    /**
     * Middleware to check access key
     */
    private function checkKey(Request $request): bool
    {
        return $request->query('key') === $this->secretKey;
    }

    /**
     * GET /api/diagnostic/db-stats
     * Database statistics
     */
    public function dbStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Price statistics (bypass TenantScope)
        try {
            $prices = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('in_stock', true)
                ->where('price', '>', 0)
                ->pluck('price')
                ->map(fn($p) => (float) $p)
                ->sort()
                ->values();
            
            $count = $prices->count();
            if ($count > 0) {
                $p25 = $prices->get((int) ($count * 0.25)) ?? $prices->first();
                $p50 = $prices->get((int) ($count * 0.50)) ?? $prices->avg();
                $p75 = $prices->get((int) ($count * 0.75)) ?? $prices->last();
                
                $priceStats = [
                    'min' => (int) $prices->min(),
                    'max' => (int) $prices->max(),
                    'avg' => (int) round($prices->avg()),
                    'median' => (int) $p50,
                    'budget_max' => (int) $p25,
                    'mid_min' => (int) $p25,
                    'mid_max' => (int) $p75,
                    'premium_min' => (int) $p75,
                ];
            } else {
                $priceStats = [
                    'min' => 0, 'max' => 0, 'avg' => 0, 'median' => 0,
                    'budget_max' => 500, 'mid_min' => 500, 'mid_max' => 3000, 'premium_min' => 3000,
                ];
            }
        } catch (\Exception $e) {
            $priceStats = [
                'min' => 0, 'max' => 0, 'avg' => 0, 'median' => 0,
                'budget_max' => 500, 'mid_min' => 500, 'mid_max' => 3000, 'premium_min' => 3000,
                'error' => $e->getMessage(),
            ];
        }

        // Tenant statistics - bypass TenantScope to see all data, but respect soft deletes
        $tenantStats = DB::table('products')
            ->whereNull('deleted_at')
            ->select('tenant_id', DB::raw('COUNT(*) as count'))
            ->groupBy('tenant_id')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->tenant_id ?? 'NULL' => $row->count
            ]);

        // AI Enrichment statistics (may not exist in all environments)
        try {
            $totalAiIndex = DB::table('product_ai_index')->count();
            $withAiType = DB::table('product_ai_index')->whereNotNull('product_type')->where('product_type', '!=', '')->count();
            $withAiCategory = DB::table('product_ai_index')->whereNotNull('ai_category')->where('ai_category', '!=', '')->count();
            $aiIndexByTenant = DB::table('product_ai_index')
                ->join('products', 'product_ai_index.product_id', '=', 'products.id')
                ->select('products.tenant_id', DB::raw('COUNT(*) as count'))
                ->groupBy('products.tenant_id')
                ->get()
                ->mapWithKeys(fn($row) => [$row->tenant_id ?? 'NULL' => $row->count]);
        } catch (\Exception $e) {
            // Table may not exist
            $totalAiIndex = 0;
            $withAiType = 0;
            $withAiCategory = 0;
            $aiIndexByTenant = collect([]);
        }

        $totalProducts = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->count();

        // Presence breakdown - count products by presence value
        $presenceBreakdown = DB::table('products')
            ->whereNull('deleted_at')
            ->select('presence', DB::raw('COUNT(*) as count'), DB::raw('SUM(CASE WHEN in_stock = 1 THEN 1 ELSE 0 END) as in_stock_count'))
            ->groupBy('presence')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->presence ?? 'NULL' => [
                    'total' => $row->count,
                    'in_stock' => $row->in_stock_count,
                ]
            ]);

        $stats = [
            'total_products' => $totalProducts,
            'in_stock' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->count(),
            'with_color' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->whereNotNull('color')->where('color', '!=', '')->count(),
            'with_size' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->whereNotNull('size')->where('size', '!=', '')->count(),
            'with_parent_article' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->whereNotNull('parent_article')->where('parent_article', '!=', '')->count(),
            'unique_colors' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->whereNotNull('color')->where('color', '!=', '')->distinct()->pluck('color')->toArray(),
            'categories_count' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->distinct('category_path')->count('category_path'),
            'products_by_tenant' => $tenantStats,
            'ai_enrichment' => [
                'total_indexed' => $totalAiIndex,
                'with_ai_type' => $withAiType,
                'with_ai_category' => $withAiCategory,
                'by_tenant' => $aiIndexByTenant,
                'coverage_percent' => $totalProducts > 0 
                    ? round(($totalAiIndex / $totalProducts) * 100, 1) 
                    : 0,
            ],
            'price_stats' => [
                'min' => $priceStats['min'],
                'max' => $priceStats['max'],
                'avg' => $priceStats['avg'],
                'median' => $priceStats['median'],
                'budget_max' => $priceStats['budget_max'],
                'mid_min' => $priceStats['mid_min'],
                'mid_max' => $priceStats['mid_max'],
                'premium_min' => $priceStats['premium_min'],
            ],
            'presence_breakdown' => $presenceBreakdown,
        ];

        return response()->json($stats);
    }

    /**
     * GET /api/diagnostic/cart-events
     * Get add_to_cart events with metadata for debugging
     */
    public function cartEvents(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $merchantId = $request->query('merchant_id', 'ataka-fsp');
        $limit = min((int) $request->query('limit', 50), 100);

        $events = DB::table('chat_events')
            ->where('event_type', 'add_to_cart')
            ->where(function($q) use ($merchantId) {
                $q->where('merchant_id', $merchantId)
                  ->orWhere('merchant_id', 'like', '%' . $merchantId . '%');
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $parsed = $events->map(function($e) {
            $meta = json_decode($e->metadata ?? '{}', true);
            return [
                'id' => $e->id,
                'merchant_id' => $e->merchant_id,
                'session_id' => $e->session_id,
                'product_article' => $e->product_article,
                'product_price' => $e->product_price,
                'had_chat' => $meta['had_chat_conversation'] ?? false,
                'from_chat' => $meta['product_from_chat'] ?? false,
                'created_at' => $e->created_at,
            ];
        });

        // Group by merchant_id for summary
        $byMerchant = $events->groupBy('merchant_id')->map->count();

        // Summary stats
        $total = $parsed->count();
        $withHadChat = $parsed->filter(fn($e) => $e['had_chat'])->count();
        $withFromChat = $parsed->filter(fn($e) => $e['from_chat'])->count();
        $chatAttributed = $parsed->filter(fn($e) => $e['had_chat'] || $e['from_chat'])->count();
        $uniqueSessions = $parsed->pluck('session_id')->unique()->count();
        $chatAttributedSessions = $parsed->filter(fn($e) => $e['had_chat'] || $e['from_chat'])->pluck('session_id')->unique()->count();

        return response()->json([
            'summary' => [
                'total_events' => $total,
                'with_had_chat' => $withHadChat,
                'with_from_chat' => $withFromChat,
                'chat_attributed' => $chatAttributed,
                'unique_sessions' => $uniqueSessions,
                'chat_attributed_sessions' => $chatAttributedSessions,
                'by_merchant' => $byMerchant,
            ],
            'events' => $parsed,
        ]);
    }

    /**
     * GET /api/diagnostic/categories
     * Get all unique categories with product counts
     */
    public function categories(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $categories = Product::where('in_stock', true)
            ->whereNotNull('category_path')
            ->where('category_path', '!=', '')
            ->select('category_path', DB::raw('COUNT(*) as count'))
            ->groupBy('category_path')
            ->orderByDesc('count')
            ->get()
            ->map(fn($c) => [
                'path' => $c->category_path,
                'name' => collect(explode(' > ', $c->category_path))->last(),
                'count' => $c->count,
            ]);

        return response()->json([
            'total' => $categories->count(),
            'categories' => $categories,
        ]);
    }

    /**
     * GET /api/diagnostic/search-db?q=рукавички
     * Search products in DB (Eloquent)
     */
    public function searchDb(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query('q', '');
        $limit = min((int) $request->query('limit', 20), 100);
        $tenantId = $request->query('tenant_id');

        if (!$query) {
            return response()->json(['error' => 'Missing q parameter'], 400);
        }

        // Use withoutGlobalScope to see all products including those with NULL tenant_id
        $productsQuery = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('in_stock', true);
        
        // Filter by tenant_id if provided
        if ($tenantId !== null) {
            $productsQuery->where('tenant_id', (int) $tenantId);
        }
        
        $products = $productsQuery
            ->where(function ($q) use ($query) {
                if ($query !== '*') {
                    $q->where('title', 'like', "%{$query}%")
                      ->orWhere('search_index', 'like', "%{$query}%")
                      ->orWhere('category_path', 'like', "%{$query}%");
                }
            })
            ->limit($limit)
            ->get(['id', 'article', 'title', 'price', 'category_path', 'color', 'size', 'brand', 'in_stock', 'tenant_id', 'images', 'raw']);

        return response()->json([
            'query' => $query,
            'count' => $products->count(),
            'products' => $products->map(function ($p) {
                return [
                    'id' => $p->id,
                    'article' => $p->article,
                    'title' => $p->title,
                    'price' => $p->price,
                    'category_path' => $p->category_path,
                    'color' => $p->color,
                    'size' => $p->size,
                    'brand' => $p->brand,
                    'in_stock' => $p->in_stock,
                    'tenant_id' => $p->tenant_id,
                    'images' => $p->images,
                    'raw_pictures' => $p->raw['pictures'] ?? null,
                    'raw_images' => $p->raw['images'] ?? null,
                    'raw_image' => $p->raw['image'] ?? null,
                ];
            }),
        ]);
    }

    /**
     * GET /api/diagnostic/product-by-article?article=5hy-mwd-8xz
     * Find product by exact article (for debugging parseStructuredResponse)
     */
    public function productByArticle(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $article = $request->query('article', '');
        if (!$article) {
            return response()->json(['error' => 'Missing article parameter'], 400);
        }

        $product = Product::where('article', $article)->first();
        
        if (!$product) {
            return response()->json([
                'found' => false,
                'article' => $article,
                'message' => 'Product not found by exact article match',
            ]);
        }

        // Extract size-related fields from raw for debugging
        $sizeDebug = [
            'Rozmir' => $product->raw['Rozmir'] ?? null,
            'mod_title' => $product->raw['mod_title'] ?? null,
            'select_size' => $product->raw['select']['size'] ?? null,
            'select_rozmir' => $product->raw['select']['rozmir'] ?? null,
            'characteristics_size' => $product->raw['characteristics']['size'] ?? null,
            'characteristics_rozmir' => $product->raw['characteristics']['rozmir'] ?? null,
            'params_size' => $product->raw['params']['size'] ?? null,
        ];
        
        return response()->json([
            'found' => true,
            'article' => $article,
            'product' => [
                'id' => $product->id,
                'article' => $product->article,
                'title' => $product->title,
                'price' => $product->price,
                'in_stock' => $product->in_stock,
                'presence' => $product->presence,
                'quantity' => $product->quantity,
                'display_in_showcase' => $product->display_in_showcase,
                'category_path' => $product->category_path,
                'brand' => $product->brand,
                'color' => $product->color,
                'size' => $product->size,
                'tenant_id' => $product->tenant_id,
                'images' => $product->images,
                'raw_pictures' => $product->raw['pictures'] ?? null,
                'raw_images' => $product->raw['images'] ?? null,
                'raw_image' => $product->raw['image'] ?? null,
                'raw_presence' => $product->raw['presence'] ?? null,
                'raw_quantity' => $product->raw['quantity'] ?? null,
                'raw_display_in_showcase' => $product->raw['display_in_showcase'] ?? null,
                'raw' => $request->query('full_raw') ? $product->raw : null,
            ],
            'size_debug' => $sizeDebug,
        ]);
    }

    /**
     * GET /api/diagnostic/search-meili?q=рукавички
     * Search products in Meilisearch
     */
    public function searchMeili(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query('q', '');
        $limit = min((int) $request->query('limit', 20), 100);
        $filter = $request->query('filter', '');
        $tenantId = $request->query('tenant_id');

        if (!$query) {
            return response()->json(['error' => 'Missing q parameter'], 400);
        }

        try {
            $meili = app(MeiliClient::class);
            $index = $meili->client()->index('products');

            $searchParams = [
                'limit' => $limit,
                'attributesToRetrieve' => ['id', 'article', 'title', 'price', 'category_path', 'color', 'color_norm', 'size', 'brand', 'in_stock', 'ai_product_type', 'tenant_id', 'ai_keywords', 'ai_slang', 'search_index'],
            ];

            // Build filter
            $filterParts = ['in_stock = true'];
            
            if ($tenantId) {
                $filterParts[] = "tenant_id = {$tenantId}";
            }
            
            if ($filter) {
                $filterParts[] = $filter;
            }
            
            $searchParams['filter'] = implode(' AND ', $filterParts);

            $result = $index->search($query, $searchParams);
            $hits = $result->getHits();

            return response()->json([
                'query' => $query,
                'filter' => $searchParams['filter'] ?? null,
                'count' => count($hits),
                'estimated_total' => $result->getEstimatedTotalHits(),
                'processing_time_ms' => $result->getProcessingTimeMs(),
                'products' => $hits,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'query' => $query,
            ], 500);
        }
    }

    /**
     * GET /api/diagnostic/meili-stats
     * Meilisearch index statistics
     */
    public function meiliStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $meiliEnabled = config('meilisearch.enabled', false);
        $meiliHost = config('meilisearch.host', 'not set');
        
        if (!$meiliEnabled) {
            return response()->json([
                'status' => 'disabled',
                'meili_enabled' => false,
                'meili_host' => $meiliHost,
                'message' => 'Meilisearch is disabled in config (MEILI_ENABLED=false)',
            ]);
        }
        
        if (empty($meiliHost)) {
            return response()->json([
                'status' => 'not_configured', 
                'meili_enabled' => true,
                'meili_host' => $meiliHost,
                'message' => 'Meilisearch host is not configured (MEILI_HOST is empty)',
            ]);
        }

        try {
            $meili = app(MeiliClient::class);
            $client = $meili->client();
            
            // First check if client can connect
            $health = $client->health();
            
            $index = $client->index('products');
            $stats = $index->stats(); // v1.x uses stats() not getStats()
            $settings = $index->getSettings();

            return response()->json([
                'status' => 'ok',
                'meili_enabled' => true,
                'meili_host' => $meiliHost,
                'health' => $health,
                'documents' => $stats['numberOfDocuments'] ?? 0,
                'is_indexing' => $stats['isIndexing'] ?? false,
                'field_distribution' => $stats['fieldDistribution'] ?? [],
                'filterable_attributes' => $settings['filterableAttributes'] ?? [],
                'searchable_attributes' => $settings['searchableAttributes'] ?? [],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'meili_enabled' => $meiliEnabled,
                'meili_host' => $meiliHost,
            ], 500);
        }
    }

    /**
     * GET /api/diagnostic/product/{id}
     * Get full product details by ID
     */
    public function product(Request $request, int $id): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $product = Product::with('aiIndex')->find($id);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        // Get siblings (variants)
        $siblings = [];
        if ($product->parent_article) {
            $siblings = Product::where('parent_article', $product->parent_article)
                ->where('in_stock', true)
                ->get(['id', 'article', 'title', 'color', 'size', 'price'])
                ->toArray();
        }

        return response()->json([
            'product' => $product->toArray(),
            'siblings_count' => count($siblings),
            'siblings' => $siblings,
        ]);
    }

    /**
     * GET /api/diagnostic/variants/{parentArticle}
     * Get all variants by parent_article
     */
    public function variants(Request $request, string $parentArticle): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $variants = Product::where('parent_article', $parentArticle)
            ->get(['id', 'article', 'title', 'color', 'size', 'price', 'in_stock', 'quantity']);

        // Group by color
        $byColor = $variants->groupBy('color')->map(function ($items, $color) {
            return [
                'color' => $color ?: '(no color)',
                'count' => $items->count(),
                'sizes' => $items->pluck('size')->filter()->unique()->values()->toArray(),
                'items' => $items->map(fn($p) => [
                    'id' => $p->id,
                    'size' => $p->size,
                    'price' => $p->price,
                    'in_stock' => $p->in_stock,
                ])->toArray(),
            ];
        })->values();

        return response()->json([
            'parent_article' => $parentArticle,
            'total_variants' => $variants->count(),
            'in_stock' => $variants->where('in_stock', true)->count(),
            'unique_colors' => $variants->pluck('color')->filter()->unique()->values()->toArray(),
            'unique_sizes' => $variants->pluck('size')->filter()->unique()->values()->toArray(),
            'by_color' => $byColor,
        ]);
    }

    /**
     * GET /api/diagnostic/category-products?path=Рукавиці
     * Get products in category
     */
    public function categoryProducts(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $path = $request->query('path', '');
        $limit = min((int) $request->query('limit', 50), 200);

        if (!$path) {
            return response()->json(['error' => 'Missing path parameter'], 400);
        }

        $products = Product::where('in_stock', true)
            ->where('category_path', 'like', "%{$path}%")
            ->limit($limit)
            ->get(['id', 'article', 'title', 'price', 'category_path', 'color', 'size', 'brand']);

        return response()->json([
            'path_filter' => $path,
            'count' => $products->count(),
            'products' => $products,
        ]);
    }

    /**
     * GET /api/diagnostic/test-chat?q=рукавички тактичні
     * Test chat search without AI (just Meili + processing)
     * Now with detailed debug info to trace what happens at each step
     */
    public function testChat(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query('q', '');
        $tenantId = $request->query('tenant_id', 2); // Default to tenant 2 for debugging

        if (!$query) {
            return response()->json(['error' => 'Missing q parameter'], 400);
        }

        try {
            // STEP 1: Direct Meili search with same filter as MeiliProductSearchTool
            $meili = app(\App\Services\Search\MeiliClient::class);
            $index = $meili->client()->index('products');
            
            // Build the same filter that MeiliProductSearchTool would build
            $queryLower = mb_strtolower($query);
            $accessoryFilter = null;
            if (preg_match('/(шолом|каска|helmet)/ui', $queryLower)) {
                $accessoryFilter = "ai_product_type IN ['helmet', 'ballistic_helmet', 'tactical_helmet']";
            }
            
            $meiliFilter = "tenant_id = {$tenantId} AND in_stock = true";
            if ($accessoryFilter) {
                $meiliFilter .= " AND " . $accessoryFilter;
            }
            
            $rawMeiliWithFilter = $index->search($query, [
                'limit' => 10,
                'filter' => $meiliFilter,
                'attributesToRetrieve' => ['id', 'title', 'ai_product_type', 'category_path'],
            ])->getHits();
            
            // STEP 2: Direct Meili without ai_product_type filter for comparison
            $rawMeiliNoFilter = $index->search($query, [
                'limit' => 5,
                'filter' => "tenant_id = {$tenantId} AND in_stock = true",
                'attributesToRetrieve' => ['id', 'title', 'ai_product_type'],
            ])->getHits();
            
            // STEP 3: Run MeiliProductSearchTool.search()
            $searchTool = app(\App\Services\Agent\Tools\MeiliProductSearchTool::class);
            $currentTenantId = $searchTool->getCurrentTenantId();
            $filters = ['tenant_id' => (int)$tenantId];
            $results = $searchTool->search($query, $filters, 20);

            return response()->json([
                'query' => $query,
                'tenant_id_requested' => (int)$tenantId,
                'tenant_id_from_context' => $currentTenantId,
                'meili_filter_used' => $meiliFilter,
                'accessory_filter' => $accessoryFilter,
                
                // Raw Meili WITH ai_product_type filter
                'raw_meili_with_filter_count' => count($rawMeiliWithFilter),
                'raw_meili_with_filter' => array_map(fn($h) => [
                    'id' => $h['id'] ?? null,
                    'title' => mb_substr($h['title'] ?? '', 0, 50),
                    'ai_product_type' => $h['ai_product_type'] ?? '__missing__',
                ], array_slice($rawMeiliWithFilter, 0, 5)),
                
                // Raw Meili WITHOUT ai_product_type filter
                'raw_meili_no_filter_count' => count($rawMeiliNoFilter),
                'raw_meili_no_filter' => array_map(fn($h) => [
                    'id' => $h['id'] ?? null,
                    'ai_product_type' => $h['ai_product_type'] ?? '__missing__',
                ], array_slice($rawMeiliNoFilter, 0, 5)),
                
                // MeiliProductSearchTool results
                'meili_tool_count' => count($results),
                'meili_tool_results' => array_map(fn($p) => [
                    'id' => $p['id'] ?? null,
                    'title' => mb_substr($p['title'] ?? '', 0, 50),
                    'ai_product_type' => $p['ai_product_type'] ?? '__missing__',
                    'category_path' => $p['category_path'] ?? null,
                ], array_slice($results, 0, 5)),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query' => $query,
            ], 500);
        }
    }
    
    /**
     * GET /api/diagnostic/trace-meili?q=...&tenant_id=2
     * Step-by-step trace of MeiliProductSearchTool to find where ai_product_type is lost
     */
    public function traceMeili(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query('q', '');
        $tenantId = (int)$request->query('tenant_id', 2);

        if (!$query) {
            return response()->json(['error' => 'Missing q parameter'], 400);
        }

        $trace = [];
        
        try {
            $meili = app(\App\Services\Search\MeiliClient::class);
            $index = $meili->client()->index('products');
            
            // Build filter
            $filterParts = ["tenant_id = {$tenantId}", "in_stock = true"];
            $queryLower = mb_strtolower($query);
            
            // Add helmet filter if query matches
            if (preg_match('/(шолом|каска|helmet)/ui', $queryLower)) {
                $filterParts[] = "ai_product_type IN ['helmet', 'ballistic_helmet', 'tactical_helmet']";
            }
            
            $filterString = implode(' AND ', $filterParts);
            $trace['filter'] = $filterString;
            
            // Search
            $searchParams = [
                'limit' => 20,
                'filter' => $filterString,
                'attributesToRetrieve' => [
                    'id', 'article', 'parent_article', 'title', 'price',
                    'category_path', 'in_stock', 'popularity', 'orders_count',
                    'ai_product_type', 'ai_keywords', 'display_in_showcase', 'brand',
                ],
            ];
            
            $result = $index->search($query, $searchParams);
            $hits = $result->getHits();
            
            $trace['step1_raw_hits'] = array_map(fn($h) => [
                'id' => $h['id'] ?? null,
                'ai_product_type' => $h['ai_product_type'] ?? '__MISSING__',
                'title' => mb_substr($h['title'] ?? '', 0, 40),
            ], array_slice($hits, 0, 5));
            $trace['step1_count'] = count($hits);
            
            // Apply __unknown__ for empty ai_product_type (like MeiliProductSearchTool does)
            foreach ($hits as &$hit) {
                if (empty($hit['ai_product_type'])) {
                    $hit['ai_product_type'] = '__unknown__';
                }
            }
            unset($hit); // Important: unset reference!
            
            $trace['step2_after_unknown_default'] = array_map(fn($h) => [
                'id' => $h['id'] ?? null,
                'ai_product_type' => $h['ai_product_type'] ?? '__MISSING__',
            ], array_slice($hits, 0, 5));
            
            // STEP 3: Simulate filterAccessories logic
            $accessoryTitlePatterns = [
                'кріплення', 'адаптер', 'планка', 'подушк', 'противаг',
                'кавер', 'чохол', 'велкро', 'панел', 'тримач',
                'маска', 'візор', 'visor', 'mount', 'adapter', 'cover',
                'pad', 'panel', 'strap', 'clip', 'rail', 'ліхтар',
                'захист обличчя', 'захист нижньої', 'набір', 'комплект монтаж',
                'система захист', 'липучк', 'нейлонов', 'платформ',
            ];
            
            $mainProducts = [];
            $filteredOut = [];
            
            foreach ($hits as $hit) {
                $titleLower = mb_strtolower($hit['title'] ?? '');
                $isAccessory = false;
                $matchedPattern = null;
                
                foreach ($accessoryTitlePatterns as $pattern) {
                    if (str_contains($titleLower, $pattern)) {
                        $isAccessory = true;
                        $matchedPattern = $pattern;
                        break;
                    }
                }
                
                if ($isAccessory) {
                    $filteredOut[] = [
                        'id' => $hit['id'],
                        'title' => mb_substr($hit['title'] ?? '', 0, 50),
                        'matched_pattern' => $matchedPattern,
                    ];
                } else {
                    $mainProducts[] = [
                        'id' => $hit['id'],
                        'title' => mb_substr($hit['title'] ?? '', 0, 50),
                        'ai_product_type' => $hit['ai_product_type'] ?? '__unknown__',
                    ];
                }
            }
            
            $trace['step3_main_products_count'] = count($mainProducts);
            $trace['step3_main_products'] = array_slice($mainProducts, 0, 10);
            $trace['step3_filtered_out_count'] = count($filteredOut);
            $trace['step3_filtered_out_sample'] = array_slice($filteredOut, 0, 5);
            
            // Step 4: Check if shouldFilter would be true
            $isMainProductQuery = preg_match('/(шолом|каска|helmet|плитоноск|plate.?carrier|бронежилет|жилет)/ui', $queryLower);
            $shouldFilter = (count($mainProducts) >= 1 && $isMainProductQuery) || (count($mainProducts) >= 2);
            $trace['step4_shouldFilter'] = $shouldFilter;
            $trace['step4_isMainProductQuery'] = (bool)$isMainProductQuery;
            $trace['step4_mainCount'] = count($mainProducts);
            
            // Step 5: What would MeiliProductSearchTool.search() actually return?
            $searchTool = app(\App\Services\Agent\Tools\MeiliProductSearchTool::class);
            $toolFilters = ['tenant_id' => $tenantId];
            $toolResults = $searchTool->search($query, $toolFilters, 20);
            $searchMeta = $searchTool->getSearchMeta();
            
            $trace['step5_meili_tool_results_count'] = count($toolResults);
            $trace['step5_meili_tool_search_meta'] = $searchMeta;
            $trace['step5_meili_tool_results'] = array_map(fn($p) => [
                'id' => $p['id'] ?? null,
                'ai_product_type' => $p['ai_product_type'] ?? '__missing__',
                'title' => mb_substr($p['title'] ?? '', 0, 40),
            ], array_slice($toolResults, 0, 5));
            
            return response()->json([
                'query' => $query,
                'tenant_id' => $tenantId,
                'trace' => $trace,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * GET /api/diagnostic/sync-sample?article=xxx
     * Check raw data for a product (what came from Horoshop)
     */
    public function syncSample(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $article = $request->query('article', '');

        if (!$article) {
            // Return random product with raw data
            $product = Product::whereNotNull('raw')->where('in_stock', true)->inRandomOrder()->first();
        } else {
            $product = Product::where('article', $article)->first();
        }

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $raw = $product->raw;

        return response()->json([
            'article' => $product->article,
            'title' => $product->title,
            'db_color' => $product->color,
            'db_size' => $product->size,
            'raw_color' => $raw['color'] ?? null,
            'raw_Kolir' => $raw['Kolir'] ?? null,
            'raw_Rozmir' => $raw['Rozmir'] ?? null,
            'raw_mod_title' => $raw['mod_title'] ?? null,
            'has_raw' => !empty($raw),
            'raw_keys' => is_array($raw) ? array_keys($raw) : [],
        ]);
    }

    /**
     * POST /api/diagnostic/reindex-meili
     * Trigger Meilisearch reindex
     * Add ?sync=1 to run synchronously (takes longer but doesn't need queue worker)
     * Add ?tenant_id=N to reindex only specific tenant
     */
    public function reindexMeili(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $chunk = min((int) $request->query('chunk', 200), 500);
        $sync = $request->query('sync', false);
        $tenantId = $request->query('tenant_id') ? (int) $request->query('tenant_id') : null;
        
        // Count products
        $query = Product::withoutGlobalScope(\App\Scopes\TenantScope::class);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $total = $query->count();

        if ($total === 0) {
            return response()->json([
                'status' => 'skipped',
                'message' => 'No products found',
            ]);
        }

        if ($sync) {
            // Run synchronously - for when queue worker is not running
            set_time_limit(300); // 5 minutes
            // Constructor: __construct(?int $tenantId = null, int $chunkSize = 500)
            $job = new \App\Jobs\IndexProductsToMeiliJob($tenantId, $chunk);
            $job->handle(app(\App\Services\Search\MeiliClient::class));
            
            return response()->json([
                'status' => 'completed',
                'total_products' => $total,
                'tenant_id' => $tenantId,
                'chunk_size' => $chunk,
                'message' => "Indexed {$total} product(s) synchronously",
            ]);
        }

        // Dispatch to queue - correct argument order: (tenantId, chunkSize)
        \App\Jobs\IndexProductsToMeiliJob::dispatch($tenantId, $chunk)
            ->onQueue('meili');

        return response()->json([
            'status' => 'dispatched',
            'jobs' => 1,
            'total_products' => $total,
            'tenant_id' => $tenantId,
            'chunk_size' => $chunk,
            'message' => "Dispatched 1 job to queue=meili for {$total} product(s)",
        ]);
    }

    /**
     * POST /api/diagnostic/ai-enrich
     * Run AI enrichment for products without AI index
     * 
     * Query params:
     *   - tenant_id: optional, specific tenant (default: all tenants)
     *   - sync: run synchronously (default: queue)
     *   - batch: batch size (default: 50)
     *   - force: re-analyze even if already has AI index
     */
    public function aiEnrich(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->input('tenant_id') ? (int) $request->input('tenant_id') : null;
        $sync = $request->boolean('sync', false);
        $batchSize = min(200, max(10, (int) $request->input('batch', 50)));
        $force = $request->boolean('force', false);

        // Count products needing enrichment
        $query = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('in_stock', true);
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        if (!$force) {
            $query->whereNotIn('id', function ($q) {
                $q->select('product_id')->from('product_ai_index')->whereNotNull('keywords');
            });
        }
        
        $productsToEnrich = $query->count();
        
        // Get stats per tenant
        $statsByTenant = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('in_stock', true)
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->when(!$force, fn($q) => $q->whereNotIn('id', function ($sq) {
                $sq->select('product_id')->from('product_ai_index')->whereNotNull('keywords');
            }))
            ->selectRaw('tenant_id, COUNT(*) as count')
            ->groupBy('tenant_id')
            ->pluck('count', 'tenant_id')
            ->toArray();

        if ($productsToEnrich === 0) {
            return response()->json([
                'status' => 'skipped',
                'message' => 'All products already have AI index',
                'by_tenant' => $statsByTenant,
            ]);
        }

        if ($sync) {
            // Run synchronously for all tenants - process ALL batches in a loop
            set_time_limit(3600); // 1 hour for large datasets
            $results = [];
            
            $tenants = $tenantId 
                ? [\App\Models\Tenant::find($tenantId)]
                : \App\Models\Tenant::where('status', 'active')->get();
            
            foreach ($tenants as $tenant) {
                if (!$tenant) continue;
                
                $tenantCount = $statsByTenant[$tenant->id] ?? 0;
                if ($tenantCount === 0) continue;
                
                try {
                    $processed = 0;
                    $batchNum = 0;
                    
                    // Process all batches in a loop (not via dispatch)
                    while ($processed < $tenantCount) {
                        $batchNum++;
                        
                        // Get products for this batch directly (not via job)
                        $products = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                            ->where('tenant_id', $tenant->id)
                            ->where('in_stock', true)
                            ->whereNotNull('title')
                            ->when(!$force, fn($q) => $q->whereNotIn('id', function ($sq) {
                                $sq->select('product_id')->from('product_ai_index')->whereNotNull('keywords');
                            }))
                            ->orderBy('id')
                            ->take($batchSize)
                            ->get();
                        
                        if ($products->isEmpty()) {
                            break;
                        }
                        
                        // Process products using ProductIndexBuilder directly
                        $builder = app(\App\Services\Ai\ProductIndexBuilder::class);
                        foreach ($products as $product) {
                            try {
                                $builder->buildAndSaveIndex($product);
                                $processed++;
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('AI enrichment failed for product', [
                                    'product_id' => $product->id,
                                    'error' => $e->getMessage(),
                                ]);
                                // Continue with next product even on error
                            }
                            
                            // Rate limiting: 300ms between API calls
                            usleep(300000);
                        }
                        
                        // Update tenantCount for accurate tracking
                        $tenantCount = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                            ->where('tenant_id', $tenant->id)
                            ->where('in_stock', true)
                            ->when(!$force, fn($q) => $q->whereNotIn('id', function ($sq) {
                                $sq->select('product_id')->from('product_ai_index')->whereNotNull('keywords');
                            }))
                            ->count();
                        
                        \Illuminate\Support\Facades\Log::info('AI enrichment batch complete', [
                            'tenant_id' => $tenant->id,
                            'batch' => $batchNum,
                            'batch_size' => $products->count(),
                            'total_processed' => $processed,
                            'remaining' => $tenantCount,
                        ]);
                    }
                    
                    $results[$tenant->id] = [
                        'status' => 'completed',
                        'products_processed' => $processed,
                    ];
                } catch (\Throwable $e) {
                    $results[$tenant->id] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            return response()->json([
                'status' => 'completed',
                'mode' => 'sync',
                'total_to_enrich' => $productsToEnrich,
                'by_tenant' => $statsByTenant,
                'results' => $results,
            ]);
        }

        // Dispatch jobs per tenant
        $tenants = $tenantId 
            ? [\App\Models\Tenant::find($tenantId)]
            : \App\Models\Tenant::where('status', 'active')->get();
        
        $dispatched = [];
        foreach ($tenants as $tenant) {
            if (!$tenant) continue;
            
            $tenantCount = $statsByTenant[$tenant->id] ?? 0;
            if ($tenantCount === 0) continue;
            
            \App\Jobs\AnalyzeProductsWithAiJob::dispatch(
                batchSize: min($batchSize, $tenantCount),
                offset: 0,
                forceReanalyze: $force,
                tenantId: $tenant->id
            )->onQueue('default');
            
            $dispatched[$tenant->id] = $tenantCount;
        }

        return response()->json([
            'status' => 'dispatched',
            'mode' => 'queue',
            'total_to_enrich' => $productsToEnrich,
            'by_tenant' => $statsByTenant,
            'jobs_dispatched' => $dispatched,
            'message' => 'Jobs dispatched to queue. Check queue worker logs.',
        ]);
    }

    /**
     * GET /api/diagnostic/ai-test-one
     * Test AI enrichment on a single product with full debug output
     */
    public function aiTestOne(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $productId = $request->input('product_id');
        
        // Get one product to test
        $product = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('in_stock', true)
            ->whereNotNull('title')
            ->when($productId, fn($q) => $q->where('id', $productId))
            ->first();
        
        if (!$product) {
            return response()->json(['error' => 'No product found'], 404);
        }

        $config = config('services.openai', []);
        $apiKey = $config['key'] ?? null;
        
        if (!$apiKey) {
            return response()->json([
                'error' => 'OpenAI API key not configured',
                'config_keys' => array_keys($config),
            ], 500);
        }

        $raw = is_array($product->raw) ? $product->raw : json_decode($product->raw ?? '{}', true);
        
        $title = $product->title ?? '';
        $description = $this->extractDescriptionForTest($raw);
        $category = $product->category_path ?? '';
        $characteristics = $this->extractCharacteristicsForTest($raw);

        $prompt = $this->buildPromptForTest($title, $description, $category, $characteristics);

        // Hardcode gpt-4o-mini for testing - gpt-5-nano has too many restrictions
        $model = 'gpt-4o-mini';
        $baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        
        // Build request body for gpt-4o-mini
        $requestBody = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an e-commerce product expert for Ukrainian market. Classify ANY product type correctly. Respond ONLY with valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 800,
        ];
        
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withToken($apiKey)
                ->post($baseUrl . '/chat/completions', $requestBody);

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;
            
            // Try to parse JSON
            $json = null;
            if ($content) {
                $cleanContent = preg_replace('/```json\s*/i', '', $content);
                $cleanContent = preg_replace('/```\s*$/i', '', $cleanContent);
                $cleanContent = trim($cleanContent);
                $json = json_decode($cleanContent, true);
            }
            
            // Save if requested and JSON parsed successfully
            $saved = false;
            if ($request->boolean('save') && $json !== null) {
                \App\Models\ProductAiIndex::updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'product_type' => $json['product_type'] ?? null,
                        'ai_category' => $json['ai_category'] ?? null,
                        'materials' => $json['materials'] ?? [],
                        'standards' => $json['standards'] ?? [],
                        'slang' => $json['slang'] ?? [],
                        'keywords' => $json['keywords'] ?? [],
                        'usage' => $json['usage'] ?? [],
                        'raw_ai_json' => $json,
                    ]
                );
                $saved = true;
            }
            
            return response()->json([
                'product' => [
                    'id' => $product->id,
                    'title' => $title,
                    'category' => $category,
                    'tenant_id' => $product->tenant_id,
                ],
                'saved' => $saved,
                'api_config' => [
                    'model' => $model,
                    'base_url' => $baseUrl,
                    'has_key' => !empty($apiKey),
                ],
                'prompt_length' => mb_strlen($prompt),
                'response_status' => $response->status(),
                'response_raw' => $data,
                'content' => $content,
                'parsed_json' => $json,
                'json_error' => $json === null ? json_last_error_msg() : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'product' => [
                    'id' => $product->id,
                    'title' => $title,
                ],
                'api_config' => [
                    'model' => $model,
                    'base_url' => $baseUrl,
                ],
            ], 500);
        }
    }

    private function extractDescriptionForTest(array $raw): string
    {
        $desc = $raw['description'] ?? $raw['description_full'] ?? '';
        if (is_array($desc)) {
            $desc = $desc['uk'] ?? $desc['ua'] ?? reset($desc) ?: '';
        }
        return mb_substr(strip_tags((string)$desc), 0, 1000);
    }

    private function extractCharacteristicsForTest(array $raw): string
    {
        $chars = $raw['characteristics'] ?? $raw['attrs'] ?? [];
        if (!is_array($chars)) {
            return '';
        }
        $lines = [];
        foreach ($chars as $key => $value) {
            if (is_array($value)) {
                // Handle nested structures like {"id": 1, "value": {"ua": "Text"}}
                if (isset($value['value'])) {
                    $value = is_array($value['value']) 
                        ? ($value['value']['ua'] ?? $value['value']['uk'] ?? reset($value['value']) ?: '')
                        : $value['value'];
                } else {
                    // Try to flatten simple arrays
                    $value = implode(', ', array_filter($value, 'is_string'));
                }
            }
            if (is_string($value) && $value !== '') {
                $lines[] = "{$key}: {$value}";
            }
        }
        return implode('; ', array_slice($lines, 0, 20));
    }

    private function buildPromptForTest(string $title, string $description, string $category, string $characteristics): string
    {
        return "Проаналізуй цей товар та згенеруй JSON для пошукового індексу.

ТОВАР:
Назва: {$title}
Категорія: {$category}
Опис: " . mb_substr($description, 0, 500) . "
Характеристики: {$characteristics}

Згенеруй JSON з полями:
1. \"product_type\": основний тип товару англійською (ОБОВ'ЯЗКОВО! smartphone, laptop, plate_carrier, helmet, boots, jacket, etc)
2. \"ai_category\": загальна категорія (electronics, armor, apparel, footwear, accessories, bags, home, etc)
3. \"keywords\": масив 10-15 ключових слів УКРАЇНСЬКОЮ та АНГЛІЙСЬКОЮ (включи синоніми: телефон/phone, смартфон/smartphone)
4. \"slang\": масив 5-10 сленгових назв УКРАЇНСЬКОЮ як шукають реальні люди (айфон, мобілка, ноут, плитка, бронік)
5. \"materials\": масив матеріалів якщо є
6. \"standards\": масив стандартів якщо є (IP68, NIJ III, etc)
7. \"usage\": масив призначення (everyday, work, gaming, sport, etc)

КРИТИЧНО: product_type та ai_category ОБОВ'ЯЗКОВІ - визнач з назви! Відповідай ТІЛЬКИ валідним JSON.";
    }

    /**
     * POST /api/diagnostic/ai-enrich-batch-sync
     * Synchronously process a batch of products without AI index
     * 
     * Query params:
     * - tenant_id: required - process only this tenant's products
     * - batch_size: 1-20, default 5 - how many products to process
     * - offset: skip N products (for manual pagination)
     */
    public function aiEnrichBatchSync(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->input('tenant_id');
        if (!$tenantId) {
            return response()->json(['error' => 'tenant_id is required'], 400);
        }

        $batchSize = min(20, max(1, (int) $request->input('batch_size', 5)));
        $offset = max(0, (int) $request->input('offset', 0));

        // Get products without AI index for this tenant
        $products = \App\Models\Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('id', function($query) {
                $query->select('product_id')->from('product_ai_index');
            })
            ->orderBy('id')
            ->offset($offset)
            ->limit($batchSize)
            ->get();

        if ($products->isEmpty()) {
            // Check if there are any products left
            $remainingCount = \App\Models\Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotIn('id', function($query) {
                    $query->select('product_id')->from('product_ai_index');
                })
                ->count();

            return response()->json([
                'status' => 'complete',
                'message' => 'No more products to process',
                'remaining' => $remainingCount,
                'processed' => [],
            ]);
        }

        // API config
        $config = config('services.openai', []);
        $apiKey = $config['key'] ?? null;
        $baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/');
        $model = 'gpt-4o-mini'; // Same as ai-test-one

        if (empty($apiKey)) {
            return response()->json(['error' => 'OpenAI API key not configured'], 500);
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($products as $product) {
            $raw = $product->raw ?? [];
            $title = is_array($raw['title'] ?? null)
                ? ($raw['title']['uk'] ?? $raw['title']['ua'] ?? $product->title)
                : ($raw['title'] ?? $product->title);
            $category = $product->category_path ?? '';
            $description = $this->extractDescriptionForTest($raw);
            $characteristics = $this->extractCharacteristicsForTest($raw);

            $prompt = $this->buildPromptForTest($title, $description, $category, $characteristics);

            $requestBody = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 1000,
            ];

            try {
                $response = \Illuminate\Support\Facades\Http::timeout(45)
                    ->withToken($apiKey)
                    ->post($baseUrl . '/chat/completions', $requestBody);

                if (!$response->successful()) {
                    $results[] = [
                        'product_id' => $product->id,
                        'title' => $title,
                        'status' => 'error',
                        'error' => 'API error: ' . $response->status(),
                    ];
                    $errorCount++;
                    continue;
                }

                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? null;

                // Parse JSON
                $json = null;
                if ($content) {
                    $cleanContent = preg_replace('/```json\s*/i', '', $content);
                    $cleanContent = preg_replace('/```\s*$/i', '', $cleanContent);
                    $cleanContent = trim($cleanContent);
                    $json = json_decode($cleanContent, true);
                }

                if ($json === null) {
                    $results[] = [
                        'product_id' => $product->id,
                        'title' => $title,
                        'status' => 'error',
                        'error' => 'Failed to parse JSON: ' . json_last_error_msg(),
                        'raw_content' => mb_substr($content ?? '', 0, 200),
                    ];
                    $errorCount++;
                    continue;
                }

                // Save to database
                \App\Models\ProductAiIndex::updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'product_type' => $json['product_type'] ?? null,
                        'ai_category' => $json['ai_category'] ?? null,
                        'materials' => $json['materials'] ?? [],
                        'standards' => $json['standards'] ?? [],
                        'slang' => $json['slang'] ?? [],
                        'keywords' => $json['keywords'] ?? [],
                        'usage' => $json['usage'] ?? [],
                        'raw_ai_json' => $json,
                    ]
                );

                $results[] = [
                    'product_id' => $product->id,
                    'title' => $title,
                    'status' => 'success',
                    'product_type' => $json['product_type'] ?? null,
                    'ai_category' => $json['ai_category'] ?? null,
                ];
                $successCount++;

                // Small delay to avoid rate limiting
                usleep(200000); // 200ms

            } catch (\Throwable $e) {
                $results[] = [
                    'product_id' => $product->id,
                    'title' => $title,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
                $errorCount++;
            }
        }

        // Get remaining count
        $remainingCount = \App\Models\Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('id', function($query) {
                $query->select('product_id')->from('product_ai_index');
            })
            ->count();

        return response()->json([
            'status' => 'processed',
            'tenant_id' => (int) $tenantId,
            'batch_size' => $batchSize,
            'offset' => $offset,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'remaining' => $remainingCount,
            'processed' => $results,
            'next_offset' => $offset + $batchSize,
        ]);
    }

    /**
     * GET /api/diagnostic/sync-logs
     * Get sync logs for debugging
     */
    public function syncLogs(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $type = $request->input('type'); // horoshop, ai_enrichment, meili_index
        $limit = min(50, max(10, (int) $request->input('limit', 20)));

        $query = DB::table('sync_logs')
            ->orderBy('created_at', 'desc');
        
        if ($type) {
            $query->where('type', $type);
        }

        $logs = $query->limit($limit)->get();

        return response()->json([
            'logs' => $logs,
            'count' => $logs->count(),
        ]);
    }

    /**
     * GET /api/diagnostic/ai-enrich-stats
     * Get AI enrichment statistics per tenant
     */
    public function aiEnrichStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Count products by tenant
        $productsByTenant = DB::table('products')
            ->whereNull('deleted_at')
            ->where('in_stock', true)
            ->select('tenant_id', DB::raw('COUNT(*) as total'))
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        // Count AI indexed products by tenant
        $aiIndexByTenant = DB::table('product_ai_index')
            ->join('products', 'product_ai_index.product_id', '=', 'products.id')
            ->where('products.in_stock', true)
            ->whereNull('products.deleted_at')
            ->select(
                'products.tenant_id',
                DB::raw('COUNT(*) as indexed'),
                DB::raw('SUM(CASE WHEN product_ai_index.keywords IS NOT NULL AND product_ai_index.keywords != "[]" THEN 1 ELSE 0 END) as with_keywords'),
                DB::raw('SUM(CASE WHEN product_ai_index.slang IS NOT NULL AND product_ai_index.slang != "[]" THEN 1 ELSE 0 END) as with_slang'),
                DB::raw('SUM(CASE WHEN product_ai_index.product_type IS NOT NULL AND product_ai_index.product_type != "" THEN 1 ELSE 0 END) as with_type')
            )
            ->groupBy('products.tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $stats = [];
        foreach ($productsByTenant as $tenantId => $data) {
            $ai = $aiIndexByTenant[$tenantId] ?? null;
            $stats[$tenantId] = [
                'total_products' => $data->total,
                'indexed' => $ai->indexed ?? 0,
                'with_keywords' => $ai->with_keywords ?? 0,
                'with_slang' => $ai->with_slang ?? 0,
                'with_type' => $ai->with_type ?? 0,
                'coverage_percent' => $data->total > 0 
                    ? round((($ai->indexed ?? 0) / $data->total) * 100, 1) 
                    : 0,
            ];
        }

        // Sample AI index records - optionally filter by tenant_id
        $tenantIdFilter = $request->input('tenant_id');
        $samplesQuery = DB::table('product_ai_index')
            ->join('products', 'product_ai_index.product_id', '=', 'products.id')
            ->select(
                'product_ai_index.product_id',
                'products.tenant_id',
                'products.title',
                'product_ai_index.product_type',
                'product_ai_index.ai_category',
                'product_ai_index.keywords',
                'product_ai_index.slang',
                'product_ai_index.created_at'
            );
        
        if ($tenantIdFilter) {
            $samplesQuery->where('products.tenant_id', (int) $tenantIdFilter);
        }
        
        $samples = $samplesQuery->limit(10)->get();
        
        // Count products without AI index per tenant
        $missingAiByTenant = DB::table('products')
            ->leftJoin('product_ai_index', 'products.id', '=', 'product_ai_index.product_id')
            ->whereNull('product_ai_index.product_id')
            ->where('products.in_stock', true)
            ->whereNull('products.deleted_at')
            ->select('products.tenant_id', DB::raw('COUNT(*) as missing'))
            ->groupBy('products.tenant_id')
            ->get()
            ->keyBy('tenant_id');

        return response()->json([
            'by_tenant' => $stats,
            'missing_ai_by_tenant' => $missingAiByTenant,
            'total_ai_records' => DB::table('product_ai_index')->count(),
            'samples' => $samples,
        ]);
    }

    /**
     * POST /api/diagnostic/cleanup-meili
     * Remove stale documents from Meilisearch (products deleted from Horoshop or out of stock)
     * Faster than full reindex - only deletes, doesn't re-add
     */
    public function cleanupMeili(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        set_time_limit(120);
        
        try {
            $meili = app(\App\Services\Search\MeiliClient::class);
            $index = $meili->productsIndex();
            
            // Get all IDs from Meilisearch
            $meiliIds = [];
            $limit = 1000;
            $offset = 0;
            
            do {
                $query = (new \Meilisearch\Contracts\DocumentsQuery())
                    ->setLimit($limit)
                    ->setOffset($offset)
                    ->setFields(['id']);
                $result = $index->getDocuments($query);
                $docs = $result->getResults();
                
                if (empty($docs)) {
                    break;
                }
                
                foreach ($docs as $doc) {
                    $meiliIds[] = (int) $doc['id'];
                }
                
                $offset += $limit;
            } while (count($docs) === $limit);
            
            if (empty($meiliIds)) {
                return response()->json([
                    'status' => 'ok',
                    'meili_count' => 0,
                    'deleted' => 0,
                    'message' => 'Meili index is empty',
                ]);
            }
            
            // Get valid IDs from DB (only in_stock=true)
            $validIds = Product::where('in_stock', true)
                ->whereIn('id', $meiliIds)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();
            
            // Find IDs to delete
            $idsToDelete = array_diff($meiliIds, $validIds);
            
            if (empty($idsToDelete)) {
                return response()->json([
                    'status' => 'ok',
                    'meili_count' => count($meiliIds),
                    'valid_count' => count($validIds),
                    'deleted' => 0,
                    'message' => 'No stale documents found',
                ]);
            }
            
            // Delete in chunks
            $deletedCount = 0;
            foreach (array_chunk($idsToDelete, 500) as $chunk) {
                $index->deleteDocuments($chunk);
                $deletedCount += count($chunk);
            }
            
            return response()->json([
                'status' => 'ok',
                'meili_count' => count($meiliIds),
                'valid_count' => count($validIds),
                'deleted' => $deletedCount,
                'sample_deleted_ids' => array_slice($idsToDelete, 0, 10),
                'message' => "Deleted {$deletedCount} stale documents",
            ]);
            
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/diagnostic/cleanup-stale-products
     * Remove products from DB that were not updated in the last sync
     * Useful when Horoshop has fewer products than DB (removed from showcase)
     * Required: tenant_id parameter
     * Optional: minutes (default 30), or cutoff_time (absolute datetime, format: Y-m-d H:i:s)
     */
    public function cleanupStaleProducts(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->query('tenant_id') ? (int) $request->query('tenant_id') : null;
        if (!$tenantId) {
            return response()->json(['error' => 'tenant_id is required'], 400);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $dryRun = $request->query('dry_run', '1') !== '0';
        
        // Allow absolute cutoff time or relative minutes
        $cutoffTimeParam = $request->query('cutoff_time');
        if ($cutoffTimeParam) {
            $cutoffTime = \Carbon\Carbon::parse($cutoffTimeParam);
        } else {
            $cutoffMinutes = (int) $request->query('minutes', 30);
            $cutoffTime = now()->subMinutes($cutoffMinutes);
        }

        try {
            // Find stale products: updated_at < cutoff time
            $staleQuery = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '<', $cutoffTime);
            
            $staleCount = $staleQuery->count();
            $sampleArticles = (clone $staleQuery)->limit(10)->pluck('article')->toArray();
            
            // Current products count
            $currentCount = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->count();
            
            // Recent products count
            $recentCount = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('updated_at', '>=', $cutoffTime)
                ->count();

            if ($dryRun) {
                return response()->json([
                    'status' => 'dry_run',
                    'tenant_id' => $tenantId,
                    'current_products' => $currentCount,
                    'recent_products' => $recentCount,
                    'stale_products' => $staleCount,
                    'would_remain' => $recentCount,
                    'cutoff_time' => $cutoffTime->toDateTimeString(),
                    'sample_stale_articles' => $sampleArticles,
                    'message' => "Would delete {$staleCount} stale products. Add &dry_run=0 to execute.",
                ]);
            }

            // Actually delete
            $deleted = $staleQuery->delete();

            return response()->json([
                'status' => 'completed',
                'tenant_id' => $tenantId,
                'deleted' => $deleted,
                'remaining' => $recentCount,
                'cutoff_time' => $cutoffTime->toDateTimeString(),
                'message' => "Deleted {$deleted} stale products",
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/diagnostic/cleanup-by-api
     * Remove products from DB that don't exist in Horoshop API
     * Fetches all articles from API and deletes DB products not in that list
     * Required: tenant_id parameter
     */
    public function cleanupByApi(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->query('tenant_id') ? (int) $request->query('tenant_id') : null;
        if (!$tenantId) {
            return response()->json(['error' => 'tenant_id is required'], 400);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if ($tenant->platform !== 'horoshop' || empty($tenant->platform_credentials)) {
            return response()->json(['error' => 'Tenant has no Horoshop credentials'], 400);
        }

        $dryRun = $request->query('dry_run', '1') !== '0';
        set_time_limit(300);

        try {
            $credentials = $tenant->platform_credentials;
            $domain = is_array($credentials['domain']) ? ($credentials['domain']['value'] ?? '') : (string) $credentials['domain'];
            $login = is_array($credentials['login']) ? ($credentials['login']['value'] ?? '') : (string) $credentials['login'];
            $password = is_array($credentials['password']) ? ($credentials['password']['value'] ?? '') : (string) $credentials['password'];
            
            $client = new \App\Services\Horoshop\HoroshopClient($domain, $login, $password);
            
            // Fetch all articles from API
            $apiArticles = [];
            $offset = 0;
            $limit = 200;
            
            do {
                $response = $client->request('catalog/export', [
                    'expr' => ['display_in_showcase' => 1],
                    'limit' => $limit,
                    'offset' => $offset,
                    'includedParams' => ['article'],
                ]);
                
                $products = $response['products'] ?? [];
                if (empty($products)) {
                    break;
                }
                
                foreach ($products as $product) {
                    if (!empty($product['article'])) {
                        $apiArticles[] = $product['article'];
                    }
                }
                
                $offset += $limit;
            } while (count($products) === $limit);
            
            // Get DB articles for tenant
            $dbArticles = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->pluck('article')
                ->toArray();
            
            // Find articles to delete (in DB but not in API)
            $articlesToDelete = array_diff($dbArticles, $apiArticles);
            
            if (empty($articlesToDelete)) {
                return response()->json([
                    'status' => 'ok',
                    'tenant_id' => $tenantId,
                    'api_count' => count($apiArticles),
                    'db_count' => count($dbArticles),
                    'to_delete' => 0,
                    'message' => 'No stale products found - DB matches API',
                ]);
            }

            if ($dryRun) {
                return response()->json([
                    'status' => 'dry_run',
                    'tenant_id' => $tenantId,
                    'api_count' => count($apiArticles),
                    'db_count' => count($dbArticles),
                    'to_delete' => count($articlesToDelete),
                    'would_remain' => count($apiArticles),
                    'sample_stale_articles' => array_slice($articlesToDelete, 0, 20),
                    'message' => "Would delete " . count($articlesToDelete) . " stale products. Add &dry_run=0 to execute.",
                ]);
            }

            // Actually delete
            $deleted = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('article', $articlesToDelete)
                ->delete();

            return response()->json([
                'status' => 'completed',
                'tenant_id' => $tenantId,
                'api_count' => count($apiArticles),
                'deleted' => $deleted,
                'remaining' => count($dbArticles) - $deleted,
                'message' => "Deleted {$deleted} stale products",
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/diagnostic/sync-horoshop
     * Trigger full sync from Horoshop (marks deleted products as out of stock)
     * Add ?queue=0 to run synchronously (slow, ~5-10 min)
     */
    public function syncHoroshop(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $useQueue = $request->query('queue', '1') !== '0';
        $tenantId = $request->query('tenant_id') ? (int) $request->query('tenant_id') : null;
        
        // For tenant-specific sync, use SyncHoroshopProductsJob
        if ($tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);
            if (!$tenant) {
                return response()->json(['error' => 'Tenant not found'], 404);
            }
            
            if ($tenant->platform !== 'horoshop' || empty($tenant->platform_credentials)) {
                return response()->json(['error' => 'Tenant has no Horoshop credentials'], 400);
            }
            
            if ($useQueue) {
                \App\Jobs\SyncHoroshopProductsJob::dispatch($tenantId)->onQueue('default');
                return response()->json([
                    'status' => 'dispatched',
                    'message' => "SyncHoroshopProductsJob dispatched for tenant {$tenantId}",
                    'tenant_id' => $tenantId,
                ]);
            }
            
            // Sync synchronously
            try {
                $productService = app(\App\Services\Horoshop\ProductService::class);
                $result = $productService->syncFromHoroshopForTenant($tenant, 200);
                $tenant->update(['last_sync_at' => now()]);
                
                return response()->json([
                    'status' => 'completed',
                    'tenant_id' => $tenantId,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => array_slice($e->getTrace(), 0, 5), // First 5 trace entries
                ], 500);
            }
        }
        
        // Legacy global sync (for backwards compatibility)
        if ($useQueue) {
            // Dispatch to queue
            \App\Jobs\IncrementalProductSyncJob::dispatch()
                ->onQueue('default');
            
            return response()->json([
                'status' => 'dispatched',
                'message' => 'IncrementalProductSyncJob dispatched to queue. Will mark deleted products as out of stock.',
            ]);
        }
        
        // Run synchronously (for debugging)
        set_time_limit(600); // 10 minutes
        
        try {
            $job = new \App\Jobs\IncrementalProductSyncJob();
            $job->handle(
                app(\App\Services\Horoshop\ProductService::class)
            );
            
            $stats = \Illuminate\Support\Facades\Cache::get('incremental_sync_stats', []);
            
            return response()->json([
                'status' => 'completed',
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/diagnostic/tenant-products/{tenantId}
     * Delete all products for a tenant (for reset/cleanup)
     */
    public function deleteTenantProducts(Request $request, int $tenantId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $dryRun = $request->boolean('dry_run', true);
        
        // Count products including soft-deleted (using DB::table to bypass SoftDeletes)
        $productCount = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->count();
        
        if ($dryRun) {
            return response()->json([
                'dry_run' => true,
                'tenant_id' => $tenantId,
                'tenant_name' => $tenant->name,
                'products_to_delete' => $productCount,
                'note' => 'Set dry_run=false to actually delete products',
            ]);
        }
        
        // Delete AI index first (foreign key)
        // Note: Use products table directly to include soft-deleted products
        $aiDeleted = DB::table('product_ai_index')
            ->whereIn('product_id', function ($q) use ($tenantId) {
                $q->select('id')->from('products')->where('tenant_id', $tenantId);
            })
            ->delete();
        
        // Force delete products (bypassing soft delete)
        // DB::table() doesn't respect SoftDeletes, so it will delete all including soft-deleted
        $deleted = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->delete();
        
        // Reset last_sync_at
        $tenant->update(['last_sync_at' => null]);
        
        return response()->json([
            'dry_run' => false,
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant->name,
            'products_deleted' => $deleted,
            'ai_index_deleted' => $aiDeleted,
            'note' => 'Products deleted. You can now run sync to import fresh data.',
        ]);
    }

    /**
     * DELETE /api/diagnostic/tenant/{tenantId}
     * Completely delete a tenant and all its data (for re-onboarding)
     */
    public function deleteTenant(Request $request, int $tenantId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $dryRun = $request->boolean('dry_run', true);

        // Collect all related data counts
        $stats = [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'domain' => $tenant->domain,
            ],
            'products' => DB::table('products')->where('tenant_id', $tenantId)->count(),
            'product_ai_index' => DB::table('product_ai_index')
                ->whereIn('product_id', function ($q) use ($tenantId) {
                    $q->select('id')->from('products')->where('tenant_id', $tenantId);
                })->count(),
            'widget_settings' => DB::table('widget_settings')->where('tenant_id', $tenantId)->count(),
            'chat_sessions' => DB::table('chat_sessions')->where('tenant_id', $tenantId)->count(),
            'chat_messages' => DB::table('chat_messages')
                ->whereIn('chat_session_id', function ($q) use ($tenantId) {
                    $q->select('id')->from('chat_sessions')->where('tenant_id', $tenantId);
                })->count(),
            'proactive_trigger_rules' => DB::table('proactive_trigger_rules')->where('tenant_id', $tenantId)->count(),
            'proactive_trigger_events' => DB::table('proactive_trigger_events')
                ->whereIn('rule_id', function ($q) use ($tenantId) {
                    $q->select('id')->from('proactive_trigger_rules')->where('tenant_id', $tenantId);
                })->count(),
            'prompt_presets' => DB::table('prompt_presets')->where('tenant_id', $tenantId)->count(),
            'greetings' => DB::table('greetings')->where('tenant_id', $tenantId)->count(),
            'sync_logs' => DB::table('sync_logs')->where('tenant_id', $tenantId)->count(),
            'users' => DB::table('users')->where('tenant_id', $tenantId)->count(),
        ];

        if ($dryRun) {
            return response()->json([
                'dry_run' => true,
                'tenant_id' => $tenantId,
                'will_delete' => $stats,
                'note' => '⚠️ Set dry_run=false to PERMANENTLY delete this tenant and ALL its data!',
            ]);
        }

        // Delete in correct order (respect foreign keys)
        $deleted = [];

        // 1. Chat messages (FK to chat_sessions)
        $deleted['chat_messages'] = DB::table('chat_messages')
            ->whereIn('chat_session_id', function ($q) use ($tenantId) {
                $q->select('id')->from('chat_sessions')->where('tenant_id', $tenantId);
            })->delete();

        // 2. Chat sessions
        $deleted['chat_sessions'] = DB::table('chat_sessions')->where('tenant_id', $tenantId)->delete();

        // 3. Proactive trigger events (FK to rules)
        $deleted['proactive_trigger_events'] = DB::table('proactive_trigger_events')
            ->whereIn('rule_id', function ($q) use ($tenantId) {
                $q->select('id')->from('proactive_trigger_rules')->where('tenant_id', $tenantId);
            })->delete();

        // 4. Proactive trigger rules
        $deleted['proactive_trigger_rules'] = DB::table('proactive_trigger_rules')->where('tenant_id', $tenantId)->delete();

        // 5. Product AI index (FK to products)
        $deleted['product_ai_index'] = DB::table('product_ai_index')
            ->whereIn('product_id', function ($q) use ($tenantId) {
                $q->select('id')->from('products')->where('tenant_id', $tenantId);
            })->delete();

        // 6. Products
        $deleted['products'] = DB::table('products')->where('tenant_id', $tenantId)->delete();

        // 7. Widget settings
        $deleted['widget_settings'] = DB::table('widget_settings')->where('tenant_id', $tenantId)->delete();

        // 8. Prompt presets
        $deleted['prompt_presets'] = DB::table('prompt_presets')->where('tenant_id', $tenantId)->delete();

        // 9. Greetings
        $deleted['greetings'] = DB::table('greetings')->where('tenant_id', $tenantId)->delete();

        // 10. Sync logs
        $deleted['sync_logs'] = DB::table('sync_logs')->where('tenant_id', $tenantId)->delete();

        // 11. Users (unlink from tenant, don't delete users)
        $deleted['users_unlinked'] = DB::table('users')->where('tenant_id', $tenantId)->update(['tenant_id' => null]);

        // 12. Finally, delete the tenant itself
        $deleted['tenant'] = DB::table('tenants')->where('id', $tenantId)->delete();

        return response()->json([
            'dry_run' => false,
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant->name,
            'deleted' => $deleted,
            'message' => '🗑️ Tenant and all related data deleted. You can now re-onboard.',
        ]);
    }

    /**
     * POST /api/diagnostic/reset-tenant/{tenantId}
     * Reset tenant data for re-onboarding (keeps tenant, user, widget_settings)
     */
    public function resetTenant(Request $request, int $tenantId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $dryRun = $request->boolean('dry_run', true);

        // Collect all related data counts (excluding tenant, user, widget_settings)
        $stats = [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'domain' => $tenant->domain,
                'note' => '✅ WILL BE KEPT',
            ],
            'products' => DB::table('products')->where('tenant_id', $tenantId)->count(),
            'product_ai_index' => DB::table('product_ai_index')
                ->whereIn('product_id', function ($q) use ($tenantId) {
                    $q->select('id')->from('products')->where('tenant_id', $tenantId);
                })->count(),
            'categories' => DB::table('categories')->where('tenant_id', $tenantId)->count(),
            'brands' => DB::table('brands')->where('tenant_id', $tenantId)->count(),
            'chat_sessions' => DB::table('chat_sessions')->where('tenant_id', $tenantId)->count(),
            'chat_messages' => DB::table('chat_messages')
                ->whereIn('chat_session_id', function ($q) use ($tenantId) {
                    $q->select('id')->from('chat_sessions')->where('tenant_id', $tenantId);
                })->count(),
            'onboarding_progress' => DB::table('tenant_onboarding_progress')->where('tenant_id', $tenantId)->count(),
            'sync_logs' => DB::table('sync_logs')->where('tenant_id', $tenantId)->count(),
            'will_keep' => [
                'tenant' => '✅',
                'users' => DB::table('users')->where('tenant_id', $tenantId)->count(),
                'widget_settings' => DB::table('widget_settings')->where('tenant_id', $tenantId)->count(),
            ],
        ];

        if ($dryRun) {
            return response()->json([
                'dry_run' => true,
                'tenant_id' => $tenantId,
                'will_delete' => $stats,
                'note' => '⚠️ Set dry_run=false to reset tenant data. Tenant/user/widget will be KEPT.',
            ]);
        }

        // Delete in correct order (respect foreign keys)
        $deleted = [];

        // 1. Chat messages (FK to chat_sessions)
        $deleted['chat_messages'] = DB::table('chat_messages')
            ->whereIn('chat_session_id', function ($q) use ($tenantId) {
                $q->select('id')->from('chat_sessions')->where('tenant_id', $tenantId);
            })->delete();

        // 2. Chat sessions
        $deleted['chat_sessions'] = DB::table('chat_sessions')->where('tenant_id', $tenantId)->delete();

        // 3. Product AI index (FK to products)
        $deleted['product_ai_index'] = DB::table('product_ai_index')
            ->whereIn('product_id', function ($q) use ($tenantId) {
                $q->select('id')->from('products')->where('tenant_id', $tenantId);
            })->delete();

        // 4. Products
        $deleted['products'] = DB::table('products')->where('tenant_id', $tenantId)->delete();

        // 5. Categories
        $deleted['categories'] = DB::table('categories')->where('tenant_id', $tenantId)->delete();

        // 6. Brands
        $deleted['brands'] = DB::table('brands')->where('tenant_id', $tenantId)->delete();

        // 7. Onboarding progress
        $deleted['onboarding_progress'] = DB::table('tenant_onboarding_progress')->where('tenant_id', $tenantId)->delete();

        // 8. Sync logs
        $deleted['sync_logs'] = DB::table('sync_logs')->where('tenant_id', $tenantId)->delete();

        // 9. Reset tenant fields for re-onboarding (KEEP platform_credentials for re-sync!)
        $tenant->update([
            'last_sync_at' => null,
            'onboarding_completed_at' => null,
            // Keep platform and platform_credentials so re-onboarding can sync!
        ]);

        // 10. Delete from Meilisearch
        try {
            $client = new \Meilisearch\Client(
                config('meilisearch.host'),
                config('meilisearch.key')
            );
            $index = $client->index(config('meilisearch.index', 'products'));
            // Delete all docs for this tenant
            $index->deleteDocuments(['filter' => "tenant_id = {$tenantId}"]);
            $deleted['meilisearch'] = 'Deleted docs with tenant_id=' . $tenantId;
        } catch (\Throwable $e) {
            $deleted['meilisearch_error'] = $e->getMessage();
        }

        return response()->json([
            'dry_run' => false,
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant->name,
            'deleted' => $deleted,
            'kept' => ['tenant', 'users', 'widget_settings'],
            'message' => '🔄 Tenant reset complete! Ready for re-onboarding.',
        ]);
    }

    /**
     * POST /api/diagnostic/dispatch-onboard/{tenantId}
     * Dispatch OnboardTenantJob for a tenant (useful after reset)
     */
    public function dispatchOnboard(Request $request, int $tenantId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check if credentials exist
        if (empty($tenant->platform_credentials)) {
            return response()->json([
                'error' => 'Tenant has no platform_credentials configured',
                'tenant_id' => $tenantId,
                'platform' => $tenant->platform,
            ], 400);
        }

        // Dispatch the job
        \App\Jobs\OnboardTenantJob::dispatch($tenantId)->onQueue('default');

        return response()->json([
            'success' => true,
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant->name,
            'platform' => $tenant->platform,
            'message' => '🚀 OnboardTenantJob dispatched! Check onboarding progress.',
        ]);
    }

    /**
     * GET /api/diagnostic/chat-sessions
     * List recent chat sessions
     */
    public function chatSessions(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $limit = min((int) $request->query('limit', 20), 100);
        $tenantId = $request->query('tenant_id');
        $showNullTenant = $request->boolean('null_tenant');
        
        // Build query without TenantScope to see all sessions for diagnostics
        $query = \App\Models\ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->orderByDesc('updated_at');
        
        if ($showNullTenant) {
            $query->whereNull('tenant_id');
        } elseif ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $sessions = $query->take($limit)
            ->get()
            ->map(fn($s) => [
                'session_id' => $s->session_id,
                'tenant_id' => $s->tenant_id,
                'messages_count' => $s->messages_count,
                'status' => $s->status,
                'last_intent' => $s->last_intent,
                'last_message_at' => $s->last_message_at?->format('Y-m-d H:i:s'),
                'updated_at' => $s->updated_at?->format('Y-m-d H:i:s'),
            ]);
        
        // Count sessions by tenant_id for diagnostics
        $tenantStats = \App\Models\ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->selectRaw('tenant_id, COUNT(*) as count')
            ->groupBy('tenant_id')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->tenant_id ?? 'NULL' => $row->count
            ]);

        return response()->json([
            'count' => $sessions->count(),
            'tenant_stats' => $tenantStats,
            'sessions' => $sessions,
        ]);
    }

    /**
     * GET /api/diagnostic/chat-history/{sessionId}
     * View chat history for debugging context issues
     */
    public function chatHistory(Request $request, string $sessionId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Bypass TenantScope to allow diagnostic access to all sessions
        $session = \App\Models\ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('session_id', $sessionId)->first();
        
        if (!$session) {
            return response()->json([
                'error' => 'Session not found',
                'session_id' => $sessionId,
            ], 404);
        }

        $fullContent = $request->boolean('full');

        // Bypass TenantScope for diagnostic access
        $messages = \App\Models\ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('chat_session_id', $session->id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($msg) use ($fullContent) {
                $content = $msg->content;
                if (!$fullContent) {
                    $content = mb_substr($content, 0, 500) . (mb_strlen($content) > 500 ? '...' : '');
                }
                
                $meta = $msg->meta ?? [];
                
                return [
                    'id' => $msg->id,
                    'role' => $msg->role,
                    'content' => $content,
                    'meta' => $meta, // Return full meta for debugging
                    'intent' => $meta['intent'] ?? null,
                    'products_shown' => $meta['products_shown'] ?? null,
                    'product_ids' => $meta['product_ids'] ?? null,
                    'product_articles' => $meta['product_articles'] ?? null,
                    'has_product_details' => !empty($meta['product_details']),
                    'product_details_count' => count($meta['product_details'] ?? []),
                    'created_at' => $msg->created_at->format('Y-m-d H:i:s'),
                ];
            });

        // Get unique tenant_ids from messages for debugging
        $messageTenantIds = \App\Models\ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('chat_session_id', $session->id)
            ->pluck('tenant_id')
            ->unique()
            ->values();

        return response()->json([
            'session_id' => $sessionId,
            'db_id' => $session->id,
            'session_tenant_id' => $session->tenant_id,
            'message_tenant_ids' => $messageTenantIds,
            'messages_count' => $messages->count(),
            'status' => $session->status,
            'created_at' => $session->created_at->format('Y-m-d H:i:s'),
            'last_message_at' => $session->last_message_at?->format('Y-m-d H:i:s'),
            'messages' => $messages,
        ]);
    }

    /**
     * POST /api/diagnostic/sync-faq
     * Sync FAQ content from URLs
     */
    public function syncFaq(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $settings = \App\Models\WidgetSettings::first();
        if (!$settings) {
            return response()->json(['error' => 'No WidgetSettings found'], 404);
        }

        // Set default URLs if empty
        if (empty($settings->faq_payment_delivery_url)) {
            $settings->faq_payment_delivery_url = 'https://contractor.kiev.ua/oplata-i-dostavka/';
        }
        if (empty($settings->faq_contacts_url)) {
            $settings->faq_contacts_url = 'https://contractor.kiev.ua/kontaktna-informatsiya/';
        }
        if (empty($settings->faq_about_url)) {
            $settings->faq_about_url = 'https://contractor.kiev.ua/pro-nas/';
        }
        $settings->save();

        // Ingest content from URLs
        try {
            $ingestService = app(\App\Services\Support\FaqContentIngestService::class);
            $ingestService->ingest($settings);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Ingest failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        $settings->refresh();

        // Set returns text manually if still empty
        if (empty($settings->faq_returns_text)) {
            $settings->faq_returns_text = "Повернення та обмін\n\nМи приймаємо повернення та обмін товарів протягом 14 днів з моменту отримання.\n\nУмови повернення:\n• Товар має бути в оригінальній упаковці\n• Товар не використовувався\n• Наявність чеку або підтвердження замовлення\n\nДля оформлення повернення зверніться до нас:\n• Телефон: +380 63 631 9919\n• Telegram: @sturmtig\n• Email: vigser2@gmail.com";
            $settings->save();
        }

        // Clear cache
        \Illuminate\Support\Facades\Cache::forget('widget_settings_faq');

        return response()->json([
            'success' => true,
            'faq_lengths' => [
                'faq_payment_delivery_text' => strlen($settings->faq_payment_delivery_text ?? ''),
                'faq_returns_text' => strlen($settings->faq_returns_text ?? ''),
                'faq_contacts_text' => strlen($settings->faq_contacts_text ?? ''),
                'faq_about_text' => strlen($settings->faq_about_text ?? ''),
            ],
        ]);
    }

    /**
     * GET /api/diagnostic/widget-settings
     * Get widget settings (FAQ texts)
     */
    public function widgetSettings(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Get all widget settings for all tenants
        $allSettings = \App\Models\WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)->get();
        
        if ($allSettings->isEmpty()) {
            return response()->json(['error' => 'No WidgetSettings found'], 404);
        }

        $settingsList = $allSettings->map(function ($settings) {
            $tenant = $settings->tenant_id 
                ? \App\Models\Tenant::find($settings->tenant_id) 
                : null;
            
            return [
                'id' => $settings->id,
                'tenant_id' => $settings->tenant_id,
                'tenant_name' => $tenant?->name,
                'tenant_slug' => $tenant?->slug,
                'api_token' => $settings->api_token,
                'api_token_preview' => $settings->api_token 
                    ? substr($settings->api_token, 0, 8) . '...' . substr($settings->api_token, -8) 
                    : null,
                'store_name' => $settings->store_name,
                'domain' => $settings->domain,
                'enabled' => $settings->enabled,
                // FAQ texts for debugging
                'faq_texts' => [
                    'faq_payment_delivery' => [
                        'url' => $settings->faq_payment_delivery_url,
                        'text_length' => strlen($settings->faq_payment_delivery_text ?? ''),
                        'text_preview' => mb_substr($settings->faq_payment_delivery_text ?? '', 0, 200),
                    ],
                    'faq_returns' => [
                        'url' => $settings->faq_returns_url,
                        'text_length' => strlen($settings->faq_returns_text ?? ''),
                        'text_preview' => mb_substr($settings->faq_returns_text ?? '', 0, 200),
                    ],
                    'faq_contacts' => [
                        'url' => $settings->faq_contacts_url,
                        'text_length' => strlen($settings->faq_contacts_text ?? ''),
                        'text_preview' => mb_substr($settings->faq_contacts_text ?? '', 0, 200),
                    ],
                    'faq_about' => [
                        'url' => $settings->faq_about_url,
                        'text_length' => strlen($settings->faq_about_text ?? ''),
                        'text_preview' => mb_substr($settings->faq_about_text ?? '', 0, 200),
                    ],
                ],
            ];
        });

        return response()->json([
            'count' => $settingsList->count(),
            'settings' => $settingsList,
        ]);
    }

    /**
     * POST /api/diagnostic/clear-tone-cache
     * Clear ToneService cache for a tenant
     */
    public function clearToneCache(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->input('tenant_id');
        $cleared = [];

        // Clear specific tenant cache
        if ($tenantId) {
            $key = 'widget_settings_tone:' . $tenantId;
            \Illuminate\Support\Facades\Cache::forget($key);
            $cleared[] = $key;
        }

        // Always clear global cache
        $globalKey = 'widget_settings_tone:global';
        \Illuminate\Support\Facades\Cache::forget($globalKey);
        $cleared[] = $globalKey;

        // Get fresh settings to verify
        $settings = null;
        if ($tenantId) {
            $settings = \App\Models\WidgetSettings::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->first();
        }

        // Test ToneService output
        $toneService = app(\App\Services\Ai\ToneService::class);
        if ($tenantId) {
            $toneService->setTenantId((int)$tenantId);
        }
        $fullPromptSection = $toneService->getFullPromptSection();
        $brandRulesPrompt = $toneService->getBrandRulesPrompt();

        return response()->json([
            'success' => true,
            'cleared_keys' => $cleared,
            'tenant_id' => $tenantId,
            'settings' => $settings ? [
                'id' => $settings->id,
                'tone' => $settings->tone,
                'brand_rules' => $settings->brand_rules,
            ] : null,
            'tone_service_output' => [
                'full_prompt_section' => mb_substr($fullPromptSection, 0, 500),
                'brand_rules_prompt' => $brandRulesPrompt,
            ],
        ]);
    }

    /**
     * GET /api/diagnostic/tenant-context
     * Debug TenantContext resolution
     */
    public function tenantContext(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $context = app(\App\Services\Tenant\TenantContext::class);
        $searchTool = app(\App\Services\Agent\Tools\MeiliProductSearchTool::class);
        
        return response()->json([
            'request' => [
                'has_tenant_id' => $request->has('tenant_id'),
                'tenant_id' => $request->input('tenant_id'),
                'has_tenant' => $request->has('tenant'),
                'tenant' => $request->input('tenant'),
                'all_input' => $request->all(),
            ],
            'tenant_context' => [
                'tenant_id' => $context->getTenantId(),
                'merchant_id' => $context->getMerchantId(),
                'has_tenant' => $context->hasTenant(),
            ],
            'search_tool' => [
                'current_tenant_id' => $searchTool->getCurrentTenantId(),
            ],
            'app_bound' => [
                'current_tenant' => app()->bound('current_tenant'),
                'current_tenant_id' => app()->bound('current_tenant') ? app('current_tenant')?->id : null,
            ],
        ]);
    }
    
    /**
     * POST /api/diagnostic/sync-orders
     * Sync orders from Horoshop and update orders_count
     */
    public function syncOrders(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $days = (int) $request->input('days', 90);
        
        try {
            // Run the sync command
            \Illuminate\Support\Facades\Artisan::call('orders:sync', [
                '--days' => $days,
                '--update-counts' => true,
                '--timeout' => 300,
            ]);
            
            $output = \Illuminate\Support\Facades\Artisan::output();
            
            // Get stats after sync
            $ordersCount = \App\Models\Order::count();
            $itemsCount = \App\Models\OrderItem::count();
            $productsWithOrders = \App\Models\Product::where('orders_count', '>', 0)->count();
            $topProducts = \App\Models\Product::where('orders_count', '>', 0)
                ->orderBy('orders_count', 'desc')
                ->take(10)
                ->get(['article', 'title', 'orders_count']);
            
            return response()->json([
                'success' => true,
                'days_synced' => $days,
                'orders_count' => $ordersCount,
                'items_count' => $itemsCount,
                'products_with_orders' => $productsWithOrders,
                'top_10_products' => $topProducts,
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/diagnostic/backfill-checkout-events
     * Create checkout_success events for existing orders that don't have them
     */
    public function backfillCheckoutEvents(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $dryRun = $request->boolean('dry_run', false);
            $stats = [
                'total_orders' => 0,
                'orders_with_session' => 0,
                'already_has_event' => 0,
                'events_created' => 0,
                'skipped_cancelled' => 0,
            ];

            // Get orders with session_id (can be linked to chat)
            $orders = \App\Models\Order::whereNotNull('session_id')
                ->where('session_id', '!=', '')
                ->get();

            $stats['total_orders'] = \App\Models\Order::count();
            $stats['orders_with_session'] = $orders->count();

            foreach ($orders as $order) {
                // Skip cancelled orders (stat_status = 5)
                $rawData = is_array($order->raw) ? $order->raw : (is_string($order->raw) ? json_decode($order->raw, true) : []);
                if (($rawData['stat_status'] ?? $order->status_code) == 5) {
                    $stats['skipped_cancelled']++;
                    continue;
                }

                // Check if checkout_success event already exists
                $existingEvent = DB::table('chat_events')
                    ->where('event_type', 'checkout_success')
                    ->where('session_id', $order->session_id)
                    ->where('metadata', 'like', '%"order_id":' . $order->order_id . '%')
                    ->exists();

                if ($existingEvent) {
                    $stats['already_has_event']++;
                    continue;
                }

                // Determine merchant_id from session
                $merchantId = null;
                $chatSession = DB::table('chat_sessions')
                    ->where('session_id', $order->session_id)
                    ->first();
                
                if ($chatSession) {
                    $tenant = \App\Models\Tenant::find($chatSession->tenant_id);
                    $merchantId = $tenant?->slug ?? $tenant?->widgetSettings?->api_token;
                }

                if (!$dryRun) {
                    DB::table('chat_events')->insert([
                        'session_id' => $order->session_id,
                        'merchant_id' => $merchantId,
                        'event_type' => 'checkout_success',
                        'product_id' => null,
                        'product_article' => null,
                        'product_price' => $order->total_sum,
                        'metadata' => json_encode([
                            'order_id' => $order->order_id,
                            'total_sum' => $order->total_sum,
                            'items_count' => $order->total_quantity,
                            'had_chat' => $order->had_chat,
                            'products_from_chat' => $order->products_from_chat,
                            'source' => 'backfill',
                        ]),
                        'created_at' => $order->ordered_at ?? $order->created_at,
                    ]);
                }
                $stats['events_created']++;
            }

            return response()->json([
                'success' => true,
                'dry_run' => $dryRun,
                'stats' => $stats,
                'message' => $dryRun 
                    ? "Dry run complete. Would create {$stats['events_created']} events."
                    : "Backfill complete. Created {$stats['events_created']} checkout_success events.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
    
    /**
     * GET /api/diagnostic/orders-stats
     * Get orders statistics
     */
    public function ordersStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $ordersCount = \App\Models\Order::count();
        $itemsCount = \App\Models\OrderItem::count();
        $productsWithOrders = \App\Models\Product::where('orders_count', '>', 0)->count();
        
        $topProducts = \App\Models\Product::where('orders_count', '>', 0)
            ->where('in_stock', true)
            ->orderBy('orders_count', 'desc')
            ->take(20)
            ->get(['article', 'title', 'orders_count', 'price', 'category_path']);
        
        return response()->json([
            'orders_count' => $ordersCount,
            'items_count' => $itemsCount,
            'products_with_orders' => $productsWithOrders,
            'top_20_products' => $topProducts,
        ]);
    }
    
    /**
     * GET /api/diagnostic/ai-index-stats
     * Get AI enrichment statistics and quality score
     */
    public function aiIndexStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $qualityService = app(\App\Services\Ai\EnrichmentQualityService::class);
        $quality = $qualityService->getOverallScore();
        $recommendations = $qualityService->getRecommendations();

        $totalProducts = \App\Models\Product::count();
        $inStockProducts = \App\Models\Product::where('in_stock', true)->count();
        $showcaseProducts = \App\Models\Product::where('display_in_showcase', true)->count();
        
        // Products without AI index
        $withoutAiIndex = \App\Models\Product::where('display_in_showcase', true)
            ->whereDoesntHave('aiIndex')
            ->count();
        
        // Top product types
        $topProductTypes = \App\Models\ProductAiIndex::selectRaw('product_type, COUNT(*) as cnt')
            ->whereNotNull('product_type')
            ->where('product_type', '!=', '')
            ->groupBy('product_type')
            ->orderByDesc('cnt')
            ->take(15)
            ->get();
        
        // Products needing slang enrichment
        $missingSlangCount = \App\Models\Product::where('display_in_showcase', true)
            ->whereHas('aiIndex', function ($ai) {
                $ai->where(function ($q) {
                    $q->whereNull('slang')
                      ->orWhere('slang', '[]')
                      ->orWhere('slang', 'null')
                      ->orWhereRaw("JSON_LENGTH(slang) = 0");
                });
            })
            ->count();
        
        return response()->json([
            'quality_score' => $quality['score'],
            'quality_grade' => $quality['grade'],
            'total_products' => $totalProducts,
            'in_stock_products' => $inStockProducts,
            'showcase_products' => $showcaseProducts,
            'ai_index' => $quality['stats'],
            'missing_ai_index' => $withoutAiIndex,
            'missing_slang' => $missingSlangCount,
            'top_product_types' => $topProductTypes,
            'recommendations' => $recommendations,
        ]);
    }
    
    /**
     * GET /api/diagnostic/ai-index-problems
     * Get detailed problems with AI index quality
     */
    public function aiIndexProblems(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $limit = (int) $request->input('limit', 50);
        
        $qualityService = app(\App\Services\Ai\EnrichmentQualityService::class);
        $problems = $qualityService->findProblems($limit);
        $recommendations = $qualityService->getRecommendations();
        
        return response()->json([
            'problems' => $problems,
            'total_issues' => array_sum(array_column($problems, 'count')),
            'recommendations' => $recommendations,
        ]);
    }
    
    /**
     * POST /api/diagnostic/run-enrichment
     * Run AI enrichment for products
     * 
     * Modes:
     * - missing: products without AI index (default)
     * - incomplete: products with empty product_type
     * - missing_slang: products with empty slang
     * 
     * Set async=1 to dispatch as queue job (recommended for large batches)
     */
    public function runEnrichment(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $limit = (int) $request->input('limit', 100);
        $timeout = (int) $request->input('timeout', 300);
        $noAi = (bool) $request->input('no_ai', false);
        $async = (bool) $request->input('async', false);
        $mode = $request->input('mode', 'missing'); // missing, incomplete, missing_slang
        
        // Build options based on mode
        $options = [
            '--limit' => $limit,
            '--timeout' => $timeout,
            '--batch' => 10,
        ];
        
        switch ($mode) {
            case 'missing_slang':
                $options['--missing-slang'] = true;
                break;
            case 'incomplete':
                $options['--incomplete'] = true;
                break;
            case 'missing':
            default:
                $options['--only-missing'] = true;
                break;
        }
        
        if ($noAi) {
            $options['--no-ai'] = true;
        }
        
        try {
            if ($async) {
                // Dispatch as queue job to avoid HTTP timeout
                dispatch(function () use ($options) {
                    \Illuminate\Support\Facades\Artisan::call('products:build-ai-index', $options);
                })->onQueue('default');
                
                return response()->json([
                    'success' => true,
                    'async' => true,
                    'message' => 'Enrichment job dispatched to queue',
                    'mode' => $mode,
                    'limit' => $limit,
                    'timeout' => $timeout,
                ]);
            }
            
            // Sync execution (will timeout on large batches)
            \Illuminate\Support\Facades\Artisan::call('products:build-ai-index', $options);
            
            $output = \Illuminate\Support\Facades\Artisan::output();
            
            // Get updated stats
            $totalAiIndex = \App\Models\ProductAiIndex::count();
            $withProductType = \App\Models\ProductAiIndex::whereNotNull('product_type')
                ->where('product_type', '!=', '')
                ->count();
            $withSlang = \App\Models\ProductAiIndex::whereNotNull('slang')
                ->whereRaw("JSON_LENGTH(slang) > 0")
                ->count();
            
            return response()->json([
                'success' => true,
                'mode' => $mode,
                'limit' => $limit,
                'timeout' => $timeout,
                'no_ai' => $noAi,
                'ai_index_count' => $totalAiIndex,
                'with_product_type' => $withProductType,
                'with_slang' => $withSlang,
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * GET /api/diagnostic/slang-stats?type=plate_carrier
     * Get slang statistics by product type
     */
    public function slangStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $productType = $request->query('type');
        
        $query = \App\Models\ProductAiIndex::query();
        
        if ($productType) {
            $query->where('product_type', $productType);
        }
        
        $records = $query->whereNotNull('slang')
            ->whereRaw("JSON_LENGTH(slang) > 0")
            ->with('product:id,title,category_path')
            ->take(20)
            ->get(['id', 'product_id', 'product_type', 'slang', 'keywords']);
        
        // Aggregate all slang terms
        $allSlang = [];
        foreach ($records as $r) {
            $slangArray = is_array($r->slang) ? $r->slang : [];
            foreach ($slangArray as $term) {
                $term = mb_strtolower(trim($term));
                if ($term) {
                    $allSlang[$term] = ($allSlang[$term] ?? 0) + 1;
                }
            }
        }
        arsort($allSlang);
        
        // Products without slang for this type
        $withoutSlang = \App\Models\ProductAiIndex::query()
            ->when($productType, fn($q) => $q->where('product_type', $productType))
            ->where(function($q) {
                $q->whereNull('slang')
                  ->orWhereRaw("JSON_LENGTH(slang) = 0");
            })
            ->with('product:id,title')
            ->take(10)
            ->get(['id', 'product_id', 'product_type']);
        
        return response()->json([
            'product_type' => $productType ?? 'all',
            'with_slang_count' => $records->count(),
            'slang_terms' => array_slice($allSlang, 0, 30, true),
            'examples' => $records->take(5)->map(fn($r) => [
                'title' => $r->product->title ?? 'N/A',
                'slang' => $r->slang,
            ]),
            'without_slang' => $withoutSlang->map(fn($r) => [
                'title' => $r->product->title ?? 'N/A',
                'product_type' => $r->product_type,
            ]),
        ]);
    }
    
    /**
     * POST /api/diagnostic/enrich-one
     * Enrich a single product with AI (fast, avoids timeout)
     * 
     * Pass product_id=123 to enrich specific product
     * Or mode=missing_slang to enrich next product without slang
     */
    public function enrichOne(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $productId = $request->input('product_id');
        $mode = $request->input('mode', 'missing_slang');
        
        // Find product to enrich
        $product = null;
        
        if ($productId) {
            $product = \App\Models\Product::find($productId);
        } else {
            // Find next product based on mode
            $query = \App\Models\Product::where('display_in_showcase', true);
            
            if ($mode === 'missing_slang') {
                $query->whereHas('aiIndex', function ($ai) {
                    $ai->where(function ($q) {
                        $q->whereNull('slang')
                          ->orWhere('slang', '[]')
                          ->orWhere('slang', 'null')
                          ->orWhereRaw("JSON_LENGTH(slang) = 0");
                    });
                });
            } elseif ($mode === 'missing') {
                $query->whereDoesntHave('aiIndex');
            }
            
            $product = $query->orderBy('id')->first();
        }
        
        if (!$product) {
            return response()->json([
                'success' => true,
                'message' => 'No products to enrich',
                'remaining' => 0,
            ]);
        }
        
        try {
            /** @var \App\Services\Ai\ProductIndexBuilder $builder */
            $builder = app(\App\Services\Ai\ProductIndexBuilder::class);
            $result = $builder->buildForProduct($product);
            
            // Count remaining
            $remaining = \App\Models\Product::where('display_in_showcase', true)
                ->whereHas('aiIndex', function ($ai) {
                    $ai->where(function ($q) {
                        $q->whereNull('slang')
                          ->orWhere('slang', '[]')
                          ->orWhere('slang', 'null')
                          ->orWhereRaw("JSON_LENGTH(slang) = 0");
                    });
                })
                ->count();
            
            // Get model being used
            $analyzeModel = config('services.openai.model_analyze') ?: config('services.openai.model');
            
            return response()->json([
                'success' => true,
                'product_id' => $product->id,
                'title' => $product->title,
                'model_used' => $analyzeModel,
                'slang' => $result->slang ?? [],
                'product_type' => $result->product_type,
                'keywords' => $result->keywords ?? [],
                'raw_ai_json' => $result->raw_ai_json,
                'remaining' => $remaining,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'product_id' => $product->id,
            ], 500);
        }
    }
    
    /**
     * GET /api/diagnostic/embedding-stats
     * Get statistics about embeddings
     */
    public function embeddingStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $totalAiIndex = \App\Models\ProductAiIndex::count();
        
        // Count embeddings - embedding field is JSON array
        $withEmbedding = \App\Models\ProductAiIndex::whereNotNull('embedding')
            ->where('embedding', '!=', '[]')
            ->where('embedding', '!=', 'null')
            ->count();
        
        $withoutEmbedding = $totalAiIndex - $withEmbedding;
        
        // Sample of products with embeddings
        $samplesWithEmbedding = \App\Models\ProductAiIndex::whereNotNull('embedding')
            ->where('embedding', '!=', '[]')
            ->with('product:id,title,article')
            ->take(5)
            ->get()
            ->map(fn($ai) => [
                'product_id' => $ai->product_id,
                'title' => $ai->product->title ?? 'Unknown',
                'embedding_length' => is_array($ai->embedding) ? count($ai->embedding) : 0,
            ]);
        
        // Check embedding service availability
        $embeddingService = app(\App\Services\Ai\EmbeddingService::class);
        $serviceAvailable = $embeddingService->isAvailable();
        
        return response()->json([
            'total_ai_index' => $totalAiIndex,
            'with_embedding' => $withEmbedding,
            'without_embedding' => $withoutEmbedding,
            'coverage_percent' => $totalAiIndex > 0 
                ? round(($withEmbedding / $totalAiIndex) * 100, 1) 
                : 0,
            'service_available' => $serviceAvailable,
            'samples_with_embedding' => $samplesWithEmbedding,
        ]);
    }
    
    /**
     * POST /api/diagnostic/generate-embeddings
     * Generate embeddings for products (sync, limited batch for HTTP timeout)
     */
    public function generateEmbeddings(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $limit = min((int) $request->input('limit', 50), 100); // Max 100 per request
        $batchSize = min((int) $request->input('batch', 20), 50);
        
        $embeddingService = app(\App\Services\Ai\EmbeddingService::class);
        
        if (!$embeddingService->isAvailable()) {
            return response()->json([
                'success' => false,
                'error' => 'Embedding service not available (check OPENAI_API_KEY)',
            ], 500);
        }
        
        $startTime = microtime(true);
        $processed = 0;
        $success = 0;
        $failed = 0;
        
        // Get products without embeddings
        $aiIndexes = \App\Models\ProductAiIndex::whereNull('embedding')
            ->orWhere('embedding', '[]')
            ->with('product')
            ->limit($limit)
            ->get();
        
        $totalNeeding = \App\Models\ProductAiIndex::whereNull('embedding')
            ->orWhere('embedding', '[]')
            ->count();
        
        foreach ($aiIndexes->chunk($batchSize) as $chunk) {
            $texts = [];
            $indexMap = [];
            
            foreach ($chunk as $aiIndex) {
                $product = $aiIndex->product;
                if (!$product) {
                    $failed++;
                    continue;
                }
                
                $text = $embeddingService->buildProductText([
                    'title' => $product->title,
                    'category_path' => $product->category_path,
                    'brand' => $product->brand,
                    'keywords' => $aiIndex->keywords ?? [],
                    'slang' => $aiIndex->slang ?? [],
                ]);
                
                if (!empty($text)) {
                    $texts[] = $text;
                    $indexMap[count($texts) - 1] = $aiIndex;
                }
            }
            
            if (empty($texts)) {
                continue;
            }
            
            // Batch embed
            $embeddings = $embeddingService->embedBatch($texts);
            
            // Save embeddings
            foreach ($embeddings as $i => $embedding) {
                $aiIndex = $indexMap[$i] ?? null;
                if (!$aiIndex) continue;
                
                $processed++;
                
                if ($embedding && is_array($embedding)) {
                    $aiIndex->embedding = $embedding;
                    $aiIndex->save();
                    $success++;
                } else {
                    $failed++;
                }
            }
        }
        
        $elapsed = round(microtime(true) - $startTime, 2);
        $remaining = $totalNeeding - $success;
        
        return response()->json([
            'success' => true,
            'processed' => $processed,
            'success_count' => $success,
            'failed_count' => $failed,
            'elapsed_seconds' => $elapsed,
            'remaining' => $remaining,
            'message' => $remaining > 0 
                ? "Generated {$success} embeddings. {$remaining} remaining. Run again to continue."
                : "All embeddings generated!",
        ]);
    }
    
    /**
     * GET /api/diagnostic/ab-test-stats
     * Get A/B testing statistics
     */
    public function abTestStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $experiment = $request->input('experiment', 'search_ai_features');
        
        $abService = app(\App\Services\Analytics\ABTestingService::class);
        $stats = $abService->getStats($experiment);
        
        return response()->json($stats);
    }
    
    /**
     * POST /api/diagnostic/ab-test-reset
     * Reset A/B test data (for testing)
     */
    public function abTestReset(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $experiment = $request->input('experiment', 'search_ai_features');
        
        $abService = app(\App\Services\Analytics\ABTestingService::class);
        $abService->resetExperiment($experiment);
        
        return response()->json([
            'success' => true,
            'message' => "Experiment '{$experiment}' data reset",
        ]);
    }
    
    /**
     * GET /api/diagnostic/ab-test-variant
     * Get variant for a specific session
     */
    public function abTestVariant(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $sessionId = $request->input('session_id');
        if (!$sessionId) {
            return response()->json(['error' => 'session_id required'], 400);
        }
        
        $experiment = $request->input('experiment', 'search_ai_features');
        
        $abService = app(\App\Services\Analytics\ABTestingService::class);
        $variant = $abService->getVariant($sessionId, $experiment);
        $features = $abService->getFeatures($sessionId, $experiment);
        
        return response()->json([
            'session_id' => $sessionId,
            'experiment' => $experiment,
            'variant' => $variant,
            'features' => $features,
        ]);
    }
    
    /**
     * POST /api/diagnostic/ab-test-force
     * Force a specific variant for a session (for testing)
     */
    public function abTestForce(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $sessionId = $request->input('session_id');
        $variant = $request->input('variant');
        
        if (!$sessionId || !$variant) {
            return response()->json(['error' => 'session_id and variant required'], 400);
        }
        
        $experiment = $request->input('experiment', 'search_ai_features');
        
        $abService = app(\App\Services\Analytics\ABTestingService::class);
        $abService->forceVariant($sessionId, $variant, $experiment);
        
        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'variant' => $variant,
            'experiment' => $experiment,
        ]);
    }
    
    /**
     * POST /api/diagnostic/clear-product-shown
     * Clear all product_shown events (to fix inflated stats from bug)
     */
    public function clearProductShown(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Get counts before deletion
        $totalEvents = DB::table('chat_events')->count();
        $productShownCount = DB::table('chat_events')
            ->where('event_type', 'product_shown')
            ->count();
        
        // Delete all product_shown events
        $deleted = DB::table('chat_events')
            ->where('event_type', 'product_shown')
            ->delete();
        
        return response()->json([
            'success' => true,
            'total_events_before' => $totalEvents,
            'product_shown_deleted' => $deleted,
            'total_events_after' => DB::table('chat_events')->count(),
            'message' => "Deleted {$deleted} product_shown events",
        ]);
    }
    
    /**
     * POST /api/diagnostic/reset-views-count
     * Reset views_count for all products (to recalculate from clean events)
     */
    public function resetViewsCount(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Count products with views > 0 before reset
        $productsWithViews = DB::table('products')
            ->where('views_count', '>', 0)
            ->count();
        
        $totalViews = DB::table('products')
            ->sum('views_count');
        
        // Reset all views_count to 0
        $affected = DB::table('products')
            ->update(['views_count' => 0]);
        
        return response()->json([
            'success' => true,
            'products_with_views_before' => $productsWithViews,
            'total_views_before' => $totalViews,
            'products_reset' => $affected,
            'message' => "Reset views_count to 0 for {$affected} products",
        ]);
    }
    
    /**
     * GET /api/diagnostic/chat-events-stats
     * Get statistics about chat_events table
     */
    public function chatEventsStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $stats = DB::table('chat_events')
            ->selectRaw('event_type, COUNT(*) as count')
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->get();
        
        $total = DB::table('chat_events')->count();
        $uniqueSessions = DB::table('chat_events')->distinct('session_id')->count('session_id');
        
        return response()->json([
            'total_events' => $total,
            'unique_sessions' => $uniqueSessions,
            'by_event_type' => $stats,
        ]);
    }
    
    /**
     * POST /api/diagnostic/clear-all-analytics
     * Nuclear option: clear ALL analytics data and start fresh
     */
    public function clearAllAnalytics(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dryRun = $request->boolean('dry_run', true);
        $tenantId = $request->query('tenant_id');

        // Define all tables to clear (in correct order for foreign keys)
        $tables = [
            'chat_messages' => 'Chat messages',
            'chat_sessions' => 'Chat sessions',
            'chat_events' => 'Chat events (analytics)',
            'chat_conversions' => 'Conversions (purchases, carts)',
            'chat_session_outcomes' => 'Session outcomes (funnels)',
            'active_chat_sessions' => 'Active chat sessions (real-time)',
            'ab_test_events' => 'A/B test events',
            'proactive_trigger_events' => 'Proactive trigger events',
        ];
        
        $stats = [];
        $totalDeleted = 0;

        foreach ($tables as $table => $description) {
            // Check if table exists
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                $stats[$table] = ['exists' => false, 'description' => $description, 'count' => 0];
                continue;
            }

            $query = DB::table($table);
            
            // Filter by tenant if specified
            if ($tenantId) {
                $columns = DB::getSchemaBuilder()->getColumnListing($table);
                if (in_array('tenant_id', $columns)) {
                    $query->where('tenant_id', $tenantId);
                } elseif (in_array('merchant_id', $columns)) {
                    $query->where('merchant_id', $tenantId);
                }
            }

            $count = $query->count();
            
            if ($dryRun) {
                $stats[$table] = [
                    'exists' => true,
                    'description' => $description,
                    'count' => $count,
                    'will_delete' => $count,
                ];
                $totalDeleted += $count;
            } else {
                // Rebuild query for actual delete
                $deleteQuery = DB::table($table);
                if ($tenantId) {
                    $columns = DB::getSchemaBuilder()->getColumnListing($table);
                    if (in_array('tenant_id', $columns)) {
                        $deleteQuery->where('tenant_id', $tenantId);
                    } elseif (in_array('merchant_id', $columns)) {
                        $deleteQuery->where('merchant_id', $tenantId);
                    }
                }
                $deleted = $deleteQuery->delete();
                $stats[$table] = [
                    'exists' => true,
                    'description' => $description,
                    'deleted' => $deleted,
                ];
                $totalDeleted += $deleted;
            }
        }

        // Reset counters in proactive_trigger_rules
        if (!$dryRun && DB::getSchemaBuilder()->hasTable('proactive_trigger_rules')) {
            $rulesQuery = DB::table('proactive_trigger_rules');
            if ($tenantId) {
                $rulesQuery->where('tenant_id', $tenantId);
            }
            $rulesReset = $rulesQuery->update([
                'shown_count' => 0,
                'clicked_count' => 0,
                'converted_count' => 0,
                'purchased_count' => 0,
            ]);
            $stats['proactive_trigger_rules_counters'] = ['reset' => $rulesReset];
        } elseif ($dryRun && DB::getSchemaBuilder()->hasTable('proactive_trigger_rules')) {
            $rulesQuery = DB::table('proactive_trigger_rules');
            if ($tenantId) {
                $rulesQuery->where('tenant_id', $tenantId);
            }
            $stats['proactive_trigger_rules_counters'] = [
                'description' => 'Reset shown/clicked/converted counters',
                'rules_to_reset' => $rulesQuery->count(),
            ];
        }

        // Clear cache
        if (!$dryRun) {
            try {
                \Illuminate\Support\Facades\Cache::flush();
                $stats['cache'] = ['cleared' => true];
            } catch (\Exception $e) {
                $stats['cache'] = ['cleared' => false, 'error' => $e->getMessage()];
            }
        }

        if ($dryRun) {
            return response()->json([
                'dry_run' => true,
                'tenant_id' => $tenantId ?? 'all',
                'total_to_delete' => $totalDeleted,
                'tables' => $stats,
                'note' => '⚠️ Set dry_run=false to actually delete. This is IRREVERSIBLE!',
            ]);
        }

        return response()->json([
            'success' => true,
            'dry_run' => false,
            'tenant_id' => $tenantId ?? 'all',
            'total_deleted' => $totalDeleted,
            'tables' => $stats,
            'message' => '🔥 All statistics cleared! Dashboard will show 0 until new data is collected.',
        ]);
    }
    
    /**
     * POST /api/diagnostic/set-super-admin
     * Set user as super admin by email
     */
    public function setSuperAdmin(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $email = $request->input('email', 'stovburtm@gmail.com');
        
        $user = \App\Models\User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'User not found',
                'email' => $email,
            ], 404);
        }
        
        $oldRole = $user->role;
        $user->role = \App\Models\User::ROLE_SUPER_ADMIN;
        $user->save();
        
        return response()->json([
            'success' => true,
            'email' => $email,
            'old_role' => $oldRole,
            'new_role' => $user->role,
            'message' => "User {$email} is now super_admin",
        ]);
    }
    
    /**
     * GET /api/diagnostic/users
     * List all users with roles
     */
    public function listUsers(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $users = \App\Models\User::select(['id', 'name', 'email', 'role', 'tenant_id', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'count' => $users->count(),
            'users' => $users,
        ]);
    }

    /**
     * GET /api/diagnostic/funnel-debug
     * Debug funnel data for a specific tenant
     */
    public function funnelDebug(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->query('tenant_id');
        $days = (int) $request->query('days', 7);
        $startDate = now()->subDays($days)->startOfDay();
        $endDate = now()->endOfDay();

        // Get tenant info
        $tenant = null;
        $slug = null;
        $apiToken = null;
        
        if ($tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);
            if ($tenant) {
                $slug = $tenant->slug;
                $apiToken = $tenant->widgetSettings?->api_token;
            }
        }

        // Check merchant_id distribution in chat_events
        $merchantDistribution = DB::table('chat_events')
            ->select('merchant_id', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('merchant_id')
            ->orderByDesc('count')
            ->get();

        // Funnel data without merchant filter (ALL data)
        $funnelAll = [];
        $eventTypes = ['page_view', 'chat_opened', 'message', 'product_click', 'add_to_cart', 'checkout_success'];
        foreach ($eventTypes as $type) {
            $count = DB::table('chat_events')
                ->where('event_type', $type)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->distinct('session_id')
                ->count('session_id');
            $funnelAll[$type] = $count;
        }

        // Funnel data WITH merchant filter (for this tenant)
        $funnelFiltered = [];
        if ($slug || $apiToken) {
            foreach ($eventTypes as $type) {
                $query = DB::table('chat_events')
                    ->where('event_type', $type)
                    ->whereBetween('created_at', [$startDate, $endDate]);
                
                $query->where(function($q) use ($slug, $apiToken) {
                    $q->where('merchant_id', $slug);
                    if ($apiToken) {
                        $q->orWhere('merchant_id', $apiToken);
                    }
                });
                
                $funnelFiltered[$type] = $query->distinct('session_id')->count('session_id');
            }
        }

        // Orders table check
        $ordersCount = 0;
        $ordersWithChat = 0;
        if (\Schema::hasTable('orders')) {
            if ($tenantId) {
                $tenantSessionIds = DB::table('chat_sessions')
                    ->where('tenant_id', $tenantId)
                    ->pluck('session_id')
                    ->toArray();
                
                if (!empty($tenantSessionIds)) {
                    $ordersCount = DB::table('orders')
                        ->whereIn('session_id', $tenantSessionIds)
                        ->where('created_at', '>=', $startDate)
                        ->count();
                    $ordersWithChat = DB::table('orders')
                        ->whereIn('session_id', $tenantSessionIds)
                        ->where('had_chat', true)
                        ->where('created_at', '>=', $startDate)
                        ->count();
                }
            } else {
                $ordersCount = DB::table('orders')
                    ->where('created_at', '>=', $startDate)
                    ->count();
                $ordersWithChat = DB::table('orders')
                    ->where('had_chat', true)
                    ->where('created_at', '>=', $startDate)
                    ->count();
            }
        }

        // Chat sessions count
        $chatSessionsCount = DB::table('chat_sessions')
            ->where('created_at', '>=', $startDate)
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->count();

        // Proactive trigger events
        $triggerStats = [];
        if ($tenantId) {
            $triggerStats = DB::table('proactive_trigger_events as pte')
                ->join('proactive_trigger_rules as ptr', 'pte.rule_id', '=', 'ptr.id')
                ->where('ptr.tenant_id', $tenantId)
                ->where('pte.created_at', '>=', $startDate)
                ->select('pte.event_type', DB::raw('COUNT(*) as count'))
                ->groupBy('pte.event_type')
                ->get()
                ->pluck('count', 'event_type')
                ->toArray();
        }

        return response()->json([
            'tenant' => [
                'id' => $tenantId,
                'name' => $tenant?->name,
                'slug' => $slug,
                'api_token' => $apiToken ? substr($apiToken, 0, 10) . '...' : null,
            ],
            'period' => [
                'days' => $days,
                'start' => $startDate->format('Y-m-d H:i:s'),
                'end' => $endDate->format('Y-m-d H:i:s'),
            ],
            'merchant_distribution' => $merchantDistribution,
            'funnel_all_tenants' => $funnelAll,
            'funnel_this_tenant' => $funnelFiltered,
            'orders' => [
                'total' => $ordersCount,
                'with_chat' => $ordersWithChat,
            ],
            'chat_sessions' => $chatSessionsCount,
            'trigger_events' => $triggerStats,
            'notes' => [
                'funnel_source' => 'chat_events table, filtered by merchant_id',
                'orders_source' => 'orders table, filtered by session_id belonging to tenant',
                'mismatch_reason' => 'Воронка рахує checkout_success з chat_events, а Замовлення беруться з orders table',
            ],
        ]);
    }

    /**
     * GET /api/diagnostic/trigger-events
     * List recent trigger events for debugging
     */
    public function triggerEvents(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $sessionId = $request->query('session_id');
        $limit = min((int) $request->query('limit', 50), 200);
        
        $query = \App\Models\ProactiveTriggerEvent::with('rule:id,name,trigger_type')
            ->orderByDesc('created_at');
        
        if ($sessionId) {
            $query->where('session_id', $sessionId);
        }
        
        $events = $query->take($limit)->get()->map(function ($event) {
            return [
                'id' => $event->id,
                'rule_id' => $event->rule_id,
                'rule_name' => $event->rule?->name,
                'rule_type' => $event->rule?->trigger_type,
                'session_id' => $event->session_id,
                'event_type' => $event->event_type,
                'context' => $event->context,
                'created_at' => $event->created_at->format('Y-m-d H:i:s'),
            ];
        });
        
        // Summary stats
        $stats = [
            'total_events' => \App\Models\ProactiveTriggerEvent::count(),
            'today_shown' => \App\Models\ProactiveTriggerEvent::today()->where('event_type', 'shown')->count(),
            'today_clicked' => \App\Models\ProactiveTriggerEvent::today()->where('event_type', 'clicked')->count(),
            'today_dismissed' => \App\Models\ProactiveTriggerEvent::today()->where('event_type', 'dismissed')->count(),
        ];
        
        return response()->json([
            'stats' => $stats,
            'events' => $events,
        ]);
    }

    /**
     * GET /api/diagnostic/scheduler-status
     * Check scheduler status and list scheduled tasks
     */
    public function schedulerStatus(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Get scheduled tasks
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = $schedule->events();
        
        $tasks = collect($events)->map(function ($event) {
            return [
                'command' => $event->command ?? $event->description ?? get_class($event),
                'expression' => $event->expression,
                'timezone' => $event->timezone,
                'without_overlapping' => $event->withoutOverlapping ?? false,
                'environments' => $event->environments ?? [],
                'next_run' => \Carbon\Carbon::now()->setTimezone(config('app.timezone'))
                    ->endOfMinute()->addMinute()
                    ->format('Y-m-d H:i:s'),
            ];
        });
        
        // Check last sync logs
        $lastSyncs = [];
        if (\Schema::hasTable('sync_logs')) {
            $lastSyncs = \App\Models\SyncLog::orderByDesc('started_at')
                ->take(10)
                ->get()
                ->map(fn($log) => [
                    'type' => $log->sync_type,
                    'status' => $log->status,
                    'started_at' => $log->started_at?->format('Y-m-d H:i:s'),
                    'duration' => $log->duration_seconds,
                    'error' => $log->error_message,
                ]);
        }
        
        // Check queue status
        $queueInfo = $this->getQueueStatus();
        
        return response()->json([
            'timezone' => config('app.timezone'),
            'server_time' => now()->format('Y-m-d H:i:s'),
            'scheduled_tasks_count' => count($tasks),
            'tasks' => $tasks,
            'queue' => $queueInfo,
            'last_syncs' => $lastSyncs,
            'note' => 'On Laravel Cloud, scheduler requires a Scheduler Worker in Dashboard > Workers',
        ]);
    }
    
    /**
     * Get queue status (pending jobs, failed jobs)
     */
    private function getQueueStatus(): array
    {
        $result = [
            'connection' => config('queue.default'),
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'recent_failed' => [],
        ];
        
        try {
            // Check jobs table if using database driver
            if (config('queue.default') === 'database' && \Schema::hasTable('jobs')) {
                $result['pending_jobs'] = DB::table('jobs')->count();
                
                // Get pending jobs breakdown
                $pendingByQueue = DB::table('jobs')
                    ->select('queue', DB::raw('count(*) as count'))
                    ->groupBy('queue')
                    ->get()
                    ->pluck('count', 'queue')
                    ->toArray();
                $result['pending_by_queue'] = $pendingByQueue;
            }
            
            // Check failed_jobs table
            if (\Schema::hasTable('failed_jobs')) {
                $result['failed_jobs'] = DB::table('failed_jobs')->count();
                
                // Get recent failed jobs
                $recentFailed = DB::table('failed_jobs')
                    ->orderByDesc('failed_at')
                    ->take(5)
                    ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at']);
                
                $result['recent_failed'] = $recentFailed->map(function ($job) {
                    $payload = json_decode($job->payload, true);
                    return [
                        'uuid' => $job->uuid,
                        'queue' => $job->queue,
                        'job' => $payload['displayName'] ?? 'unknown',
                        'failed_at' => $job->failed_at,
                        'error' => \Str::limit($job->exception, 200),
                    ];
                })->toArray();
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }

    /**
     * POST /api/diagnostic/run-sync
     * Manually trigger a sync job
     */
    public function runSyncJob(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $job = $request->input('job', 'horoshop');
        $sync = $request->input('sync', false); // Run synchronously instead of queue
        
        $jobMap = [
            'horoshop' => \App\Jobs\SyncHoroshopProductsJob::class,
            'brands' => \App\Jobs\SyncBrandsJob::class,
            'meili' => \App\Jobs\IndexProductsToMeiliJob::class,
            'ai' => \App\Jobs\AnalyzeProductsWithAiJob::class,
        ];
        
        if (!isset($jobMap[$job])) {
            return response()->json([
                'error' => "Unknown job: {$job}",
                'available' => array_keys($jobMap),
            ], 400);
        }
        
        try {
            $jobClass = $jobMap[$job];
            
            if ($sync) {
                // Run synchronously (will block the request)
                $jobInstance = new $jobClass();
                $jobInstance->handle();
                
                return response()->json([
                    'success' => true,
                    'job' => $job,
                    'mode' => 'sync',
                    'message' => "Job executed synchronously.",
                ]);
            }
            
            dispatch(new $jobClass());
            
            return response()->json([
                'success' => true,
                'job' => $job,
                'mode' => 'queue',
                'dispatched' => $jobClass,
                'message' => "Job dispatched to queue. Check queue worker logs.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * GET /api/diagnostic/tenant/{id}
     * Get tenant info with widget status check
     */
    public function tenantInfo(Request $request, int $id): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $tenant = \App\Models\Tenant::find($id);
        
        if (!$tenant) {
            return response()->json(['error' => "Tenant #{$id} not found"], 404);
        }
        
        $widgetAccess = $tenant->canUseWidget();
        
        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            // Trial info
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'trial_ends_at_human' => $tenant->trial_ends_at?->diffForHumans(),
            'is_on_trial' => $tenant->isOnTrial(),
            'is_trial_expired' => $tenant->isTrialExpired(),
            // Paid subscription info
            'plan_expires_at' => $tenant->plan_expires_at?->toIso8601String(),
            'plan_expires_at_human' => $tenant->plan_expires_at?->diffForHumans(),
            'is_subscription_expiring_soon' => $tenant->isSubscriptionExpiringSoon(),
            // General status
            'is_active' => $tenant->isActive(),
            'can_use_widget' => $widgetAccess,
            'messages_limit' => $tenant->messages_limit,
            'messages_used' => $tenant->messages_used,
            'products_count' => $tenant->products()->count(),
        ]);
    }
    
    /**
     * POST /api/diagnostic/clear-queue
     * Clear all pending jobs from queue (use when queue is backed up)
     */
    public function clearQueue(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $deleted = 0;
        $failed = 0;
        
        try {
            if (\Schema::hasTable('jobs')) {
                $deleted = DB::table('jobs')->delete();
            }
            
            if ($request->input('include_failed', false) && \Schema::hasTable('failed_jobs')) {
                $failed = DB::table('failed_jobs')->delete();
            }
            
            return response()->json([
                'success' => true,
                'deleted_pending' => $deleted,
                'deleted_failed' => $failed,
                'message' => "Queue cleared. {$deleted} pending and {$failed} failed jobs removed.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * GET /api/diagnostic/test-queue
     * Test if queue worker is processing jobs
     */
    public function testQueue(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        // Create a simple test job that just writes to cache
        $testId = uniqid('queue_test_');
        
        // Write "pending" to cache
        \Cache::put("queue_test_{$testId}", 'pending', 300);
        
        // Dispatch a simple closure job
        dispatch(function () use ($testId) {
            \Cache::put("queue_test_{$testId}", 'completed_at_' . now()->toIso8601String(), 300);
        })->onQueue('default');
        
        return response()->json([
            'test_id' => $testId,
            'status' => 'dispatched',
            'check_url' => "/api/diagnostic/test-queue-result?key={$this->secretKey}&test_id={$testId}",
            'message' => 'Job dispatched. Check result in 10-30 seconds.',
        ]);
    }
    
    /**
     * GET /api/diagnostic/test-queue-result
     * Check result of queue test
     */
    public function testQueueResult(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $testId = $request->query('test_id');
        if (!$testId) {
            return response()->json(['error' => 'Missing test_id'], 400);
        }
        
        $result = \Cache::get("queue_test_{$testId}", 'not_found');
        
        $queueWorkerActive = str_starts_with($result, 'completed_at_');
        
        return response()->json([
            'test_id' => $testId,
            'result' => $result,
            'queue_worker_active' => $queueWorkerActive,
            'pending_jobs' => DB::table('jobs')->count(),
        ]);
    }

    /**
     * POST /api/diagnostic/fix-null-tenants
     * Fix chat sessions and messages with NULL tenant_id
     */
    public function fixNullTenants(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $defaultTenantId = $request->input('tenant_id', 2); // Default to Contractor (id=2)
        
        // Fix sessions with NULL tenant_id
        $sessionsFixed = \App\Models\ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $defaultTenantId]);
        
        // Fix messages with NULL tenant_id
        $messagesFixed = \App\Models\ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $defaultTenantId]);
        
        return response()->json([
            'success' => true,
            'sessions_fixed' => $sessionsFixed,
            'messages_fixed' => $messagesFixed,
            'tenant_id_used' => $defaultTenantId,
        ]);
    }

    /**
     * GET /api/diagnostic/tenants
     * List all tenants with stats
     */
    public function tenants(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenants = \App\Models\Tenant::all()->map(function ($tenant) {
            // Extract horoshop domain from credentials (without exposing login/password)
            $credentials = $tenant->platform_credentials ?? [];
            $horoshopDomain = $credentials['domain'] ?? null;
            
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
                'platform' => $tenant->platform,
                'horoshop_domain' => $horoshopDomain,
                'has_credentials' => !empty($credentials),
                'trial_ends_at' => $tenant->trial_ends_at?->toDateTimeString(),
                'last_sync_at' => $tenant->last_sync_at?->toDateTimeString(),
                'messages_used' => $tenant->messages_used,
                'messages_limit' => $tenant->messages_limit,
                'stats' => [
                    'products' => \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                        ->where('tenant_id', $tenant->id)->count(),
                    'products_in_stock' => \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                        ->where('tenant_id', $tenant->id)->where('in_stock', true)->count(),
                    'chat_sessions' => \App\Models\ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                        ->where('tenant_id', $tenant->id)->count(),
                    'chat_messages' => \App\Models\ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
                        ->where('tenant_id', $tenant->id)->count(),
                    'prompt_presets' => DB::table('prompt_presets')->where('tenant_id', $tenant->id)->count(),
                    'greetings' => DB::table('greetings')->where('tenant_id', $tenant->id)->count(),
                    'sync_logs' => DB::table('sync_logs')->where('tenant_id', $tenant->id)->count(),
                ],
            ];
        });

        return response()->json([
            'total' => $tenants->count(),
            'tenants' => $tenants,
        ]);
    }

    /**
     * GET /api/diagnostic/tenant/{id}
     * Detailed tenant info
     */
    public function tenantDetails(Request $request, int $id): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenant = \App\Models\Tenant::find($id);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Get widget settings
        $widgetSettings = $tenant->widgetSettings;

        // Get recent sync logs
        $syncLogs = DB::table('sync_logs')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'sync_type', 'status', 'started_at', 'total_processed', 'created', 'updated', 'failed']);

        // Get categories (include all, not just in_stock)
        $categories = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('category_path')
            ->select('category_path', DB::raw('COUNT(*) as count'))
            ->groupBy('category_path')
            ->orderByDesc('count')
            ->limit(20)
            ->get();
        
        // Get sample products (first 5)
        $sampleProducts = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->limit(5)
            ->get(['id', 'article', 'title', 'price', 'link', 'in_stock', 'category_path']);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
                'platform' => $tenant->platform,
                'trial_ends_at' => $tenant->trial_ends_at?->toDateTimeString(),
                'plan_expires_at' => $tenant->plan_expires_at?->toDateTimeString(),
                'last_sync_at' => $tenant->last_sync_at?->toDateTimeString(),
                'messages_used' => $tenant->messages_used,
                'messages_limit' => $tenant->messages_limit,
                'has_credentials' => !empty($tenant->platform_credentials),
            ],
            'widget_settings' => $widgetSettings ? [
                'id' => $widgetSettings->id,
                'api_token' => $widgetSettings->api_token ? substr($widgetSettings->api_token, 0, 8) . '...' : null,
                'domain' => $widgetSettings->domain,
                'bot_name' => $widgetSettings->bot_name,
                'store_name' => $widgetSettings->store_name,
                'enabled' => $widgetSettings->enabled,
                'horoshop_domain' => $widgetSettings->horoshop_domain,
                'primary_color' => $widgetSettings->primary_color,
                'welcome_message' => $widgetSettings->welcome_message ? substr($widgetSettings->welcome_message, 0, 50) . '...' : null,
            ] : null,
            'stats' => [
                'products' => \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenant->id)->count(),
                'products_in_stock' => \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenant->id)->where('in_stock', true)->count(),
                'chat_sessions' => \App\Models\ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenant->id)->count(),
                'chat_messages' => \App\Models\ChatMessage::withoutGlobalScope(\App\Scopes\TenantScope::class)
                    ->where('tenant_id', $tenant->id)->count(),
                'prompt_presets' => DB::table('prompt_presets')->where('tenant_id', $tenant->id)->count(),
                'greetings' => DB::table('greetings')->where('tenant_id', $tenant->id)->count(),
            ],
            'top_categories' => $categories,
            'sample_products' => $sampleProducts,
            'recent_sync_logs' => $syncLogs,
        ]);
    }

    /**
     * POST /api/diagnostic/migrate-data
     * Migrate products/chats from tenant 1 to tenant 2 (one-time operation)
     */
    public function migrateDataToTenant(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $fromTenantId = $request->input('from_tenant_id', 1);
        $toTenantId = (int) $request->input('to_tenant_id', 2);
        $dryRun = $request->boolean('dry_run', true);
        $migrateNull = $request->boolean('migrate_null', false);

        // Only validate target tenant exists
        $toTenant = \App\Models\Tenant::find($toTenantId);
        if (!$toTenant) {
            return response()->json(['error' => 'Target tenant not found'], 404);
        }

        // Source tenant might not exist (orphaned data cleanup)
        $fromTenant = $fromTenantId !== null ? \App\Models\Tenant::find($fromTenantId) : null;

        $tables = [
            'products' => ['model' => \App\Models\Product::class],
            'product_ai_index' => ['table' => true],
            'chat_sessions' => ['model' => \App\Models\ChatSession::class],
            'chat_messages' => ['model' => \App\Models\ChatMessage::class],
            'prompt_presets' => ['table' => true],
            'greetings' => ['table' => true],
            'sync_logs' => ['table' => true],
            'widget_settings' => ['table' => true],
            'store_contexts' => ['table' => true],
            'proactive_trigger_rules' => ['table' => true],
        ];

        $results = [];

        foreach ($tables as $tableName => $config) {
            if (!DB::getSchemaBuilder()->hasTable($tableName)) {
                $results[$tableName] = ['skipped' => 'table does not exist'];
                continue;
            }

            if (!DB::getSchemaBuilder()->hasColumn($tableName, 'tenant_id')) {
                $results[$tableName] = ['skipped' => 'no tenant_id column'];
                continue;
            }

            // Build query - either by tenant_id or NULL
            $query = DB::table($tableName);
            if ($migrateNull || $fromTenantId === null || $fromTenantId === 'null') {
                $query->whereNull('tenant_id');
                $fromLabel = 'NULL';
            } else {
                $query->where('tenant_id', (int) $fromTenantId);
                $fromLabel = $fromTenantId;
            }

            $count = $query->count();
            
            if ($dryRun) {
                $results[$tableName] = ['would_migrate' => $count, 'from' => $fromLabel];
            } else {
                // Re-build query for update
                $updateQuery = DB::table($tableName);
                if ($migrateNull || $fromTenantId === null || $fromTenantId === 'null') {
                    $updateQuery->whereNull('tenant_id');
                } else {
                    $updateQuery->where('tenant_id', (int) $fromTenantId);
                }
                $updated = $updateQuery->update(['tenant_id' => $toTenantId]);
                $results[$tableName] = ['migrated' => $updated, 'from' => $fromLabel];
            }
        }

        return response()->json([
            'dry_run' => $dryRun,
            'migrate_null' => $migrateNull,
            'from_tenant' => ['id' => $fromTenantId, 'name' => $fromTenant?->name ?? ($migrateNull ? '(NULL records)' : '(orphaned data)')],
            'to_tenant' => ['id' => $toTenantId, 'name' => $toTenant->name],
            'results' => $results,
            'note' => $dryRun 
                ? 'This is a dry run. Set dry_run=false to actually migrate data.' 
                : 'Data has been migrated. Remember to reindex Meilisearch!',
        ]);
    }

    /**
     * POST /api/diagnostic/update-product-color
     * Update color for specific products
     */
    public function updateProductColor(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $articles = $request->input('articles', []);
        $color = $request->input('color', '');
        $dryRun = $request->boolean('dry_run', true);

        if (empty($articles)) {
            return response()->json(['error' => 'articles parameter required (array of article codes)'], 400);
        }

        if (empty($color)) {
            return response()->json(['error' => 'color parameter required'], 400);
        }

        // Find products
        $products = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->whereIn('article', $articles)
            ->get(['id', 'article', 'title', 'color']);

        if ($products->isEmpty()) {
            return response()->json(['error' => 'No products found with given articles'], 404);
        }

        $results = $products->map(fn($p) => [
            'id' => $p->id,
            'article' => $p->article,
            'title' => $p->title,
            'old_color' => $p->color,
            'new_color' => $color,
        ])->toArray();

        if (!$dryRun) {
            \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->whereIn('article', $articles)
                ->update(['color' => $color]);
        }

        return response()->json([
            'dry_run' => $dryRun,
            'color' => $color,
            'updated_count' => count($results),
            'products' => $results,
            'note' => $dryRun 
                ? 'Dry run - set dry_run=false to apply changes. Remember to reindex Meilisearch after!' 
                : 'Products updated! Run Meilisearch reindex to update search.',
        ]);
    }

    /**
     * GET /api/diagnostic/test-color-picker
     * Test color detection from product images using ColorThief
     */
    public function testColorPicker(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $debug = [];
            $debug['step'] = 'init';
            
            $searchQuery = $request->input('q', 'Level 7');
            $limit = min((int) $request->input('limit', 10), 20);
            $tenantId = (int) $request->input('tenant_id', 2);
            $analyzeImages = $request->boolean('analyze_images', true);
            
            $debug['step'] = 'query_prepared';
            $debug['params'] = compact('searchQuery', 'limit', 'tenantId', 'analyzeImages');

            // Get products without color - use DB facade (no TenantScope issues)
            $products = DB::table('products')
                ->select(['id', 'article', 'title', 'color', 'images', 'raw'])
                ->where('tenant_id', $tenantId)
                ->where('in_stock', true)
                ->where(function($builder) {
                    $builder->whereNull('color')
                        ->orWhere('color', '')
                        ->orWhere('color', 'null');
                })
                ->where('title', 'like', '%' . $searchQuery . '%')
                ->limit($limit)
                ->get();
            
            $debug['step'] = 'products_fetched';
            $debug['products_count'] = count($products);

            $colorService = new \App\Services\Catalog\ColorDetectionService();
            $debug['step'] = 'service_created';
            
            $results = [];

            foreach ($products as $product) {
                // DB::table returns stdClass, raw is JSON string
                $rawString = $product->raw ?? '';
                $raw = is_string($rawString) ? (json_decode($rawString, true) ?: []) : [];
                
                // Get image URL - check multiple sources
                $imageUrl = null;
                
                // First priority: images column (JSON array of URLs)
                $imagesCol = $product->images ?? '';
                if (is_string($imagesCol) && !empty($imagesCol)) {
                    $imagesArr = json_decode($imagesCol, true);
                    if (is_array($imagesArr) && !empty($imagesArr[0])) {
                        $imageUrl = $imagesArr[0];
                    }
                }
                
                // Second priority: raw.pictures or raw.images
                if (!$imageUrl) {
                    if (isset($raw['pictures'][0]['url'])) {
                        $imageUrl = $raw['pictures'][0]['url'];
                    } elseif (isset($raw['images'][0]['url'])) {
                        $imageUrl = $raw['images'][0]['url'];
                    } elseif (isset($raw['image'])) {
                        $imageUrl = $raw['image'];
                    }
                }

                $descColor = null;
                try {
                    $descText = $raw['description'] ?? '';
                    // Handle case when description is array
                    if (is_array($descText)) {
                        $descText = implode(' ', array_filter($descText, 'is_string'));
                    }
                    $descColor = is_string($descText) ? $colorService->extractColorFromText($descText) : null;
                } catch (\Throwable $th) {
                    $descColor = 'ERROR: ' . $th->getMessage();
                }

                $result = [
                    'id' => $product->id,
                    'article' => $product->article,
                    'title' => $product->title,
                    'current_color' => $product->color ?: null,
                    'from_description' => $descColor,
                    'from_image' => null,
                    'image_url' => $imageUrl,
                    'palette' => null,
                    'recommended' => null,
                    'source' => null,
                ];

                // Analyze image if requested
                if ($analyzeImages && $imageUrl) {
                    try {
                        $result['from_image'] = $colorService->analyzeImage($imageUrl);
                        $result['palette'] = $colorService->getColorPalette($imageUrl, 3);
                    } catch (\Throwable $imgErr) {
                        $result['error'] = $imgErr->getMessage();
                    }
                }

                // Determine recommended color and source
                $currentColor = $product->color ?? '';
                if ($currentColor !== '' && $currentColor !== 'null') {
                    $result['recommended'] = $currentColor;
                    $result['source'] = 'field';
                } elseif ($result['from_description']) {
                    $result['recommended'] = $result['from_description'];
                    $result['source'] = 'description';
                } elseif ($result['from_image']) {
                    $result['recommended'] = $result['from_image'];
                    $result['source'] = 'image';
                }

                $results[] = $result;
            }
            
            $debug['step'] = 'loop_completed';

            return response()->json([
                'query' => $searchQuery,
                'tenant_id' => $tenantId,
                'products_found' => count($results),
                'analyze_images' => $analyzeImages,
                'products' => $results,
                'debug' => $debug,
                'note' => 'Uses ColorThief library. Priority: color field > description > image analysis',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * GET /api/diagnostic/color-palette
     * Get color palette from a specific image URL
     */
    public function colorPalette(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $imageUrl = $request->input('url');
        $count = min((int) $request->input('count', 5), 10);

        if (empty($imageUrl)) {
            return response()->json(['error' => 'url parameter required'], 400);
        }

        $colorService = app(\App\Services\Catalog\ColorDetectionService::class);
        
        try {
            $palette = $colorService->getColorPalette($imageUrl, $count);
            $dominant = $colorService->analyzeImage($imageUrl);

            return response()->json([
                'url' => $imageUrl,
                'dominant_color' => $dominant,
                'palette' => $palette,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'url' => $imageUrl,
            ], 500);
        }
    }

    /**
     * POST /api/diagnostic/auto-detect-colors
     * Automatically detect and optionally update colors for products without color
     */
    public function autoDetectColors(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->input('tenant_id', 2);
        $limit = min((int) $request->input('limit', 50), 200);
        $dryRun = $request->boolean('dry_run', true);
        $skipImages = $request->boolean('skip_images', false);

        // Get products without color - use DB facade to bypass TenantScope
        $products = DB::table('products')
            ->select(['id', 'article', 'title', 'color', 'images', 'raw'])
            ->where('tenant_id', $tenantId)
            ->where('in_stock', true)
            ->where(function($q) {
                $q->whereNull('color')->orWhere('color', '')->orWhere('color', 'null');
            })
            ->limit($limit)
            ->get();

        $colorService = app(\App\Services\Catalog\ColorDetectionService::class);
        $results = [
            'detected' => [],
            'not_detected' => [],
            'updated' => 0,
        ];

        foreach ($products as $product) {
            // Parse raw JSON (DB::table returns string)
            $rawString = $product->raw ?? '';
            $raw = is_string($rawString) ? (json_decode($rawString, true) ?: []) : [];
            
            // Get image URL from images column first, then raw
            $imageUrl = null;
            if (!$skipImages) {
                $imagesCol = $product->images ?? '';
                if (is_string($imagesCol) && !empty($imagesCol)) {
                    $imagesArr = json_decode($imagesCol, true);
                    if (is_array($imagesArr) && !empty($imagesArr[0])) {
                        $imageUrl = $imagesArr[0];
                    }
                }
                if (!$imageUrl) {
                    $imageUrl = $raw['pictures'][0]['url'] ?? $raw['images'][0]['url'] ?? $raw['image'] ?? null;
                }
            }

            // Get description (handle array case)
            $description = $raw['description'] ?? '';
            if (is_array($description)) {
                $description = implode(' ', array_filter($description, 'is_string'));
            }

            $detectedColor = $colorService->detectColor(
                null, // Force detection even if color exists
                is_string($description) ? $description : null,
                $raw['properties'] ?? $raw['attributes'] ?? null,
                $imageUrl
            );

            if ($detectedColor) {
                $results['detected'][] = [
                    'id' => $product->id,
                    'article' => $product->article,
                    'title' => mb_substr($product->title, 0, 50),
                    'detected_color' => $detectedColor,
                ];

                if (!$dryRun) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['color' => $detectedColor]);
                    $results['updated']++;
                }
            } else {
                $results['not_detected'][] = [
                    'id' => $product->id,
                    'article' => $product->article,
                    'title' => mb_substr($product->title, 0, 50),
                ];
            }
        }

        return response()->json([
            'tenant_id' => $tenantId,
            'dry_run' => $dryRun,
            'skip_images' => $skipImages,
            'total_processed' => count($products),
            'detected_count' => count($results['detected']),
            'not_detected_count' => count($results['not_detected']),
            'updated_count' => $results['updated'],
            'detected' => $results['detected'],
            'not_detected' => array_slice($results['not_detected'], 0, 20), // Limit output
            'note' => $dryRun 
                ? 'Dry run - set dry_run=false to apply changes. Remember to reindex Meilisearch!' 
                : 'Colors updated! Run Meilisearch reindex.',
        ]);
    }

    /**
     * POST /api/diagnostic/seed-triggers
     * Seed default proactive triggers for a tenant
     */
    public function seedTriggers(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->input('tenant_id');
        $force = $request->boolean('force', false);

        if (!$tenantId) {
            return response()->json(['error' => 'tenant_id is required'], 400);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => "Tenant {$tenantId} not found"], 404);
        }

        $service = app(\App\Services\Tenant\DefaultTriggerService::class);
        $existingCount = \App\Models\ProactiveTriggerRule::where('tenant_id', $tenantId)->count();

        if ($existingCount > 0 && !$force) {
            return response()->json([
                'status' => 'skipped',
                'message' => "Tenant {$tenantId} already has {$existingCount} triggers. Use force=true to recreate.",
                'existing_count' => $existingCount,
            ]);
        }

        if ($force && $existingCount > 0) {
            \App\Models\ProactiveTriggerRule::where('tenant_id', $tenantId)->delete();
        }

        $service->createDefaultTriggers($tenant);
        
        $triggers = \App\Models\ProactiveTriggerRule::where('tenant_id', $tenantId)
            ->orderBy('priority')
            ->get(['id', 'name', 'trigger_type', 'is_enabled', 'message']);

        return response()->json([
            'status' => 'success',
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant->name,
            'deleted' => $force ? $existingCount : 0,
            'created' => $triggers->count(),
            'triggers' => $triggers->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->trigger_type,
                'enabled' => $t->is_enabled,
                'message_preview' => mb_substr(str_replace("\n", " ", $t->message), 0, 60) . '...',
            ]),
        ]);
    }

    /**
     * POST /api/diagnostic/seed-test-data
     * Seed test chat sessions, events, and conversions for a tenant
     */
    public function seedTestData(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->input('tenant_id');
        $numSessions = $request->input('sessions', 5);
        $numDays = $request->input('days', 7);

        if (!$tenantId) {
            return response()->json(['error' => 'tenant_id is required'], 400);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => "Tenant {$tenantId} not found"], 404);
        }

        // Get tenant's products for realistic data
        $products = \App\Models\Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('in_stock', true)
            ->inRandomOrder()
            ->limit(20)
            ->get(['id', 'article', 'title', 'price', 'category_path']);

        if ($products->isEmpty()) {
            return response()->json(['error' => "Tenant {$tenantId} has no products"], 400);
        }

        $merchantId = $tenant->slug;
        $results = [
            'sessions_created' => 0,
            'messages_created' => 0,
            'events_created' => 0,
            'conversions_created' => 0,
        ];

        // Sample user queries in Ukrainian
        $userQueries = [
            'Привіт! Шукаю бронежилет',
            'Які розміри є в наявності?',
            'Чи є такий у кольорі мультикам?',
            'Скільки коштує доставка?',
            'Покажіть тактичні рукавички',
            'Чи є знижка на підсумки?',
            'Потрібен ремінь тактичний',
            'А є швидка доставка до Києва?',
            'Хочу замовити декілька позицій',
            'Який розмір мені підійде при зрості 180?',
        ];

        $assistantResponses = [
            'Вітаю! 👋 Раді бачити! Ось що маємо:',
            'Так, цей товар є в наявності! Ось розміри:',
            'Звісно! Ось варіанти в мультикамі:',
            'Доставка Новою Поштою 1-2 дні по Україні.',
            'Ось найкращі тактичні рукавички:',
            'Так! Зараз діє акція -15% на підсумки!',
            'Рекомендую ось ці моделі:',
            'Так, є експрес-доставка! За 1 день.',
            'Чудово! Натисни на картку товару щоб перейти на сайт і додати в кошик.',
            'При зрості 180 рекомендую розмір M або L.',
        ];

        for ($i = 0; $i < $numSessions; $i++) {
            $createdAt = now()->subDays(rand(0, $numDays))->subHours(rand(0, 23))->subMinutes(rand(0, 59));
            $sessionId = 'test_session_' . uniqid();
            $clientId = 'test_client_' . rand(1000, 9999);
            
            // Random UTM params
            $utmSources = ['google', 'facebook', 'instagram', null];
            $utmMediums = ['cpc', 'organic', 'social', null];
            $utmCampaigns = ['summer_sale', 'new_arrivals', 'tactical_gear', null];
            
            $utmSource = $utmSources[array_rand($utmSources)];
            $utmMedium = $utmMediums[array_rand($utmMediums)];
            $utmCampaign = $utmCampaigns[array_rand($utmCampaigns)];

            // Create chat session
            $chatSession = \App\Models\ChatSession::create([
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'last_intent' => 'product_search',
                'messages_count' => rand(3, 8),
                'language' => 'uk',
                'status' => rand(0, 4) > 0 ? 'closed' : 'open',
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addMinutes(rand(5, 30)),
                'last_message_at' => $createdAt->copy()->addMinutes(rand(5, 30)),
            ]);
            $results['sessions_created']++;

            // Create messages
            $numMessages = rand(3, 6);
            $messageTime = $createdAt->copy();
            $shownProducts = $products->random(min(3, $products->count()));
            
            for ($m = 0; $m < $numMessages; $m++) {
                $isUser = $m % 2 === 0;
                $messageTime->addSeconds(rand(10, 120));
                
                if ($isUser) {
                    $content = $userQueries[array_rand($userQueries)];
                } else {
                    $content = $assistantResponses[array_rand($assistantResponses)];
                    // Add product references
                    if (rand(0, 1) && $shownProducts->isNotEmpty()) {
                        $content .= "\n\n[Показані товари: " . $shownProducts->take(3)->pluck('article')->implode(', ') . "]";
                    }
                }

                \App\Models\ChatMessage::create([
                    'tenant_id' => $tenantId,
                    'chat_session_id' => $chatSession->id,
                    'role' => $isUser ? 'user' : 'assistant',
                    'content' => $content,
                    'meta' => $isUser ? null : ['products_shown' => $shownProducts->take(3)->pluck('article')->toArray()],
                    'created_at' => $messageTime,
                    'updated_at' => $messageTime,
                ]);
                $results['messages_created']++;
            }

            // Create chat events
            $eventTime = $createdAt->copy();

            // 1. Page view
            DB::table('chat_events')->insert([
                'session_id' => $sessionId,
                'merchant_id' => $merchantId,
                'event_type' => 'page_view',
                'event_source' => 'widget',
                'client_id' => $clientId,
                'device_type' => ['mobile', 'desktop'][rand(0, 1)],
                'page_url' => 'https://' . $tenant->domain . '/product/' . $shownProducts->first()->article,
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'utm_campaign' => $utmCampaign,
                'created_at' => $eventTime,
            ]);
            $results['events_created']++;
            $eventTime->addSeconds(rand(5, 30));

            // 2. Chat opened
            DB::table('chat_events')->insert([
                'session_id' => $sessionId,
                'merchant_id' => $merchantId,
                'event_type' => 'chat_opened',
                'event_source' => 'widget',
                'client_id' => $clientId,
                'device_type' => ['mobile', 'desktop'][rand(0, 1)],
                'created_at' => $eventTime,
            ]);
            $results['events_created']++;
            $eventTime->addSeconds(rand(10, 60));

            // 3. Message event
            DB::table('chat_events')->insert([
                'session_id' => $sessionId,
                'merchant_id' => $merchantId,
                'event_type' => 'message',
                'event_source' => 'widget',
                'message_type' => 'user',
                'message_text' => $userQueries[array_rand($userQueries)],
                'client_id' => $clientId,
                'created_at' => $eventTime,
            ]);
            $results['events_created']++;
            $eventTime->addSeconds(rand(30, 180));

            // 4. Product clicks (60% chance)
            if (rand(0, 100) < 60) {
                $clickedProduct = $shownProducts->random();
                DB::table('chat_events')->insert([
                    'session_id' => $sessionId,
                    'merchant_id' => $merchantId,
                    'event_type' => 'product_click',
                    'event_source' => 'widget',
                    'product_id' => $clickedProduct->id,
                    'product_article' => $clickedProduct->article,
                    'product_price' => $clickedProduct->price,
                    'client_id' => $clientId,
                    'metadata' => json_encode([
                        'product_title' => $clickedProduct->title,
                        'category' => $clickedProduct->category_path,
                    ]),
                    'created_at' => $eventTime,
                ]);
                $results['events_created']++;
                $eventTime->addSeconds(rand(60, 300));

                // 5. Add to cart (40% of clicks)
                if (rand(0, 100) < 40) {
                    DB::table('chat_events')->insert([
                        'session_id' => $sessionId,
                        'merchant_id' => $merchantId,
                        'event_type' => 'add_to_cart',
                        'event_source' => 'widget',
                        'product_id' => $clickedProduct->id,
                        'product_article' => $clickedProduct->article,
                        'product_price' => $clickedProduct->price,
                        'client_id' => $clientId,
                        'metadata' => json_encode([
                            'product_title' => $clickedProduct->title,
                            'had_chat_conversation' => true,
                            'product_from_chat' => true,
                        ]),
                        'created_at' => $eventTime,
                    ]);
                    $results['events_created']++;
                    $eventTime->addMinutes(rand(5, 60));

                    // 6. Checkout success (30% of add_to_cart)
                    if (rand(0, 100) < 30) {
                        DB::table('chat_events')->insert([
                            'session_id' => $sessionId,
                            'merchant_id' => $merchantId,
                            'event_type' => 'checkout_success',
                            'event_source' => 'webhook',
                            'product_id' => $clickedProduct->id,
                            'product_article' => $clickedProduct->article,
                            'product_price' => $clickedProduct->price,
                            'client_id' => $clientId,
                            'metadata' => json_encode([
                                'order_id' => 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8)),
                                'order_total' => $clickedProduct->price,
                                'items_count' => 1,
                                'product_from_chat' => true,
                            ]),
                            'created_at' => $eventTime,
                        ]);
                        $results['events_created']++;

                        // Create conversion record
                        DB::table('chat_conversions')->insert([
                            'session_id' => $sessionId,
                            'merchant_id' => $merchantId,
                            'client_id' => $clientId,
                            'conversion_type' => 'purchase',
                            'conversion_status' => 'confirmed',
                            'order_id' => 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8)),
                            'order_total' => $clickedProduct->price,
                            'items_count' => 1,
                            'product_ids' => json_encode([$clickedProduct->id]),
                            'product_from_chat' => true,
                            'chat_attributed_value' => $clickedProduct->price,
                            'chat_timestamp' => $createdAt,
                            'conversion_timestamp' => $eventTime,
                            'minutes_to_conversion' => $createdAt->diffInMinutes($eventTime),
                            'created_at' => $eventTime,
                            'updated_at' => $eventTime,
                        ]);
                        $results['conversions_created']++;
                    }
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant->name,
            'merchant_id' => $merchantId,
            'results' => $results,
            'note' => "Created {$results['sessions_created']} test sessions with {$results['messages_created']} messages, {$results['events_created']} events, and {$results['conversions_created']} conversions for the last {$numDays} days.",
        ]);
    }

    /**
     * POST /api/diagnostic/fix-messages-tenant
     * Fix chat_messages that are missing tenant_id by inheriting from their session
     */
    public function fixMessagesTenant(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->input('tenant_id');

        // Get sessions with their tenant_id
        $query = DB::table('chat_sessions')
            ->select('id', 'tenant_id', 'session_id');
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $sessions = $query->get();
        
        $fixed = 0;
        $skipped = 0;
        
        foreach ($sessions as $session) {
            if (!$session->tenant_id) {
                $skipped++;
                continue;
            }
            
            // Update messages that don't have tenant_id
            $updated = DB::table('chat_messages')
                ->where('chat_session_id', $session->id)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $session->tenant_id]);
            
            $fixed += $updated;
        }

        return response()->json([
            'status' => 'success',
            'tenant_id' => $tenantId,
            'sessions_checked' => $sessions->count(),
            'messages_fixed' => $fixed,
            'sessions_skipped' => $skipped,
            'note' => "Fixed {$fixed} messages by inheriting tenant_id from their sessions.",
        ]);
    }

    /**
     * GET /api/diagnostic/horoshop-stock-count
     * Query Horoshop API directly to count products in stock
     */
    public function horoshopStockCount(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = (int) $request->query('tenant_id', 2);
        $tenant = \App\Models\Tenant::find($tenantId);
        
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if ($tenant->platform !== 'horoshop' || empty($tenant->platform_credentials)) {
            return response()->json(['error' => 'Tenant has no Horoshop credentials'], 400);
        }

        set_time_limit(300);

        try {
            $credentials = $tenant->platform_credentials;
            $domain = is_array($credentials['domain']) ? ($credentials['domain']['value'] ?? '') : (string) $credentials['domain'];
            $login = is_array($credentials['login']) ? ($credentials['login']['value'] ?? '') : (string) $credentials['login'];
            $password = is_array($credentials['password']) ? ($credentials['password']['value'] ?? '') : (string) $credentials['password'];
            
            $client = new \App\Services\Horoshop\HoroshopClient($domain, $login, $password);
            
            // Fetch all products with presence field
            $allProducts = [];
            $offset = 0;
            $limit = 500;
            
            do {
                $response = $client->request('catalog/export', [
                    'expr' => ['display_in_showcase' => 1],
                    'limit' => $limit,
                    'offset' => $offset,
                    'includedParams' => ['article', 'presence', 'title'],
                ]);
                
                $products = $response['products'] ?? [];
                if (empty($products)) {
                    break;
                }
                
                foreach ($products as $product) {
                    $allProducts[] = $product;
                }
                
                $offset += $limit;
            } while (count($products) === $limit);

            // Count by presence status
            $presenceCounts = [];
            $inStockCount = 0;
            $outOfStockCount = 0;
            
            foreach ($allProducts as $product) {
                $presence = $product['presence'] ?? 'unknown';
                if (is_array($presence)) {
                    $presence = $presence['value'] ?? $presence['ua'] ?? $presence['ru'] ?? json_encode($presence);
                }
                $presenceLower = mb_strtolower(trim($presence));
                
                $presenceCounts[$presence] = ($presenceCounts[$presence] ?? 0) + 1;
                
                // Check if in stock
                $isOutOfStock = str_contains($presenceLower, 'немає') || 
                                str_contains($presenceLower, 'нема') ||
                                str_contains($presenceLower, 'нет в') ||
                                str_contains($presenceLower, 'відсутн');
                
                if ($isOutOfStock) {
                    $outOfStockCount++;
                } else {
                    $inStockCount++;
                }
            }

            return response()->json([
                'tenant_id' => $tenantId,
                'domain' => $domain,
                'total_products' => count($allProducts),
                'in_stock_count' => $inStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'presence_breakdown' => $presenceCounts,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'trace' => explode("\n", $e->getTraceAsString())[0] ?? '',
            ], 500);
        }
    }

    /**
     * GET /api/diagnostic/benchmark-models
     * Compare GPT models speed and quality
     * 
     * @param Request $request
     *   - models: comma-separated list of models (default: gpt-4o,gpt-5.1)
     *   - runs: number of runs per model (default: 1)
     */
    public function benchmarkModels(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $apiKey = config('services.openai.key');
        $baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');

        if (empty($apiKey)) {
            return response()->json(['error' => 'OPENAI_API_KEY not configured'], 500);
        }

        $modelsParam = $request->query('models', 'gpt-4o,gpt-5.1');
        $models = array_map('trim', explode(',', $modelsParam));
        $runs = min((int) $request->query('runs', 1), 3); // Max 3 runs

        // Test cases
        $testCases = [
            [
                'name' => 'product_search',
                'messages' => [
                    ['role' => 'system', 'content' => 'Ти консультант інтернет-магазину тактичного спорядження. Відповідай коротко.'],
                    ['role' => 'user', 'content' => 'покажи берці'],
                ],
                'tools' => $this->getBenchmarkTools(),
            ],
            [
                'name' => 'slang_correction',
                'messages' => [
                    ['role' => 'system', 'content' => 'Ти консультант. Виправляй сленг і помилки автоматично.'],
                    ['role' => 'user', 'content' => 'покаж бойовку'],
                ],
                'tools' => $this->getBenchmarkTools(),
            ],
            [
                'name' => 'faq_no_tools',
                'messages' => [
                    ['role' => 'system', 'content' => 'Ти консультант. Відповідай коротко.'],
                    ['role' => 'user', 'content' => 'яка у вас доставка?'],
                ],
                'tools' => null,
            ],
            [
                'name' => 'english',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a shop assistant. Reply in the user\'s language.'],
                    ['role' => 'user', 'content' => 'show me tactical gloves'],
                ],
                'tools' => $this->getBenchmarkTools(),
            ],
        ];

        $results = [];
        $client = new \GuzzleHttp\Client(['timeout' => 60]);

        foreach ($models as $model) {
            $modelResults = [];

            foreach ($testCases as $case) {
                $times = [];
                $lastResponse = null;

                for ($i = 0; $i < $runs; $i++) {
                    $start = microtime(true);

                    try {
                        $payload = [
                            'model' => $model,
                            'messages' => $case['messages'],
                        ];

                        // gpt-5 models don't support temperature parameter
                        if (!str_starts_with($model, 'gpt-5')) {
                            $payload['temperature'] = 0.3;
                        }

                        if ($case['tools']) {
                            $payload['tools'] = $case['tools'];
                            $payload['tool_choice'] = 'auto';
                        }

                        $response = $client->post($baseUrl . '/chat/completions', [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $apiKey,
                                'Content-Type' => 'application/json',
                            ],
                            'json' => $payload,
                        ]);

                        $elapsed = round((microtime(true) - $start) * 1000);
                        $data = json_decode($response->getBody()->getContents(), true);

                        $choice = $data['choices'][0]['message'] ?? [];
                        $lastResponse = [
                            'time_ms' => $elapsed,
                            'content' => $choice['content'] ?? null,
                            'tool_calls' => $choice['tool_calls'] ?? [],
                            'tokens' => $data['usage']['total_tokens'] ?? 0,
                            'error' => null,
                        ];
                    } catch (\Exception $e) {
                        $elapsed = round((microtime(true) - $start) * 1000);
                        $lastResponse = [
                            'time_ms' => $elapsed,
                            'content' => null,
                            'tool_calls' => [],
                            'tokens' => 0,
                            'error' => $e->getMessage(),
                        ];
                    }

                    $times[] = $lastResponse['time_ms'];

                    if ($runs > 1) {
                        usleep(300000); // 0.3s pause
                    }
                }

                $avgTime = count($times) > 0 ? round(array_sum($times) / count($times)) : 0;

                $modelResults[$case['name']] = [
                    'avg_ms' => $avgTime,
                    'min_ms' => min($times),
                    'max_ms' => max($times),
                    'tokens' => $lastResponse['tokens'] ?? 0,
                    'has_tool_call' => !empty($lastResponse['tool_calls']),
                    'tool_name' => $lastResponse['tool_calls'][0]['function']['name'] ?? null,
                    'content_preview' => mb_substr($lastResponse['content'] ?? '', 0, 100),
                    'error' => $lastResponse['error'] ?? null,
                ];
            }

            // Calculate average across all tests
            $avgTotal = round(array_sum(array_column($modelResults, 'avg_ms')) / count($testCases));
            
            $results[$model] = [
                'tests' => $modelResults,
                'avg_total_ms' => $avgTotal,
            ];
        }

        // Comparison summary
        $summary = [];
        if (count($models) >= 2) {
            $m1 = $models[0];
            $m2 = $models[1];
            $avg1 = $results[$m1]['avg_total_ms'] ?? 0;
            $avg2 = $results[$m2]['avg_total_ms'] ?? 0;
            
            if ($avg2 > 0) {
                $ratio = round($avg1 / $avg2, 2);
                $diff = $avg1 - $avg2;
                $diffPercent = round(($avg1 / $avg2 - 1) * 100);
                
                $summary = [
                    'faster_model' => $avg1 < $avg2 ? $m1 : $m2,
                    'slower_model' => $avg1 < $avg2 ? $m2 : $m1,
                    'ratio' => $avg1 < $avg2 ? round($avg2 / $avg1, 2) : $ratio,
                    'diff_ms' => abs($diff),
                    'diff_percent' => abs($diffPercent),
                    'recommendation' => $avg1 < $avg2 
                        ? "Use {$m1} - it's " . round($avg2 / $avg1, 1) . "x faster"
                        : "Use {$m2} - it's " . round($avg1 / $avg2, 1) . "x faster",
                ];
            }
        }

        return response()->json([
            'models_tested' => $models,
            'runs_per_test' => $runs,
            'results' => $results,
            'summary' => $summary,
            'api_key_prefix' => substr($apiKey, 0, 8) . '...',
        ]);
    }

    /**
     * Get tools for benchmark tests
     */
    private function getBenchmarkTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_products',
                    'description' => 'Пошук товарів в каталозі магазину',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Пошуковий запит',
                            ],
                            'price_max' => [
                                'type' => 'number',
                                'description' => 'Максимальна ціна',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }
    
    /**
     * POST /api/diagnostic/rebuild-categories
     * Rebuild categories from products for a specific tenant or all tenants
     */
    public function rebuildCategories(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $tenantId = $request->input('tenant_id');
            $service = app(\App\Services\Catalog\CategoryIndexService::class);
            
            $beforeCounts = [];
            $afterCounts = [];
            
            if ($tenantId) {
                // Rebuild for specific tenant
                $beforeCounts[$tenantId] = \App\Models\Category::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->count();
                    
                $service->rebuildForTenant((int) $tenantId);
                
                $afterCounts[$tenantId] = \App\Models\Category::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->count();
                    
                return response()->json([
                    'success' => true,
                    'message' => "Categories rebuilt for tenant {$tenantId}",
                    'tenant_id' => (int) $tenantId,
                    'before_count' => $beforeCounts[$tenantId],
                    'after_count' => $afterCounts[$tenantId],
                ]);
            } else {
                // Rebuild for all tenants
                $tenantIds = \App\Models\Product::distinct()->pluck('tenant_id')->filter()->toArray();
                
                foreach ($tenantIds as $tid) {
                    $beforeCounts[$tid] = \App\Models\Category::withoutGlobalScopes()
                        ->where('tenant_id', $tid)
                        ->count();
                }
                
                $service->rebuild();
                
                foreach ($tenantIds as $tid) {
                    $afterCounts[$tid] = \App\Models\Category::withoutGlobalScopes()
                        ->where('tenant_id', $tid)
                        ->count();
                }
                
                $totalBefore = array_sum($beforeCounts);
                $totalAfter = array_sum($afterCounts);
                
                return response()->json([
                    'success' => true,
                    'message' => "Categories rebuilt for " . count($tenantIds) . " tenants",
                    'tenants_processed' => $tenantIds,
                    'before_counts' => $beforeCounts,
                    'after_counts' => $afterCounts,
                    'total_before' => $totalBefore,
                    'total_after' => $totalAfter,
                ]);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
    
    /**
     * POST /api/diagnostic/onboard-tenant
     * Run full onboarding for a tenant (sync, categories, AI, Meili)
     */
    public function onboardTenant(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->input('tenant_id');
        
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'tenant_id is required',
            ], 400);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        
        if (!$tenant) {
            return response()->json([
                'success' => false,
                'error' => "Tenant {$tenantId} not found",
            ], 404);
        }

        $sync = $request->boolean('sync', false); // Run synchronously?
        
        if ($sync) {
            // Run synchronously (blocking)
            \App\Jobs\OnboardTenantJob::dispatchSync($tenantId);
            
            return response()->json([
                'success' => true,
                'message' => "Onboarding completed for tenant {$tenantId}",
                'tenant_name' => $tenant->name,
                'sync' => true,
            ]);
        } else {
            // Dispatch to queue (async)
            \App\Jobs\OnboardTenantJob::dispatch($tenantId)->onQueue('default');
            
            return response()->json([
                'success' => true,
                'message' => "Onboarding job dispatched for tenant {$tenantId}",
                'tenant_name' => $tenant->name,
                'sync' => false,
                'hint' => 'Check queue worker logs for progress',
            ]);
        }
    }

    /**
     * GET /api/diagnostic/order/{orderId}
     * Find order by order_id and check chat events
     */
    public function findOrder(Request $request, $orderId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = DB::table('orders')->where('order_id', $orderId)->first();
        
        if (!$order) {
            return response()->json([
                'error' => 'Order not found',
                'order_id' => $orderId,
            ], 404);
        }

        // Get order items
        $items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->get()
            ->map(fn($item) => [
                'article' => $item->article,
                'title' => $item->title,
                'price' => $item->price,
                'quantity' => $item->quantity,
            ]);

        // Check for chat session
        $chatSession = null;
        $chatMessages = [];
        if ($order->session_id) {
            $chatSession = DB::table('chat_sessions')
                ->where('session_id', $order->session_id)
                ->first();
            
            if ($chatSession) {
                $chatMessages = DB::table('chat_messages')
                    ->where('chat_session_id', $chatSession->id)
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn($m) => [
                        'role' => $m->role,
                        'content' => mb_substr($m->content, 0, 200),
                        'created_at' => $m->created_at,
                    ]);
            }
        }

        // Check for chat events related to this session
        $chatEvents = [];
        if ($order->session_id) {
            $chatEvents = DB::table('chat_events')
                ->where('session_id', $order->session_id)
                ->orderBy('created_at')
                ->get()
                ->map(fn($e) => [
                    'type' => $e->event_type,
                    'product_article' => $e->product_article,
                    'created_at' => $e->created_at,
                ]);
        }

        // Check add_to_cart events by phone or around order time
        $cartEventsByPhone = [];
        if ($order->customer_phone) {
            // Normalize phone for search
            $phone = preg_replace('/[^0-9]/', '', $order->customer_phone);
            $phoneShort = substr($phone, -10); // last 10 digits
            
            $cartEventsByPhone = DB::table('chat_events')
                ->where('event_type', 'add_to_cart')
                ->where(function($q) use ($phone, $phoneShort, $order) {
                    $q->where('metadata', 'like', '%' . $phoneShort . '%')
                      ->orWhere('metadata', 'like', '%' . ($order->customer_name ?? 'NO_MATCH') . '%');
                })
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        }

        // Find add_to_cart events around order time (±2 hours)
        $orderTime = $order->ordered_at ?? $order->created_at;
        $cartEventsAroundTime = DB::table('chat_events')
            ->where('event_type', 'add_to_cart')
            ->whereBetween('created_at', [
                date('Y-m-d H:i:s', strtotime($orderTime) - 7200),
                date('Y-m-d H:i:s', strtotime($orderTime) + 7200),
            ])
            ->orderBy('created_at')
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'session_id' => $e->session_id,
                'product_article' => $e->product_article,
                'metadata' => json_decode($e->metadata ?? '{}', true),
                'created_at' => $e->created_at,
            ]);

        return response()->json([
            'order' => [
                'id' => $order->id,
                'order_id' => $order->order_id,
                'tenant_id' => $order->tenant_id,
                'session_id' => $order->session_id,
                'had_chat' => (bool) $order->had_chat,
                'products_from_chat' => $order->products_from_chat,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_city' => $order->customer_city,
                'total_sum' => $order->total_sum,
                'ordered_at' => $order->ordered_at,
                'created_at' => $order->created_at,
            ],
            'items' => $items,
            'chat_session' => $chatSession ? [
                'id' => $chatSession->id,
                'session_id' => $chatSession->session_id,
                'messages_count' => count($chatMessages),
            ] : null,
            'chat_messages' => $chatMessages,
            'chat_events_for_session' => $chatEvents,
            'cart_events_around_order_time' => $cartEventsAroundTime,
            'analysis' => [
                'has_session_id' => !empty($order->session_id),
                'has_chat_session' => !empty($chatSession),
                'has_chat_messages' => count($chatMessages) > 0,
                'had_actual_chat' => count($chatMessages) > 0,
                'should_count_in_funnel' => count($chatMessages) > 0,
            ],
        ]);
    }

    /**
     * POST /api/diagnostic/fix-order-chat/{orderId}
     * Fix order chat attribution
     */
    public function fixOrderChat(Request $request, $orderId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = DB::table('orders')->where('order_id', $orderId)->first();
        
        if (!$order) {
            return response()->json([
                'error' => 'Order not found',
                'order_id' => $orderId,
            ], 404);
        }

        // Check if there was actual chat
        $hadActualChat = false;
        if ($order->session_id) {
            $chatSession = DB::table('chat_sessions')
                ->where('session_id', $order->session_id)
                ->first();
            
            if ($chatSession) {
                $messagesCount = DB::table('chat_messages')
                    ->where('chat_session_id', $chatSession->id)
                    ->count();
                $hadActualChat = $messagesCount > 0;
            }
        }

        // Update order
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->update([
                'had_chat' => $hadActualChat,
                'products_from_chat' => $hadActualChat ? $order->products_from_chat : 0,
            ]);

        // Delete related cart events if no actual chat
        $deletedEvents = 0;
        if (!$hadActualChat && $order->session_id) {
            $deletedEvents = DB::table('chat_events')
                ->where('session_id', $order->session_id)
                ->whereIn('event_type', ['add_to_cart', 'checkout_success', 'checkout_submit'])
                ->delete();
        }

        return response()->json([
            'success' => true,
            'order_id' => $orderId,
            'had_actual_chat' => $hadActualChat,
            'order_updated' => $updated > 0,
            'events_deleted' => $deletedEvents,
            'message' => $hadActualChat 
                ? 'Order had actual chat conversation, kept attribution' 
                : 'No actual chat found, removed attribution',
        ]);
    }

    /**
     * POST /api/diagnostic/cleanup-false-chat-events
     * Remove add_to_cart events where had_chat=true but no actual chat session exists
     */
    public function cleanupFalseChatEvents(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dryRun = $request->query('dry_run', 'true') === 'true';
        $tenantId = $request->query('tenant_id');

        // Find all add_to_cart/checkout events
        $query = DB::table('chat_events')
            ->whereIn('event_type', ['add_to_cart', 'checkout_success', 'checkout_submit']);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $events = $query->get();

        $falseEvents = [];
        foreach ($events as $event) {
            $meta = json_decode($event->metadata ?? '{}', true);
            $hadChatInMeta = $meta['had_chat_conversation'] ?? false;
            
            // Skip events that don't claim to have had chat
            if (!$hadChatInMeta) {
                continue;
            }
            
            // Check if chat session actually exists and has user messages
            $hasActualChat = false;
            
            $chatSession = DB::table('chat_sessions')
                ->where('session_id', $event->session_id)
                ->first();
            
            if ($chatSession) {
                $userMessages = DB::table('chat_messages')
                    ->where('chat_session_id', $chatSession->id)
                    ->where('role', 'user')
                    ->count();
                $hasActualChat = $userMessages > 0;
            }

            if (!$hasActualChat) {
                $falseEvents[] = [
                    'id' => $event->id,
                    'session_id' => $event->session_id,
                    'event_type' => $event->event_type,
                    'product_article' => $event->product_article,
                    'created_at' => $event->created_at,
                    'has_chat_session' => (bool) $chatSession,
                    'reason' => $chatSession ? 'No user messages in chat' : 'Chat session not found',
                ];
            }
        }

        $deletedCount = 0;
        if (!$dryRun && count($falseEvents) > 0) {
            $idsToDelete = array_column($falseEvents, 'id');
            $deletedCount = DB::table('chat_events')
                ->whereIn('id', $idsToDelete)
                ->delete();
        }

        return response()->json([
            'dry_run' => $dryRun,
            'total_events_checked' => $events->count(),
            'false_events_found' => count($falseEvents),
            'events_deleted' => $deletedCount,
            'false_events' => $falseEvents,
            'hint' => $dryRun ? 'Add ?dry_run=false to actually delete events' : 'Events deleted',
        ]);
    }

    /**
     * GET /api/diagnostic/color-synonyms
     * Show color synonyms statistics and data
     */
    public function colorSynonyms(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $synonyms = DB::table('color_synonyms')
                ->select('color_group', DB::raw('COUNT(*) as synonyms_count'))
                ->groupBy('color_group')
                ->orderByDesc('synonyms_count')
                ->get();

            $allSynonyms = DB::table('color_synonyms')
                ->orderBy('color_group')
                ->orderByDesc('is_primary')
                ->get()
                ->groupBy('color_group')
                ->map(function ($items) {
                    return $items->pluck('synonym')->toArray();
                });

            $totalGroups = $synonyms->count();
            $totalSynonyms = DB::table('color_synonyms')->count();

            return response()->json([
                'total_groups' => $totalGroups,
                'total_synonyms' => $totalSynonyms,
                'groups_summary' => $synonyms->toArray(),
                'all_synonyms' => $allSynonyms->toArray(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'hint' => 'Table may not exist. Run: php artisan synonyms:colors',
            ], 500);
        }
    }

    /**
     * GET /api/diagnostic/category-aliases
     * Show category aliases statistics and data
     */
    public function categoryAliases(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $aliases = DB::table('category_aliases as ca')
                ->join('categories as c', 'ca.category_id', '=', 'c.id')
                ->select(
                    'c.path',
                    'ca.source',
                    DB::raw('COUNT(*) as aliases_count'),
                    DB::raw('AVG(ca.weight) as avg_weight')
                )
                ->where('ca.is_active', true)
                ->groupBy('c.path', 'ca.source')
                ->orderBy('c.path')
                ->get();

            $totalCategories = DB::table('categories')->count();
            $totalAliases = DB::table('category_aliases')->count();
            $aiGenerated = DB::table('category_aliases')->where('source', 'ai_generated')->count();

            // Sample aliases for top categories
            $topCategories = DB::table('categories')
                ->orderByDesc('products_count')
                ->limit(10)
                ->pluck('id', 'path');

            $sampleAliases = [];
            foreach ($topCategories as $path => $catId) {
                $sampleAliases[$path] = DB::table('category_aliases')
                    ->where('category_id', $catId)
                    ->where('is_active', true)
                    ->orderByDesc('weight')
                    ->limit(10)
                    ->pluck('phrase')
                    ->toArray();
            }

            return response()->json([
                'total_categories' => $totalCategories,
                'total_aliases' => $totalAliases,
                'ai_generated' => $aiGenerated,
                'by_source' => $aliases->groupBy('source')->map(fn($items) => $items->sum('aliases_count')),
                'sample_aliases' => $sampleAliases,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'hint' => 'Tables may not exist. Run: php artisan category:rebuild && php artisan category:aliases',
            ], 500);
        }
    }

    /**
     * GET /api/diagnostic/onboarding-status/{tenantId}
     * Check onboarding progress for a tenant
     */
    public function onboardingStatus(Request $request, int $tenantId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $progress = \App\Models\TenantOnboardingProgress::where('tenant_id', $tenantId)->first();

        // Get tenant stats
        $productsCount = DB::table('products')->where('tenant_id', $tenantId)->count();
        $productsInStock = DB::table('products')->where('tenant_id', $tenantId)->where('in_stock', true)->count();
        $categoriesCount = DB::table('categories')->where('tenant_id', $tenantId)->count();
        $brandsCount = DB::table('brands')->where('tenant_id', $tenantId)->count();

        return response()->json([
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant->name,
            'platform' => $tenant->platform,
            'has_credentials' => !empty($tenant->platform_credentials),
            'last_sync_at' => $tenant->last_sync_at?->toDateTimeString(),
            'onboarding_completed_at' => $tenant->onboarding_completed_at?->toDateTimeString(),
            'onboarding_progress' => $progress ? [
                'status' => $progress->status,
                'overall_percent' => $progress->overall_percent,
                'current_step' => $progress->current_step,
                'current_step_detail' => $progress->current_step_detail,
                'steps' => $progress->steps,
                'started_at' => $progress->started_at?->toDateTimeString(),
                'completed_at' => $progress->completed_at?->toDateTimeString(),
                'error_message' => $progress->error_message,
            ] : null,
            'stats' => [
                'products' => $productsCount,
                'products_in_stock' => $productsInStock,
                'categories' => $categoriesCount,
                'brands' => $brandsCount,
            ],
        ]);
    }

    /**
     * GET /api/diagnostic/categories-by-tenant
     * List categories grouped by tenant
     */
    public function categoriesByTenant(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->query('tenant_id');

        // Get category counts by tenant
        $query = DB::table('categories')
            ->select('tenant_id', DB::raw('COUNT(*) as count'))
            ->groupBy('tenant_id');
        
        $byTenant = $query->get()->pluck('count', 'tenant_id')->toArray();

        // Get categories for specific tenant if requested
        $categories = null;
        if ($tenantId !== null) {
            $categories = DB::table('categories')
                ->where('tenant_id', $tenantId)
                ->select('id', 'path', 'products_count', 'is_active')
                ->orderBy('path')
                ->get();
        }

        return response()->json([
            'total' => array_sum($byTenant),
            'by_tenant' => $byTenant,
            'categories' => $categories,
        ]);
    }

    /**
     * POST /api/diagnostic/unlink-order-session/{orderId}
     * Force unlink a session from an order (for fixing false attributions)
     */
    public function unlinkOrderSession(Request $request, $orderId): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = DB::table('orders')->where('order_id', $orderId)->first();
        
        if (!$order) {
            return response()->json([
                'error' => 'Order not found',
                'order_id' => $orderId,
            ], 404);
        }

        $oldSessionId = $order->session_id;
        $oldHadChat = $order->had_chat;
        $oldProductsFromChat = $order->products_from_chat;

        // Update order to remove session attribution
        $updated = DB::table('orders')
            ->where('id', $order->id)
            ->update([
                'session_id' => null,
                'had_chat' => false,
                'products_from_chat' => 0,
            ]);

        return response()->json([
            'success' => true,
            'order_id' => $orderId,
            'customer_name' => $order->customer_name,
            'total_sum' => $order->total_sum,
            'before' => [
                'session_id' => $oldSessionId,
                'had_chat' => $oldHadChat,
                'products_from_chat' => $oldProductsFromChat,
            ],
            'after' => [
                'session_id' => null,
                'had_chat' => false,
                'products_from_chat' => 0,
            ],
            'message' => 'Order unlinked from chat session successfully',
        ]);
    }

    /**
     * DELETE /api/diagnostic/cleanup-test-sessions
     * Delete test chat sessions (session_id starts with 'test' or 'compare')
     */
    public function cleanupTestSessions(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dryRun = $request->query('dry_run', 'true') === 'true';
        $tenantId = $request->query('tenant_id'); // optional filter

        // Find test sessions by pattern
        $query = DB::table('chat_sessions')
            ->where(function ($q) {
                // Match sessions that are NOT real sessions
                // Real sessions: start with "session_" or are UUIDs
                // UUIDs: 8-4-4-4-12 format with hyphens
                $q->where('session_id', 'NOT LIKE', 'session_%')
                  ->where(DB::raw('LENGTH(session_id)'), '!=', 36) // UUIDs are exactly 36 chars
                  ->orWhere(function ($q2) {
                      $q2->where('session_id', 'NOT LIKE', 'session_%')
                         ->where('session_id', 'NOT LIKE', '________-____-____-____-____________'); // UUID pattern
                  });
            });

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $testSessions = $query->get();

        $results = [
            'dry_run' => $dryRun,
            'sessions_found' => $testSessions->count(),
            'sessions' => [],
            'messages_deleted' => 0,
            'sessions_deleted' => 0,
        ];

        foreach ($testSessions as $session) {
            $messagesCount = DB::table('chat_messages')
                ->where('chat_session_id', $session->id)
                ->count();

            $results['sessions'][] = [
                'id' => $session->id,
                'session_id' => $session->session_id,
                'tenant_id' => $session->tenant_id,
                'messages_count' => $messagesCount,
                'created_at' => $session->created_at,
            ];

            if (!$dryRun) {
                // Delete messages first
                $deletedMessages = DB::table('chat_messages')
                    ->where('chat_session_id', $session->id)
                    ->delete();
                $results['messages_deleted'] += $deletedMessages;

                // Delete session
                DB::table('chat_sessions')->where('id', $session->id)->delete();
                $results['sessions_deleted']++;
            }
        }

        $results['message'] = $dryRun 
            ? 'Dry run complete. Use ?dry_run=false to actually delete.'
            : "Deleted {$results['sessions_deleted']} sessions and {$results['messages_deleted']} messages.";

        return response()->json($results);
    }

    /**
     * POST /api/diagnostic/close-inactive-sessions
     * Close chat sessions that have been inactive
     */
    public function closeInactiveSessions(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $timeoutMinutes = (int) $request->query('timeout', 30);
        $dryRun = $request->query('dry_run', 'true') === 'true';
        $cutoffTime = now()->subMinutes($timeoutMinutes);

        // Find open sessions with no recent activity
        $query = DB::table('chat_sessions')
            ->where('status', 'open')
            ->where('updated_at', '<', $cutoffTime);

        $sessionsToClose = $query->get();

        $results = [
            'timeout_minutes' => $timeoutMinutes,
            'cutoff_time' => $cutoffTime->toDateTimeString(),
            'dry_run' => $dryRun,
            'sessions_found' => $sessionsToClose->count(),
            'sessions' => $sessionsToClose->map(fn($s) => [
                'id' => $s->id,
                'session_id' => $s->session_id,
                'tenant_id' => $s->tenant_id,
                'messages_count' => $s->messages_count,
                'last_activity' => $s->updated_at,
            ])->toArray(),
            'sessions_closed' => 0,
        ];

        if (!$dryRun && $sessionsToClose->count() > 0) {
            $closed = DB::table('chat_sessions')
                ->where('status', 'open')
                ->where('updated_at', '<', $cutoffTime)
                ->update(['status' => 'closed']);
            
            $results['sessions_closed'] = $closed;
        }

        $results['message'] = $dryRun 
            ? 'Dry run. Use ?dry_run=false to close sessions.'
            : "Closed {$results['sessions_closed']} sessions.";

        return response()->json($results);
    }

    /**
     * GET /api/diagnostic/session-stats
     * Get chat session statistics by status
     */
    public function sessionStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $tenantId = $request->query('tenant_id');

        $query = DB::table('chat_sessions');
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $byStatus = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Recent activity breakdown
        $now = now();
        $last30min = (clone $query)->where('status', 'open')->where('updated_at', '>=', $now->copy()->subMinutes(30))->count();
        $last1hour = (clone $query)->where('status', 'open')->where('updated_at', '>=', $now->copy()->subHour())->count();
        $last24hours = (clone $query)->where('status', 'open')->where('updated_at', '>=', $now->copy()->subDay())->count();
        $older = (clone $query)->where('status', 'open')->where('updated_at', '<', $now->copy()->subDay())->count();

        // Messages stats
        $totalMessages = DB::table('chat_messages')->count();
        $avgMessagesPerSession = $query->avg('messages_count');

        return response()->json([
            'by_status' => $byStatus,
            'total_sessions' => array_sum($byStatus),
            'open_sessions_breakdown' => [
                'active_last_30min' => $last30min,
                'active_last_1hour' => $last1hour,
                'active_last_24hours' => $last24hours,
                'stale_over_24hours' => $older,
            ],
            'messages' => [
                'total' => $totalMessages,
                'avg_per_session' => round($avgMessagesPerSession ?? 0, 1),
            ],
            'recommended_action' => $older > 0 
                ? "Consider closing {$older} stale sessions with: POST /api/diagnostic/close-inactive-sessions?timeout=1440&dry_run=false"
                : 'All sessions are healthy',
        ]);
    }

    /**
     * POST /api/diagnostic/run-command
     * Run artisan commands safely
     * 
     * Allowed commands (whitelist for security):
     * - migrate
     * - synonyms:products --tenant=X
     * - synonyms:regenerate --for-all-tenants
     * - meili:reindex-products
     * - meili:setup-products
     * - sync:horoshop-products --tenant=X
     * - queue:restart
     * - optimize:clear
     */
    public function runCommand(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $command = $request->input('command');
        
        if (empty($command)) {
            return response()->json([
                'error' => 'Missing command parameter',
                'usage' => 'POST /api/diagnostic/run-command?key=XXX with body {"command": "synonyms:products --tenant=2"}',
                'allowed_commands' => $this->getAllowedCommands(),
            ], 400);
        }

        // Parse command and arguments
        $parts = explode(' ', trim($command));
        $artisanCommand = array_shift($parts);
        
        // Security: whitelist of allowed commands
        $allowedCommands = [
            'migrate',
            'synonyms:products',
            'synonyms:regenerate', 
            'meili:reindex-products',
            'meili:setup-products',
            'sync:horoshop-products',
            'queue:restart',
            'optimize:clear',
            'optimize',
            'cache:clear',
            'config:clear',
            'view:clear',
        ];

        if (!in_array($artisanCommand, $allowedCommands)) {
            return response()->json([
                'error' => "Command '{$artisanCommand}' is not allowed",
                'allowed_commands' => $allowedCommands,
            ], 403);
        }

        // Parse arguments
        $arguments = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                $part = ltrim($part, '-');
                if (str_contains($part, '=')) {
                    [$key, $value] = explode('=', $part, 2);
                    $arguments["--{$key}"] = $value;
                } else {
                    $arguments["--{$part}"] = true;
                }
            }
        }

        // Always force non-interactive
        $arguments['--no-interaction'] = true;
        
        // Add --force for migrate command in production
        if ($artisanCommand === 'migrate') {
            $arguments['--force'] = true;
        }

        try {
            $startTime = microtime(true);
            
            $exitCode = \Illuminate\Support\Facades\Artisan::call($artisanCommand, $arguments);
            $output = \Illuminate\Support\Facades\Artisan::output();
            
            $duration = round(microtime(true) - $startTime, 2);

            return response()->json([
                'success' => $exitCode === 0,
                'command' => $artisanCommand,
                'arguments' => $arguments,
                'exit_code' => $exitCode,
                'output' => $output,
                'duration_seconds' => $duration,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'command' => $artisanCommand,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * GET /api/diagnostic/synonyms-stats
     * Get product synonyms statistics
     */
    public function synonymsStats(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $total = DB::table('product_synonyms')->count();
            
            // Check if tenant_id column exists
            $hasTenantColumn = DB::getSchemaBuilder()->hasColumn('product_synonyms', 'tenant_id');
            
            $byTenant = [];
            if ($hasTenantColumn) {
                $byTenant = DB::table('product_synonyms')
                    ->select('tenant_id', DB::raw('COUNT(*) as count'))
                    ->groupBy('tenant_id')
                    ->pluck('count', 'tenant_id')
                    ->toArray();
            }
            
            $byLanguage = DB::table('product_synonyms')
                ->select('language', DB::raw('COUNT(*) as count'))
                ->groupBy('language')
                ->pluck('count', 'language')
                ->toArray();

            // Get columns that exist
            $columns = ['synonym', 'product_type'];
            if (DB::getSchemaBuilder()->hasColumn('product_synonyms', 'canonical')) {
                $columns[] = 'canonical';
            }
            if ($hasTenantColumn) {
                $columns[] = 'tenant_id';
            }

            $sampleSynonyms = DB::table('product_synonyms')
                ->select($columns)
                ->limit(20)
                ->get();

            return response()->json([
                'total' => $total,
                'by_tenant' => $byTenant,
                'by_language' => $byLanguage,
                'sample' => $sampleSynonyms,
                'table_exists' => true,
                'has_tenant_column' => $hasTenantColumn,
                'hint' => !$hasTenantColumn ? 'Run migrate to add tenant_id column' : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'table_exists' => false,
                'hint' => 'Run: POST /api/diagnostic/run-command with {"command": "migrate"}',
            ]);
        }
    }

    /**
     * Get list of allowed commands
     */
    private function getAllowedCommands(): array
    {
        return [
            'migrate' => 'Run database migrations',
            'synonyms:products --tenant=X' => 'Generate product synonyms for tenant X',
            'synonyms:regenerate --for-all-tenants' => 'Regenerate synonyms for all tenants',
            'meili:reindex-products' => 'Reindex all products in Meilisearch',
            'meili:setup-products' => 'Setup Meilisearch index settings',
            'sync:horoshop-products --tenant=X' => 'Sync products from Horoshop for tenant X',
            'queue:restart' => 'Restart queue workers',
            'optimize:clear' => 'Clear all cached data',
            'optimize' => 'Cache configs and routes',
            'cache:clear' => 'Clear application cache',
            'config:clear' => 'Clear config cache',
            'view:clear' => 'Clear view cache',
        ];
    }
}
