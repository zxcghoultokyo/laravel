<?php

namespace App\Services\Agent\Tools;

use Illuminate\Support\Facades\Log;

class DeduperTool
{
    /**
     * Deduplicate products by parent_article, then article, then id
     * Keeps the best variant based on scoring
     */
    public function dedupe(array $candidates): array
    {
        if (empty($candidates)) {
            return [];
        }
        
        Log::info('DeduperTool: deduplicating', ['count' => count($candidates)]);
        
        // Group by parent_article (if exists), otherwise by article
        $groups = [];
        
        foreach ($candidates as $candidate) {
            $key = $candidate['parent_article'] ?? $candidate['article'] ?? $candidate['id'];
            
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            
            $groups[$key][] = $candidate;
        }
        
        // For each group, pick the best variant
        $deduped = [];
        
        foreach ($groups as $key => $variants) {
            if (count($variants) === 1) {
                $deduped[] = $variants[0];
                continue;
            }
            
            // Score each variant
            usort($variants, function ($a, $b) {
                return $this->score($b) <=> $this->score($a);
            });
            
            $deduped[] = $variants[0];
        }
        
        Log::info('DeduperTool: deduplicated', [
            'before' => count($candidates),
            'after' => count($deduped)
        ]);
        
        return $deduped;
    }
    
    /**
     * Score a product variant
     * Higher is better
     */
    private function score(array $product): float
    {
        $score = 0;
        
        // In stock is critical
        if ($product['in_stock'] ?? false) {
            $score += 1000;
        }
        
        // Display in showcase is important
        if ($product['display_in_showcase'] ?? false) {
            $score += 500;
        }
        
        // Popularity matters
        $score += ($product['popularity'] ?? 0) * 10;
        
        // Prefer products with lower prices (more accessible)
        // But not too much weight
        $price = $product['price'] ?? 0;
        if ($price > 0) {
            $score -= $price / 1000; // Small penalty for expensive items
        }
        
        return $score;
    }
}
