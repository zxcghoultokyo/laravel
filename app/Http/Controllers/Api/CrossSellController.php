<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CrossSell\CrossSellService;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CrossSellController extends Controller
{
    public function __construct(
        protected CrossSellService $crossSellService,
    ) {}

    /**
     * Get cross-sell suggestions for a product.
     * Called asynchronously after main chat response.
     * 
     * GET /api/cross-sell?product_id=123
     * or
     * GET /api/cross-sell?article=ABC123
     */
    public function suggestions(Request $request)
    {
        $productId = $request->get('product_id');
        $article = $request->get('article');
        
        if (!$productId && !$article) {
            return response()->json([
                'success' => false,
                'error' => 'product_id or article required',
            ], 400);
        }

        // Find product
        $product = null;
        if ($productId) {
            $product = Product::find($productId);
        } elseif ($article) {
            $product = Product::where('article', $article)->first();
        }

        if (!$product) {
            return response()->json([
                'success' => false,
                'error' => 'Product not found',
            ], 404);
        }

        // Cache key based on product
        $cacheKey = 'cross_sell:' . $product->id;
        
        // Try cache first (cross-sell is expensive)
        $crossSell = Cache::remember($cacheKey, 300, function () use ($product) {
            try {
                $suggestions = $this->crossSellService->getSuggestions($product, 4);
                
                if (empty($suggestions)) {
                    return null;
                }
                
                return $this->crossSellService->formatForChat($suggestions, $product);
            } catch (\Throwable $e) {
                Log::error('CrossSellController: failed to get suggestions', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });

        if (!$crossSell) {
            return response()->json([
                'success' => true,
                'cross_sell' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'cross_sell' => $crossSell,
        ]);
    }
}
