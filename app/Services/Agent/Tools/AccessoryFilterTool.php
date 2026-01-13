<?php

namespace App\Services\Agent\Tools;

use App\Services\Store\StoreContextService;
use Illuminate\Support\Facades\Log;

/**
 * Tool for downranking accessories relative to main products.
 * 
 * REDESIGNED: Uses ai_product_type from database instead of hardcoded heuristics.
 * Falls back to store context config for accessory keywords detection.
 */
class AccessoryFilterTool
{
    private StoreContextService $storeContext;
    
    public function __construct(?StoreContextService $storeContext = null)
    {
        $this->storeContext = $storeContext ?? app(StoreContextService::class);
    }
    
    /**
     * Downrank accessories relative to main products.
     * 
     * Strategy:
     * 1. Use ai_product_type field if available (most reliable)
     * 2. Check store config for accessory/main keywords
     * 3. Let AI handle complex cases (no hardcoded business logic)
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
        
        // Count products by ai_product_type to find the dominant type
        $typeCounts = [];
        foreach ($candidates as $c) {
            $type = $c['ai_product_type'] ?? '';
            if (!empty($type)) {
                $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            }
        }
        
        // If we have ai_product_type data, use it for smart sorting
        if (!empty($typeCounts)) {
            return $this->sortByAiProductType($candidates, $typeCounts, $query);
        }
        
        // Fallback: use store context config for accessory detection
        return $this->sortByStoreConfig($candidates, $query);
    }
    
    /**
     * Sort candidates using ai_product_type field.
     * Main products first, accessories last.
     */
    private function sortByAiProductType(array $candidates, array $typeCounts, string $query): array
    {
        // Find dominant type (most frequent, likely what user wants)
        $dominantType = '';
        $maxCount = 0;
        foreach ($typeCounts as $type => $count) {
            if ($count > $maxCount) {
                $maxCount = $count;
                $dominantType = $type;
            }
        }
        
        Log::info('AccessoryFilterTool: dominant type from ai_product_type', [
            'dominant' => $dominantType,
            'counts' => $typeCounts
        ]);
        
        // Score candidates based on type match
        $scored = array_map(function ($product) use ($dominantType) {
            $productType = $product['ai_product_type'] ?? '';
            
            // Exact match with dominant type
            if ($productType === $dominantType) {
                $product['_accessory_score'] = 1000;
            }
            // Has type but different
            elseif (!empty($productType)) {
                // Check if it's likely an accessory (contains "accessory", "аксесуар", etc.)
                if ($this->looksLikeAccessory($productType)) {
                    $product['_accessory_score'] = 100;
                } else {
                    $product['_accessory_score'] = 500; // Different main category
                }
            }
            // No ai_product_type - neutral
            else {
                $product['_accessory_score'] = 300;
            }
            
            return $product;
        }, $candidates);
        
        return $this->sortAndClean($scored);
    }
    
    /**
     * Sort candidates using store config accessory keywords.
     */
    private function sortByStoreConfig(array $candidates, string $query): array
    {
        $ctx = $this->storeContext->getContext();
        $accessoryKeywords = $ctx['accessory_keywords'] ?? [];
        $mainKeywords = $ctx['main_product_keywords'] ?? [];
        
        // If no config, return as-is (let AI rerank handle it)
        if (empty($accessoryKeywords) && empty($mainKeywords)) {
            Log::info('AccessoryFilterTool: no store config, skipping filter');
            return $candidates;
        }
        
        $scored = array_map(function ($product) use ($accessoryKeywords, $mainKeywords) {
            $title = mb_strtolower($product['title'] ?? '');
            $category = mb_strtolower($product['category_path'] ?? '');
            $combined = $title . ' ' . $category;
            
            // Check main product keywords first
            foreach ($mainKeywords as $keyword) {
                if (str_contains($combined, mb_strtolower($keyword))) {
                    $product['_accessory_score'] = 1000;
                    return $product;
                }
            }
            
            // Check accessory keywords
            foreach ($accessoryKeywords as $keyword) {
                if (str_contains($combined, mb_strtolower($keyword))) {
                    $product['_accessory_score'] = 100;
                    return $product;
                }
            }
            
            // Neutral
            $product['_accessory_score'] = 500;
            return $product;
        }, $candidates);
        
        return $this->sortAndClean($scored);
    }
    
    /**
     * Check if ai_product_type looks like an accessory type.
     */
    private function looksLikeAccessory(string $productType): bool
    {
        $accessoryIndicators = [
            'accessory', 'аксесуар', 'аксессуар',
            '-acc', '_acc',
            'ремін', 'strap', 'sling',
            'кріплен', 'mount', 'adapter',
            'чохол', 'cover', 'case',
            'панел', 'panel',
            'патч', 'patch', 'шеврон',
        ];
        
        $typeLower = mb_strtolower($productType);
        foreach ($accessoryIndicators as $indicator) {
            if (str_contains($typeLower, $indicator)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Sort by score descending and remove temp field.
     */
    private function sortAndClean(array $scored): array
    {
        usort($scored, function ($a, $b) {
            return ($b['_accessory_score'] ?? 0) <=> ($a['_accessory_score'] ?? 0);
        });
        
        foreach ($scored as &$product) {
            unset($product['_accessory_score']);
        }
        
        return $scored;
    }
}
