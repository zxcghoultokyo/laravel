<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Horoshop\ProductService;
use App\Services\Search\ProductSearchEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductSearchController extends Controller
{
    public function index(Request $request, ProductSearchEngine $engine, ProductService $productService)
    {
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

        // parse -> normalized/expanded/signals
        $parsed = $parser->parse($q, $language, null);

        // AI intent (опціонально, але дає шанс відсікати “плити” vs “бронежилет” через ai_product_type)
        $parsed['ai_intent'] = $productService->detectProductTypes((string)($parsed['normalized'] ?? $q));

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
