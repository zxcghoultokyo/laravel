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
     */
    public function reindexMeili(Request $request): JsonResponse
    {
        if (!$this->checkKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $chunk = min((int) $request->query('chunk', 500), 1000);
        $total = Product::count();

        if ($total === 0) {
            return response()->json([
                'status' => 'skipped',
                'message' => 'No products found',
            ]);
        }

        $jobs = 0;

        Product::query()
            ->select('id')
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$jobs, $chunk) {
                $fromId = (int) $rows->first()->id;
                $toId   = (int) $rows->last()->id;

                \App\Jobs\IndexProductsToMeiliJob::dispatch($fromId, $toId, $chunk)
                    ->onQueue('meili');

                $jobs++;
            });

        return response()->json([
            'status' => 'dispatched',
            'jobs' => $jobs,
            'total_products' => $total,
            'chunk_size' => $chunk,
            'message' => "Dispatched {$jobs} job(s) to queue=meili for {$total} product(s)",
        ]);
    }
}
