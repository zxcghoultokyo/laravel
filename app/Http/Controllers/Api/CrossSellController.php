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
        $tenantId = $request->get('tenant_id');
        
        if (!$productId && !$article) {
            return response()->json([
                'success' => false,
                'error' => 'product_id or article required',
            ], 400);
        }

        // Set tenant_id for filtering
        if ($tenantId) {
            $this->crossSellService->setTenantId((int) $tenantId);
        }

        // Find product
        $product = null;
        $query = Product::query();
        
        // Apply tenant filter
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        if ($productId) {
            $product = $query->where('id', $productId)->first();
        } elseif ($article) {
            $product = $query->where('article', $article)->first();
        }

        if (!$product) {
            return response()->json([
                'success' => false,
                'error' => 'Product not found',
            ], 404);
        }

        // Cache key based on product and tenant
        $cacheKey = 'cross_sell:' . $product->id . ':t' . ($tenantId ?? 'all');
        
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
