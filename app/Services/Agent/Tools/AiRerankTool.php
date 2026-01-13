<?php

namespace App\Services\Agent\Tools;

use App\Services\Ai\AiRouter;
use App\Services\Store\StoreContextService;
use Illuminate\Support\Facades\Log;

class AiRerankTool
{
    private StoreContextService $storeContext;
    
    public function __construct(private AiRouter $aiRouter, ?StoreContextService $storeContext = null)
    {
        $this->storeContext = $storeContext ?? app(StoreContextService::class);
    }

    /**
     * Use AI to re-rank products based on query relevance
     * Takes 40 candidates, returns top 3-10 most relevant (dynamic based on quality)
     */
    public function rerank(array $candidates, string $query, array $filters = [], int $limit = 10): array
    {
        if (count($candidates) <= 3) {
            return $candidates; // No need to re-rank
        }

        // Pre-filter: if query contains specific product model names, prioritize exact matches
        $exactMatches = $this->findExactTitleMatches($candidates, $query);
        if (!empty($exactMatches)) {
            Log::info('AiRerankTool: exact title matches found', [
                'query' => $query,
                'exact_matches' => count($exactMatches),
            ]);
            // If we have enough exact matches, return them without AI
            if (count($exactMatches) >= 2) {
                return array_slice($exactMatches, 0, min(5, count($exactMatches)));
            }
            // Otherwise, prioritize exact matches in candidates before AI re-ranking
            $candidates = array_merge($exactMatches, array_filter($candidates, function ($c) use ($exactMatches) {
                return !in_array($c['id'] ?? null, array_column($exactMatches, 'id'));
            }));
        }

        try {
            // Detect brand from query
            $detectedBrand = $this->detectBrandFromQuery($query, $candidates);
            
            // If explicit brand query (e.g., "hoffmann", "атака") — filter strictly
            if ($detectedBrand && $this->isExplicitBrandQuery($query, $detectedBrand)) {
                $beforeCount = count($candidates);
                $candidates = $this->filterByBrand($candidates, $detectedBrand);
                
                Log::info('AiRerankTool: explicit brand filter applied', [
                    'brand' => $detectedBrand,
                    'before' => $beforeCount,
                    'after' => count($candidates),
                    'query' => $query,
                ]);
            }
            
            $prompt = $this->buildRerankPrompt($candidates, $query, $filters, $detectedBrand);
            $response = $this->aiRouter->callOpenAI($prompt, 0.3);
            
            $result = json_decode($response, true);
            
            if (!is_array($result) || !isset($result['chosen_ids'])) {
                Log::warning('AiRerankTool: Invalid AI response, using original order', [
                    'response' => $response
                ]);
                return array_slice($candidates, 0, min(3, count($candidates)));
            }
            
            // Reorder candidates based on AI choices
            $chosenIds = $result['chosen_ids'];
            $reranked = [];
            $reasoning = $result['reasoning'] ?? [];
            
            foreach ($chosenIds as $idx => $id) {
                $candidate = $this->findCandidateById($candidates, $id);
                if ($candidate) {
                    $candidate['ai_score'] = count($chosenIds) - $idx; // Higher position = higher score
                    $candidate['ai_reasoning'] = $reasoning[$id] ?? null;
                    $reranked[] = $candidate;
                }
            }
            
            // Don't add non-chosen candidates anymore — only show what AI selected
            
            Log::info('AiRerankTool: reranked', [
                'original_count' => count($candidates),
                'reranked_count' => count($reranked),
                'chosen_ids' => $chosenIds,
                'ai_selected' => count($chosenIds),
                'detected_brand' => $detectedBrand ?? 'none',
            ]);
            
            return $reranked;
            
        } catch (\Exception $e) {
            Log::error('AiRerankTool: error, using original order', [
                'error' => $e->getMessage(),
            ]);
            return array_slice($candidates, 0, $limit);
        }
    }

    /**
     * Find candidates whose title contains significant tokens from the query
     * E.g., query="схід 24" should match "Плитоноска 'Схід 24' Піксель"
     */
    private function findExactTitleMatches(array $candidates, string $query): array
    {
        // Extract significant words from query (3+ chars, skip common words)
        $tokens = preg_split('/[\s\p{P}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_filter($tokens, fn($t) => mb_strlen($t) >= 2);
        $commonWords = ['для', 'від', 'до', 'та', 'або', 'що', 'як', 'коли', 'де', 'у', 'в'];
        $tokens = array_filter($tokens, fn($t) => !in_array($t, $commonWords));
        
        if (empty($tokens)) {
            return [];
        }

        $exactMatches = [];
        foreach ($candidates as $c) {
            $title = mb_strtolower($c['title'] ?? '');
            $matchCount = 0;
            foreach ($tokens as $token) {
                if (str_contains($title, $token)) {
                    $matchCount++;
                }
            }
            // If at least 50% of query tokens found in title, it's a match
            if ($matchCount >= ceil(count($tokens) * 0.5)) {
                $c['_match_score'] = $matchCount / count($tokens);
                $exactMatches[] = $c;
            }
        }
        
        // Sort by match score descending
        usort($exactMatches, fn($a, $b) => ($b['_match_score'] ?? 0) <=> ($a['_match_score'] ?? 0));
        return $exactMatches;
    }

    private function buildRerankPrompt(array $candidates, string $query, array $filters, ?string $detectedBrand = null): string
    {
        // Use dynamic prompt from StoreContextService (no hardcoded store-specific data!)
        return $this->storeContext->buildRerankPrompt($candidates, $query, $filters, $detectedBrand);
    }

    private function findCandidateById(array $candidates, int $id): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['id'] === $id) {
                return $candidate;
            }
        }
        return null;
    }
    
    /**
     * Detect brand from query by checking if any candidate brand appears in query
     */
    private function detectBrandFromQuery(string $query, array $candidates): ?string
    {
        $query = mb_strtolower(trim($query));
        
        // Collect unique brands from candidates
        $brands = [];
        foreach ($candidates as $c) {
            if (!empty($c['brand'])) {
                $brands[$c['brand']] = mb_strtolower($c['brand']);
            }
        }
        
        // Check if any brand appears in query
        foreach ($brands as $originalBrand => $brandLower) {
            if (str_contains($query, $brandLower)) {
                return $originalBrand; // Return original case
            }
        }
        
        return null;
    }
    
    /**
     * Check if query is EXPLICITLY about a brand (brand is main keyword)
     * Examples: "hoffmann", "атака плитоноска" → true
     * Counter: "плитоноска зелена" → false (no brand)
     */
    private function isExplicitBrandQuery(string $query, string $brand): bool
    {
        $query = mb_strtolower(trim($query));
        $brand = mb_strtolower($brand);
        
        // Query IS the brand (e.g., "hoffmann")
        if ($query === $brand) {
            return true;
        }
        
        // Query starts with brand (e.g., "hoffmann патчі")
        if (str_starts_with($query, $brand)) {
            return true;
        }
        
        // Query ends with brand (e.g., "патчі hoffmann")
        if (str_ends_with($query, $brand)) {
            return true;
        }
        
        // Brand is standalone word in query (surrounded by spaces)
        if (preg_match('/\b' . preg_quote($brand, '/') . '\b/ui', $query)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Filter candidates to keep only specified brand
     */
    private function filterByBrand(array $candidates, string $brand): array
    {
        $brandLower = mb_strtolower($brand);
        
        return array_values(array_filter($candidates, function($c) use ($brandLower) {
            if (empty($c['brand'])) {
                return false;
            }
            return mb_strtolower($c['brand']) === $brandLower;
        }));
    }
}
