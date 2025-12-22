<?php

namespace App\Services\Search;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BrandDetectionService
{
    /**
     * Get all unique brands from database (cached for 24h)
     */
    public function getAllBrands(): array
    {
        return Cache::remember('brands:all', now()->addHours(24), function () {
            try {
                // Try to get from brands table first
                $brands = Brand::where('is_active', true)
                    ->orderByDesc('product_count')
                    ->pluck('name')
                    ->toArray();
                
                if (!empty($brands)) {
                    Log::info('BrandDetectionService: loaded brands from brands table', [
                        'count' => count($brands),
                    ]);
                    return $brands;
                }
                
                // Fallback to products.brand if brands table is empty
                Log::warning('BrandDetectionService: brands table empty, using products.brand');
                $brands = Product::whereNotNull('brand')
                    ->where('brand', '!=', '')
                    ->select('brand')
                    ->selectRaw('COUNT(*) as product_count')
                    ->groupBy('brand')
                    ->orderByDesc('product_count')
                    ->get()
                    ->pluck('brand')
                    ->toArray();
                
                if (!empty($brands)) {
                    Log::info('BrandDetectionService: loaded brands from products table', [
                        'count' => count($brands),
                    ]);
                    return $brands;
                }
                
                // Last resort: hardcoded list
                Log::warning('BrandDetectionService: no brands in DB, using hardcoded list');
                return $this->getHardcodedBrands();
                
            } catch (\Exception $e) {
                Log::error('BrandDetectionService: failed to load brands', [
                    'error' => $e->getMessage(),
                ]);
                
                // Fallback to hardcoded list
                return $this->getHardcodedBrands();
            }
        });
    }

    /**
     * Detect if query contains a brand name
     * Returns [isBrand, brandName, enhancedQuery]
     */
    public function detectBrand(string $query): array
    {
        $queryLower = mb_strtolower(trim($query));
        
        // Remove "бренд" prefix if present
        $queryClean = preg_replace('/^бренд\s+/u', '', $queryLower);
        
        $brands = $this->getAllBrands();
        
        // Check if query matches a brand (exact or partial)
        foreach ($brands as $brand) {
            $brandLower = mb_strtolower($brand);
            
            // Exact match
            if ($queryClean === $brandLower) {
                return [
                    'is_brand' => true,
                    'brand' => $brand,
                    'enhanced_query' => $brand . ' ' . $brand . ' ' . $brand, // Boost by repetition
                ];
            }
            
            // Brand at start
            if (str_starts_with($queryClean, $brandLower . ' ')) {
                return [
                    'is_brand' => true,
                    'brand' => $brand,
                    'enhanced_query' => $brand . ' ' . $brand . ' ' . $queryClean,
                ];
            }
            
            // Brand at end
            if (str_ends_with($queryClean, ' ' . $brandLower)) {
                return [
                    'is_brand' => true,
                    'brand' => $brand,
                    'enhanced_query' => $brand . ' ' . $brand . ' ' . $queryClean,
                ];
            }
            
            // Brand in middle
            if (str_contains($queryClean, ' ' . $brandLower . ' ')) {
                return [
                    'is_brand' => true,
                    'brand' => $brand,
                    'enhanced_query' => $brand . ' ' . $brand . ' ' . $queryClean,
                ];
            }
        }
        
        return [
            'is_brand' => false,
            'brand' => null,
            'enhanced_query' => $query,
        ];
    }

    /**
     * Fallback hardcoded brands if DB is unavailable
     */
    private function getHardcodedBrands(): array
    {
        return [
            'АТАКА',
            'Abrams',
            'Hoffmann',
            'ELMON',
            'RAGNAROK',
            'Condor',
            '5.11',
            'Salomon',
            'KOMBAT',
            'Carinthia',
            'Ops-Core',
            'SESTAN BUSCH',
            'FirstSpear',
            'Crye Precision',
            'Mystery Ranch',
            'Helikon-Tex',
            'Pentagon',
            'Warrior Assault Systems',
        ];
    }

    /**
     * Clear brands cache
     */
    public function clearCache(): void
    {
        Cache::forget('brands:all');
    }
}
