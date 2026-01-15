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

        $stats = [
            'total_products' => Product::count(),
            'in_stock' => Product::where('in_stock', true)->count(),
            'with_color' => Product::where('in_stock', true)->whereNotNull('color')->where('color', '!=', '')->count(),
            'with_size' => Product::where('in_stock', true)->whereNotNull('size')->where('size', '!=', '')->count(),
            'with_parent_article' => Product::where('in_stock', true)->whereNotNull('parent_article')->where('parent_article', '!=', '')->count(),
            'unique_colors' => Product::where('in_stock', true)->whereNotNull('color')->where('color', '!=', '')->distinct()->pluck('color')->toArray(),
            'categories_count' => Product::where('in_stock', true)->distinct('category_path')->count('category_path'),
        ];

        return response()->json($stats);
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

        $products = Product::where('in_stock', true)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('search_index', 'like', "%{$query}%")
                  ->orWhere('category_path', 'like', "%{$query}%");
            })
            ->limit($limit)
            ->get(['id', 'article', 'title', 'price', 'category_path', 'color', 'size', 'brand', 'in_stock']);

        return response()->json([
            'query' => $query,
            'count' => $products->count(),
            'products' => $products,
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

        try {
            $meili = app(MeiliClient::class);
            $index = $meili->client()->index('products');

            $stats = $index->getStats();
            $settings = $index->getSettings();

            return response()->json([
                'documents' => $stats['numberOfDocuments'],
                'is_indexing' => $stats['isIndexing'],
                'field_distribution' => $stats['fieldDistribution'] ?? [],
                'filterable_attributes' => $settings['filterableAttributes'] ?? [],
                'searchable_attributes' => $settings['searchableAttributes'] ?? [],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'meili_enabled' => config('meilisearch.enabled', false),
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

        $session = \App\Models\ChatSession::where('session_id', $sessionId)->first();
        
        if (!$session) {
            return response()->json([
                'error' => 'Session not found',
                'session_id' => $sessionId,
            ], 404);
        }

        $messages = \App\Models\ChatMessage::where('chat_session_id', $session->id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'role' => $msg->role,
                    'content' => mb_substr($msg->content, 0, 500) . (mb_strlen($msg->content) > 500 ? '...' : ''),
                    'intent' => $msg->meta['intent'] ?? null,
                    'products_shown' => $msg->meta['products_shown'] ?? null,
                    'product_titles' => array_map(fn($p) => $p['title'] ?? '', $msg->meta['products'] ?? []),
                    'created_at' => $msg->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json([
            'session_id' => $sessionId,
            'db_id' => $session->id,
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

        $settings = \App\Models\WidgetSettings::first();
        if (!$settings) {
            return response()->json(['error' => 'No WidgetSettings found'], 404);
        }

        return response()->json([
            'store_name' => $settings->store_name,
            'store_description' => $settings->store_description,
            'shop_phone' => $settings->shop_phone,
            'store_hours' => $settings->store_hours,
            'faq_urls' => [
                'payment_delivery' => $settings->faq_payment_delivery_url,
                'returns' => $settings->faq_returns_url,
                'contacts' => $settings->faq_contacts_url,
                'about' => $settings->faq_about_url,
            ],
            'faq_texts' => [
                'payment_delivery' => $settings->faq_payment_delivery_text,
                'returns' => $settings->faq_returns_text,
                'contacts' => $settings->faq_contacts_text,
                'about' => $settings->faq_about_text,
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
}
