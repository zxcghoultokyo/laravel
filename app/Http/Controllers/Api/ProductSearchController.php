<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiRouter;
use App\Services\Horoshop\ProductService;
use App\Services\Search\ProductSearchEngine;
use App\Services\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductSearchController extends Controller
{
    public function index(
        Request $request,
        ProductSearchEngine $engine,
        ProductService $productService,
        AiRouter $aiRouter
    ) {
        $q = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min(50, $limit));

        $categoryId = $request->query('category_id');
        $categoryId = is_null($categoryId) ? null : (int) $categoryId;

        $language = (string) $request->query('lang', 'uk');
        if (!in_array($language, ['uk', 'ua', 'ru', 'en'], true)) {
            $language = 'uk';
        }

        if ($q === '') {
            return response()->json([
                'type' => 'products',
                'query' => $q,
                'limit' => $limit,
                'estimated_total' => 0,
                'products' => [],
            ]);
        }

        /** @var \App\Services\Search\SearchQueryParser $parser */
        $parser = app(\App\Services\Search\SearchQueryParser::class);
        
        // Get tenant ID from context
        $tenantId = app(TenantContext::class)->getTenantId();

        $parsed = $parser->parse($q, $language, null, $tenantId);
        if (($parsed['normalized'] ?? '') === '') {
            return response()->json([
                'type' => 'products',
                'query' => $q,
                'limit' => $limit,
                'estimated_total' => 0,
                'products' => [],
            ]);
        }

        // ✅ AI intent: напряму через AiRouter (без protected-методів ProductService)
        $parsed['ai_intent'] = $aiRouter->parseProductSearchIntent((string) ($parsed['normalized'] ?? $q));

        Log::info('API /search/products', [
            'q' => $q,
            'limit' => $limit,
            'category_id' => $categoryId,
            'lang' => $language,
            'parsed' => [
                'normalized' => $parsed['normalized'] ?? null,
                'expanded' => $parsed['expanded'] ?? null,
                'signals' => $parsed['signals'] ?? null,
                'ai_intent' => $parsed['ai_intent'] ?? null,
            ],
        ]);

        $rows = $engine->search($parsed, $categoryId, $limit);

        $products = $rows->map(function (array $row) use ($productService) {
            /** @var \App\Models\Product $p */
            $p = $row['product'];
            return $productService->normalizeProductForApi($p);
        })->values()->all();

        return response()->json([
            'type' => 'products',
            'query' => $q,
            'limit' => $limit,
            'estimated_total' => count($products),
            'products' => $products,
        ]);
    }
}
