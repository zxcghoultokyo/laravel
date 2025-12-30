<?php

namespace App\Services\Search;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BrandDetectionService
{
    /**
     * Cyrillic → Latin brand transliteration map
     * Used to normalize user queries with Ukrainian brand names
     */
    private const BRAND_TRANSLITERATION = [
        // Ops-Core variants
        'опс коре' => 'Ops-Core',
        'опскор' => 'Ops-Core',
        'опс-кор' => 'Ops-Core',
        // SESTAN BUSCH
        'сестан буш' => 'SESTAN BUSCH',
        'сестан' => 'SESTAN BUSCH',
        'сестанбуш' => 'SESTAN BUSCH',
        // Salomon
        'салзмон' => 'Salomon',
        'саломон' => 'Salomon',
        'саломан' => 'Salomon',
        // FirstSpear
        'фірст спір' => 'FirstSpear',
        'фірстспір' => 'FirstSpear',
        'ферст спір' => 'FirstSpear',
        // Crye Precision
        'край' => 'Crye Precision',
        'край пресижн' => 'Crye Precision',
        'край' => 'Crye Precision',
        // 3M Peltor
        'пелтор' => '3M Peltor',
        '3м пелтор' => '3M Peltor',
        // ESAPI
        'есапі' => 'ESAPI',
        'есапай' => 'ESAPI',
        'ісапі' => 'ESAPI',
        // Gentex
        'гентекс' => 'Gentex',
        'генте' => 'Gentex',
        // Helikon-Tex
        'хелікон' => 'Helikon-Tex',
        'геликон' => 'Helikon-Tex',
        // Pentagon
        'пентагон' => 'Pentagon',
        // Condor
        'кондор' => 'Condor',
        // Carinthia
        'карінтія' => 'Carinthia',
        'каринтія' => 'Carinthia',
        // Mystery Ranch
        'містері ранч' => 'Mystery Ranch',
        // 5.11
        '5.11' => '5.11',
        'файв елевен' => '5.11',
        // АТАКА (Ukrainian brand)
        'атака' => 'АТАКА',
        // ELMON
        'елмон' => 'ELMON',
        // Warrior Assault Systems
        'воріор' => 'Warrior Assault Systems',
        'варіор' => 'Warrior Assault Systems',
        // Petzl
        'петцль' => 'Petzl',
        'пецл' => 'Petzl',
        // SMT
        'смт' => 'SMT',
        // UaR
        'уар' => 'UaR',
        'юар' => 'UaR',
        // Templars Gear
        'темпларс' => 'Templars Gear',
        'темплари' => 'Templars Gear',
        // ArmorCom
        'арморком' => 'ArmorCom',
        // Hailex
        'хайлікс' => 'Hailex',
        'хайлекс' => 'Hailex',
        // Aegis
        'аегіс' => 'Aegis',
        'аегис' => 'Aegis',
        // Hardwire
        'хардвайр' => 'Hardwire',
    ];

    /**
     * Transliterate cyrillic brand name to latin
     * @return string|null Latin brand name or null if not found
     */
    public function transliterateBrand(string $query): ?string
    {
        $queryLower = mb_strtolower(trim($query));
        
        // Direct lookup
        if (isset(self::BRAND_TRANSLITERATION[$queryLower])) {
            return self::BRAND_TRANSLITERATION[$queryLower];
        }
        
        // Check if query contains a cyrillic brand
        foreach (self::BRAND_TRANSLITERATION as $cyrillic => $latin) {
            if (str_contains($queryLower, $cyrillic)) {
                return $latin;
            }
        }
        
        return null;
    }

    /**
     * Replace cyrillic brand names in query with latin equivalents
     */
    public function normalizeBrandsInQuery(string $query): string
    {
        $queryLower = mb_strtolower(trim($query));
        $result = $query;
        
        // Sort by length descending to match longer phrases first
        $sorted = self::BRAND_TRANSLITERATION;
        uksort($sorted, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        
        foreach ($sorted as $cyrillic => $latin) {
            if (str_contains($queryLower, $cyrillic)) {
                // Replace preserving case context
                $result = preg_replace('/' . preg_quote($cyrillic, '/') . '/iu', $latin, $result);
                $queryLower = mb_strtolower($result);
            }
        }
        
        return $result;
    }

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
