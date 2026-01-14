<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;

/**
 * Slang Dictionary Service
 * 
 * Provides access to the slang dictionary and methods to augment search queries.
 */
class SlangDictionaryService
{
    protected array $dictionary;
    
    public function __construct()
    {
        $this->dictionary = Cache::remember('slang_dictionary', 3600, function () {
            return config('slang_dictionary', []);
        });
    }
    
    /**
     * Get all slang terms for a product type.
     */
    public function getSlangForType(string $productType): array
    {
        $entry = $this->dictionary[$productType] ?? [];
        
        return array_merge(
            $entry['slang'] ?? [],
            $entry['synonyms'] ?? [],
            $entry['typos'] ?? [],
            $entry['en'] ?? []
        );
    }
    
    /**
     * Get only Ukrainian slang (for search augmentation).
     */
    public function getUkrainianSlang(string $productType): array
    {
        $entry = $this->dictionary[$productType] ?? [];
        
        return array_merge(
            $entry['slang'] ?? [],
            $entry['synonyms'] ?? [],
            $entry['typos'] ?? []
        );
    }
    
    /**
     * Find product type by any term (slang, synonym, typo, English).
     * 
     * @return string|null Product type or null if not found
     */
    public function findTypeByTerm(string $term): ?string
    {
        $termLower = mb_strtolower(trim($term));
        
        foreach ($this->dictionary as $productType => $entry) {
            $allTerms = array_merge(
                [$productType],
                $entry['slang'] ?? [],
                $entry['synonyms'] ?? [],
                $entry['typos'] ?? [],
                $entry['en'] ?? []
            );
            
            foreach ($allTerms as $t) {
                if (mb_strtolower($t) === $termLower) {
                    return $productType;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Expand search query with slang synonyms.
     * 
     * @param string $query Original search query
     * @return string Expanded query with OR alternatives
     */
    public function expandQuery(string $query): string
    {
        $words = preg_split('/\s+/', $query);
        $expanded = [];
        
        foreach ($words as $word) {
            $productType = $this->findTypeByTerm($word);
            
            if ($productType) {
                // Found a product type term - expand with alternatives
                $alternatives = $this->getUkrainianSlang($productType);
                $alternatives[] = $word; // Include original
                $alternatives = array_unique(array_filter($alternatives));
                
                // Limit alternatives to prevent query explosion
                $alternatives = array_slice($alternatives, 0, 5);
                
                if (count($alternatives) > 1) {
                    $expanded[] = '(' . implode(' OR ', $alternatives) . ')';
                } else {
                    $expanded[] = $word;
                }
            } else {
                $expanded[] = $word;
            }
        }
        
        return implode(' ', $expanded);
    }
    
    /**
     * Get slang for AI index augmentation.
     * Returns additional slang terms that should be added to product's AI index.
     */
    public function getAugmentedSlang(string $productType, array $existingSlang = []): array
    {
        $dictSlang = $this->getUkrainianSlang($productType);
        
        // Merge and dedupe
        return array_values(array_unique(array_merge($existingSlang, $dictSlang)));
    }
    
    /**
     * Get all product types in dictionary.
     */
    public function getProductTypes(): array
    {
        return array_keys($this->dictionary);
    }
    
    /**
     * Get full dictionary.
     */
    public function getDictionary(): array
    {
        return $this->dictionary;
    }
    
    /**
     * Clear cache (call after dictionary update).
     */
    public function clearCache(): void
    {
        Cache::forget('slang_dictionary');
    }
}
