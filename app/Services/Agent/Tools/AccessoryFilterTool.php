<?php

namespace App\Services\Agent\Tools;

use Illuminate\Support\Facades\Log;

class AccessoryFilterTool
{
    /**
     * Downrank accessories relative to main products
     * Uses ai_product_type if available, fallback to heuristics
     * NEVER hardcode - use ai_product_type or category_path tokens
     */
    public function downrankAccessories(array $candidates, array $hint): array
    {
        if (empty($candidates)) {
            return [];
        }
        
        $query = mb_strtolower($hint['query'] ?? '');
        
        Log::info('AccessoryFilterTool: processing', [
            'count' => count($candidates),
            'query' => $query
        ]);
        
        // Determine primary product type from query
        $primaryType = $this->detectPrimaryType($query);
        
        if (!$primaryType) {
            // No clear primary type - return as is
            return $candidates;
        }
        
        Log::info('AccessoryFilterTool: detected primary type', ['type' => $primaryType]);
        
        // Score each product
        $scored = array_map(function ($product) use ($primaryType) {
            $productType = $this->getProductType($product);
            
            if ($productType === $primaryType) {
                // Main product - keep high score
                $product['_accessory_score'] = 1000;
            } elseif ($this->isAccessoryOf($productType, $primaryType)) {
                // Accessory - downrank
                $product['_accessory_score'] = 100;
            } else {
                // Unrelated - downrank more
                $product['_accessory_score'] = 50;
            }
            
            return $product;
        }, $candidates);
        
        // Sort by accessory score (descending)
        usort($scored, function ($a, $b) {
            return $b['_accessory_score'] <=> $a['_accessory_score'];
        });
        
        // Remove temp score field
        foreach ($scored as &$product) {
            unset($product['_accessory_score']);
        }
        
        return $scored;
    }
    
    /**
     * Detect primary product type from query
     */
    private function detectPrimaryType(string $query): ?string
    {
        $typeKeywords = [
            'plates' => ['плит', 'броня', 'керамік', 'сталь', 'композит', 'sapi', 'esapi'],
            'helmets' => ['шолом', 'каск', 'helmet', 'баллістичний', 'bump'],
            'plate-carriers' => ['плитоноск', 'носій', 'carrier', 'разгрузка', 'РПС'],
            'vests' => ['жилет', 'бронік', 'vest'],
            'pouches' => ['підсумок', 'pouch', 'mag'],
            'holsters' => ['кобура', 'holster'],
        ];
        
        foreach ($typeKeywords as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($query, $keyword)) {
                    return $type;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get product type from ai_product_type or fallback to heuristics
     */
    private function getProductType(array $product): string
    {
        $aiType = $product['ai_product_type'] ?? '__unknown__';
        
        if ($aiType && $aiType !== '__unknown__') {
            return $aiType;
        }
        
        // Fallback: analyze category_path and title
        $categoryPath = mb_strtolower($product['category_path'] ?? '');
        $title = mb_strtolower($product['title'] ?? '');
        $combined = $categoryPath . ' ' . $title;
        
        if (str_contains($combined, 'плит') || str_contains($combined, 'plate')) {
            // Check if it's accessory or main
            if (str_contains($combined, 'панел') || str_contains($combined, 'чохол') || str_contains($combined, 'підсумок')) {
                return 'plate-accessory';
            }
            return 'plates';
        }
        
        if (str_contains($combined, 'шолом') || str_contains($combined, 'каск') || str_contains($combined, 'helmet')) {
            if (str_contains($combined, 'кріплення') || str_contains($combined, 'mount') || str_contains($combined, 'рейка')) {
                return 'helmet-accessory';
            }
            return 'helmets';
        }
        
        if (str_contains($combined, 'носій') || str_contains($combined, 'carrier') || str_contains($combined, 'плитоноск')) {
            return 'plate-carriers';
        }
        
        if (str_contains($combined, 'підсумок') || str_contains($combined, 'pouch')) {
            return 'pouches';
        }
        
        if (str_contains($combined, 'кобура') || str_contains($combined, 'holster')) {
            return 'holsters';
        }
        
        return '__unknown__';
    }
    
    /**
     * Check if productType is an accessory of primaryType
     */
    private function isAccessoryOf(string $productType, string $primaryType): bool
    {
        $accessoryMap = [
            'plates' => ['plate-accessory', 'pouches', 'plate-carriers'], // carriers can hold plates
            'helmets' => ['helmet-accessory', 'pouches'],
            'plate-carriers' => ['pouches', 'plates'], // carriers need plates
            'vests' => ['pouches'],
        ];
        
        return in_array($productType, $accessoryMap[$primaryType] ?? []);
    }
}
