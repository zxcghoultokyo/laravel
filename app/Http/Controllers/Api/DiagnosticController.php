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

        // Price statistics
        $priceService = app(\App\Services\Catalog\PriceStatsService::class);
        $priceStats = $priceService->getStats();

        // Tenant statistics - bypass TenantScope to see all data
        $tenantStats = DB::table('products')
            ->select('tenant_id', DB::raw('COUNT(*) as count'))
            ->groupBy('tenant_id')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->tenant_id ?? 'NULL' => $row->count
            ]);

        $stats = [
            'total_products' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->count(),
            'in_stock' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->count(),
            'with_color' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->whereNotNull('color')->where('color', '!=', '')->count(),
            'with_size' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->whereNotNull('size')->where('size', '!=', '')->count(),
            'with_parent_article' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->whereNotNull('parent_article')->where('parent_article', '!=', '')->count(),
            'unique_colors' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->whereNotNull('color')->where('color', '!=', '')->distinct()->pluck('color')->toArray(),
            'categories_count' => Product::withoutGlobalScope(\App\Scopes\TenantScope::class)->where('in_stock', true)->distinct('category_path')->count('category_path'),
            'products_by_tenant' => $tenantStats,
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
        ];

        return response()->json($stats);
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

        if (!$query) {
            return response()->json(['error' => 'Missing q parameter'], 400);
        }

        // Use withoutGlobalScope to see all products including those with NULL tenant_id
        $products = Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('in_stock', true)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('search_index', 'like', "%{$query}%")
                  ->orWhere('category_path', 'like', "%{$query}%");
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

        return response()->json([
            'found' => true,
            'article' => $article,
            'product' => [
                'id' => $product->id,
                'article' => $product->article,
                'title' => $product->title,
                'price' => $product->price,
                'in_stock' => $product->in_stock,
                'category_path' => $product->category_path,
                'brand' => $product->brand,
                'tenant_id' => $product->tenant_id,
                'images' => $product->images,
                'raw_pictures' => $product->raw['pictures'] ?? null,
                'raw_images' => $product->raw['images'] ?? null,
                'raw_image' => $product->raw['image'] ?? null,
            ],
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

        if (!$query) {
            return response()->json(['error' => 'Missing q parameter'], 400);
        }

        try {
            $meili = app(MeiliClient::class);
            $index = $meili->client()->index('products');

            $searchParams = [
                'limit' => $limit,
                'attributesToRetrieve' => ['id', 'article', 'title', 'price', 'category_path', 'color', 'color_norm', 'size', 'brand', 'in_stock', 'ai_product_type'],
            ];

            if ($filter) {
                $searchParams['filter'] = $filter;
            } else {
                $searchParams['filter'] = 'in_stock = true';
            }

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
     */
    public function testChat(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query('q', '');

        if (!$query) {
            return response()->json(['error' => 'Missing q parameter'], 400);
        }

        try {
            $searchTool = app(\App\Services\Agent\Tools\MeiliProductSearchTool::class);
            $results = $searchTool->search($query, [], 20);

            return response()->json([
                'query' => $query,
                'count' => count($results),
                'products' => array_map(fn($p) => [
                    'id' => $p['id'] ?? null,
                    'title' => $p['title'] ?? null,
                    'price' => $p['price'] ?? null,
                    'category_path' => $p['category_path'] ?? null,
                    'ai_product_type' => $p['ai_product_type'] ?? null,
                    'brand' => $p['brand'] ?? null,
                ], $results),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'query' => $query,
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
     */
    public function reindexMeili(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $chunk = min((int) $request->query('chunk', 200), 500);
        $sync = $request->query('sync', false);
        $total = Product::count();

        if ($total === 0) {
            return response()->json([
                'status' => 'skipped',
                'message' => 'No products found',
            ]);
        }

        if ($sync) {
            // Run synchronously - for when queue worker is not running
            set_time_limit(300); // 5 minutes
            $job = new \App\Jobs\IndexProductsToMeiliJob($chunk);
            $job->handle(app(\App\Services\Search\MeiliClient::class));
            
            return response()->json([
                'status' => 'completed',
                'total_products' => $total,
                'chunk_size' => $chunk,
                'message' => "Indexed {$total} product(s) synchronously",
            ]);
        }

        // Dispatch to queue
        \App\Jobs\IndexProductsToMeiliJob::dispatch($chunk)
            ->onQueue('meili');

        return response()->json([
            'status' => 'dispatched',
            'jobs' => 1,
            'total_products' => $total,
            'chunk_size' => $chunk,
            'message' => "Dispatched 1 job to queue=meili for {$total} product(s)",
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
     * GET /api/diagnostic/chat-sessions
     * List recent chat sessions
     */
    public function chatSessions(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $limit = min((int) $request->query('limit', 20), 100);
        
        $sessions = \App\Models\ChatSession::orderByDesc('last_message_at')
            ->take($limit)
            ->get()
            ->map(fn($s) => [
                'session_id' => $s->session_id,
                'messages_count' => $s->messages_count,
                'status' => $s->status,
                'last_intent' => $s->last_intent,
                'last_message_at' => $s->last_message_at?->format('Y-m-d H:i:s'),
            ]);

        return response()->json([
            'count' => $sessions->count(),
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
                return [
                    'id' => $msg->id,
                    'role' => $msg->role,
                    'content' => $content,
                    'intent' => $msg->meta['intent'] ?? null,
                    'products_shown' => $msg->meta['products_shown'] ?? null,
                    'product_titles' => array_map(fn($p) => $p['title'] ?? '', $msg->meta['products'] ?? []),
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
            ];
        });

        return response()->json([
            'count' => $settingsList->count(),
            'settings' => $settingsList,
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
        
        $stats = [
            'chat_events' => DB::table('chat_events')->count(),
            'chat_conversions' => DB::table('chat_conversions')->count(),
            'ab_test_events' => DB::table('ab_test_events')->count(),
        ];
        
        // Clear all analytics tables
        DB::table('chat_events')->truncate();
        DB::table('chat_conversions')->truncate();
        DB::table('ab_test_events')->truncate();
        
        return response()->json([
            'success' => true,
            'deleted' => $stats,
            'message' => '🔥 All analytics data cleared! Starting fresh.',
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
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
                'platform' => $tenant->platform,
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

        // Get categories
        $categories = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('in_stock', true)
            ->whereNotNull('category_path')
            ->select('category_path', DB::raw('COUNT(*) as count'))
            ->groupBy('category_path')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

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
                'domain' => $widgetSettings->domain,
                'bot_name' => $widgetSettings->bot_name,
                'store_name' => $widgetSettings->store_name,
                'enabled' => $widgetSettings->enabled,
                'horoshop_domain' => $widgetSettings->horoshop_domain,
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
            'Чудово! Допоможу оформити замовлення.',
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
}
