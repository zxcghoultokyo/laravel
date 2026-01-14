<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\ProductAiIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * AI Index Quality Scoring Service
 * 
 * Оцінює якість AI-збагачення товарів та виявляє проблеми.
 */
class EnrichmentQualityService
{
    /**
     * Get overall quality score for AI index (0-100).
     */
    public function getOverallScore(): array
    {
        $stats = $this->getDetailedStats();
        
        // Calculate weighted score
        $score = 0;
        $score += $stats['coverage_percent'] * 0.3;           // 30% - coverage
        $score += $stats['type_coverage_percent'] * 0.25;     // 25% - product_type
        $score += $stats['slang_coverage_percent'] * 0.25;    // 25% - slang  
        $score += $stats['keywords_coverage_percent'] * 0.20; // 20% - keywords
        
        return [
            'score' => round($score, 1),
            'grade' => $this->scoreToGrade($score),
            'stats' => $stats,
        ];
    }
    
    /**
     * Get detailed statistics.
     */
    public function getDetailedStats(): array
    {
        return Cache::remember('enrichment_quality_stats', 300, function () {
            $totalProducts = Product::where('in_stock', true)->count();
            $totalAiIndex = ProductAiIndex::count();
            
            $withProductType = ProductAiIndex::whereNotNull('product_type')
                ->where('product_type', '!=', '')
                ->count();
            
            $withSlang = ProductAiIndex::whereNotNull('slang')
                ->whereRaw("JSON_LENGTH(slang) > 0")
                ->count();
            
            $withKeywords = ProductAiIndex::whereNotNull('keywords')
                ->whereRaw("JSON_LENGTH(keywords) > 0")
                ->count();
            
            $withRawAi = ProductAiIndex::whereNotNull('raw_ai_json')->count();
            
            // Average slang count
            $avgSlangCount = DB::table('product_ai_index')
                ->whereNotNull('slang')
                ->selectRaw('AVG(JSON_LENGTH(slang)) as avg')
                ->value('avg') ?? 0;
            
            // Average keywords count  
            $avgKeywordsCount = DB::table('product_ai_index')
                ->whereNotNull('keywords')
                ->selectRaw('AVG(JSON_LENGTH(keywords)) as avg')
                ->value('avg') ?? 0;
            
            // Product types distribution
            $typeDistribution = ProductAiIndex::whereNotNull('product_type')
                ->selectRaw('product_type, COUNT(*) as count')
                ->groupBy('product_type')
                ->orderByDesc('count')
                ->limit(20)
                ->pluck('count', 'product_type')
                ->toArray();
            
            return [
                'total_products' => $totalProducts,
                'total_ai_index' => $totalAiIndex,
                'coverage_percent' => $totalProducts > 0 ? round(($totalAiIndex / $totalProducts) * 100, 1) : 0,
                
                'with_product_type' => $withProductType,
                'type_coverage_percent' => $totalAiIndex > 0 ? round(($withProductType / $totalAiIndex) * 100, 1) : 0,
                
                'with_slang' => $withSlang,
                'slang_coverage_percent' => $totalAiIndex > 0 ? round(($withSlang / $totalAiIndex) * 100, 1) : 0,
                'avg_slang_count' => round($avgSlangCount, 1),
                
                'with_keywords' => $withKeywords,
                'keywords_coverage_percent' => $totalAiIndex > 0 ? round(($withKeywords / $totalAiIndex) * 100, 1) : 0,
                'avg_keywords_count' => round($avgKeywordsCount, 1),
                
                'with_raw_ai' => $withRawAi,
                'ai_enriched_percent' => $totalAiIndex > 0 ? round(($withRawAi / $totalAiIndex) * 100, 1) : 0,
                
                'type_distribution' => $typeDistribution,
            ];
        });
    }
    
    /**
     * Find products with quality issues.
     */
    public function findProblems(int $limit = 50): array
    {
        $problems = [];
        
        // 1. Products without any AI index
        $noIndex = Product::where('in_stock', true)
            ->whereNotIn('id', ProductAiIndex::select('product_id'))
            ->limit($limit)
            ->get(['id', 'title', 'article', 'category_path']);
        
        if ($noIndex->isNotEmpty()) {
            $problems['no_ai_index'] = [
                'count' => $noIndex->count(),
                'description' => 'Товари без AI-індексу',
                'severity' => 'high',
                'samples' => $noIndex->take(5)->toArray(),
            ];
        }
        
        // 2. AI index without product_type
        $noType = ProductAiIndex::whereNull('product_type')
            ->orWhere('product_type', '')
            ->with('product:id,title,article')
            ->limit($limit)
            ->get();
        
        if ($noType->isNotEmpty()) {
            $problems['no_product_type'] = [
                'count' => $noType->count(),
                'description' => 'AI-індекс без product_type',
                'severity' => 'medium',
                'samples' => $noType->take(5)->map(fn($ai) => [
                    'product_id' => $ai->product_id,
                    'title' => $ai->product->title ?? 'Unknown',
                ])->toArray(),
            ];
        }
        
        // 3. AI index without slang
        $noSlang = ProductAiIndex::where(function ($q) {
                $q->whereNull('slang')
                    ->orWhereRaw("JSON_LENGTH(slang) = 0");
            })
            ->whereNotNull('product_type')
            ->with('product:id,title,article')
            ->limit($limit)
            ->get();
        
        if ($noSlang->isNotEmpty()) {
            $problems['no_slang'] = [
                'count' => $noSlang->count(),
                'description' => 'AI-індекс без slang (погіршує пошук)',
                'severity' => 'medium',
                'samples' => $noSlang->take(5)->map(fn($ai) => [
                    'product_id' => $ai->product_id,
                    'product_type' => $ai->product_type,
                    'title' => $ai->product->title ?? 'Unknown',
                ])->toArray(),
            ];
        }
        
        // 4. Inconsistent product_types (typos, variations)
        $typeVariations = $this->findTypeVariations();
        if (!empty($typeVariations)) {
            $problems['inconsistent_types'] = [
                'count' => count($typeVariations),
                'description' => 'Неконсистентні назви product_type',
                'severity' => 'low',
                'samples' => $typeVariations,
            ];
        }
        
        // 5. Products with very few keywords (< 3)
        $fewKeywords = ProductAiIndex::whereNotNull('keywords')
            ->whereRaw("JSON_LENGTH(keywords) < 3")
            ->whereRaw("JSON_LENGTH(keywords) > 0")
            ->with('product:id,title,article')
            ->limit($limit)
            ->get();
        
        if ($fewKeywords->isNotEmpty()) {
            $problems['few_keywords'] = [
                'count' => $fewKeywords->count(),
                'description' => 'Мало ключових слів (< 3)',
                'severity' => 'low',
                'samples' => $fewKeywords->take(5)->map(fn($ai) => [
                    'product_id' => $ai->product_id,
                    'keywords_count' => count($ai->keywords ?? []),
                    'title' => $ai->product->title ?? 'Unknown',
                ])->toArray(),
            ];
        }
        
        return $problems;
    }
    
    /**
     * Find variations of product_type that might be typos.
     */
    private function findTypeVariations(): array
    {
        $types = ProductAiIndex::whereNotNull('product_type')
            ->selectRaw('product_type, COUNT(*) as count')
            ->groupBy('product_type')
            ->orderBy('product_type')
            ->pluck('count', 'product_type')
            ->toArray();
        
        $variations = [];
        $processed = [];
        
        foreach ($types as $type => $count) {
            if (in_array($type, $processed)) continue;
            
            // Find similar types (Levenshtein distance < 3)
            $similar = [];
            foreach ($types as $otherType => $otherCount) {
                if ($type === $otherType) continue;
                if (in_array($otherType, $processed)) continue;
                
                $distance = levenshtein($type, $otherType);
                if ($distance > 0 && $distance < 3) {
                    $similar[$otherType] = $otherCount;
                    $processed[] = $otherType;
                }
            }
            
            if (!empty($similar)) {
                $variations[] = [
                    'main' => $type,
                    'main_count' => $count,
                    'variations' => $similar,
                ];
            }
            
            $processed[] = $type;
        }
        
        return $variations;
    }
    
    /**
     * Get recommendations for improving quality.
     */
    public function getRecommendations(): array
    {
        $stats = $this->getDetailedStats();
        $recommendations = [];
        
        if ($stats['coverage_percent'] < 80) {
            $missing = $stats['total_products'] - $stats['total_ai_index'];
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Run AI enrichment',
                'description' => "Є {$missing} товарів без AI-індексу",
                'command' => 'php artisan products:build-ai-index --only-missing',
            ];
        }
        
        if ($stats['slang_coverage_percent'] < 70) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'Improve slang coverage',
                'description' => 'Багато товарів без slang - погіршує пошук',
                'command' => 'php artisan products:build-ai-index --incomplete',
            ];
        }
        
        if ($stats['avg_slang_count'] < 3) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'Augment slang from dictionary',
                'description' => "Середня кількість slang: {$stats['avg_slang_count']} (бажано > 5)",
                'command' => 'Перевірити config/slang_dictionary.php',
            ];
        }
        
        if ($stats['type_coverage_percent'] < 90) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'Fix missing product_type',
                'description' => 'Товари без product_type не фільтруються правильно',
                'command' => 'php artisan products:build-ai-index --incomplete',
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Convert score to grade.
     */
    private function scoreToGrade(float $score): string
    {
        return match(true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }
    
    /**
     * Clear cache.
     */
    public function clearCache(): void
    {
        Cache::forget('enrichment_quality_stats');
    }
}
