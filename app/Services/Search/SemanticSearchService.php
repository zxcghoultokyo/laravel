<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Services\Ai\EmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Semantic search service using embeddings.
 * 
 * Finds products based on meaning similarity, not just keyword matching.
 * Falls back to keyword search if embeddings are not available.
 */
class SemanticSearchService
{
    protected EmbeddingService $embeddingService;
    
    // Cache query embeddings for 1 hour
    protected const QUERY_CACHE_TTL = 3600;
    protected const QUERY_CACHE_PREFIX = 'semantic_query_';

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Check if semantic search is available.
     */
    public function isAvailable(): bool
    {
        if (!$this->embeddingService->isAvailable()) {
            return false;
        }

        // Check if we have any embeddings in the database
        $hasEmbeddings = Cache::remember('semantic_search_available', 300, function () {
            return ProductAiIndex::whereNotNull('embedding')
                ->where('embedding', '!=', '[]')
                ->exists();
        });

        return $hasEmbeddings;
    }

    /**
     * Search products semantically.
     * 
     * @param string $query Natural language query
     * @param int $limit Max results
     * @param array $filters Additional filters (in_stock, category, etc.)
     * @param float $threshold Minimum similarity score (0-1)
     * @return Collection Products with similarity scores
     */
    public function search(
        string $query,
        int $limit = 20,
        array $filters = [],
        float $threshold = 0.3
    ): Collection {
        $startTime = microtime(true);

        // Get query embedding
        $queryEmbedding = $this->getQueryEmbedding($query);
        
        if (!$queryEmbedding) {
            Log::warning('SemanticSearch: Could not generate query embedding', [
                'query' => $query,
            ]);
            return collect();
        }

        // Build base query for products with embeddings
        $productsQuery = Product::query()
            ->whereHas('aiIndex', function ($q) {
                $q->whereNotNull('embedding')
                    ->where('embedding', '!=', '[]');
            })
            ->with('aiIndex');

        // Apply filters
        if (!empty($filters['in_stock'])) {
            $productsQuery->where('in_stock', true);
        }

        if (!empty($filters['category'])) {
            $productsQuery->where('category_path', 'like', '%' . $filters['category'] . '%');
        }

        if (!empty($filters['brand'])) {
            $productsQuery->where('brand', $filters['brand']);
        }

        if (!empty($filters['price_min'])) {
            $productsQuery->where('price', '>=', $filters['price_min']);
        }

        if (!empty($filters['price_max'])) {
            $productsQuery->where('price', '<=', $filters['price_max']);
        }

        // Get products and calculate similarities
        // Note: For large catalogs, consider using pgvector or Meilisearch hybrid
        $products = $productsQuery->get();
        
        $results = [];
        
        foreach ($products as $product) {
            $embedding = $product->aiIndex->embedding ?? null;
            
            if (!$embedding || !is_array($embedding) || empty($embedding)) {
                continue;
            }

            $similarity = $this->embeddingService->cosineSimilarity($queryEmbedding, $embedding);
            
            if ($similarity >= $threshold) {
                $results[] = [
                    'product' => $product,
                    'similarity' => $similarity,
                ];
            }
        }

        // Sort by similarity descending
        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // Limit results
        $results = array_slice($results, 0, $limit);

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('SemanticSearch: completed', [
            'query' => $query,
            'total_products' => $products->count(),
            'results' => count($results),
            'elapsed_ms' => $elapsed,
            'top_similarity' => $results[0]['similarity'] ?? 0,
        ]);

        // Return collection of products with similarity scores
        return collect($results)->map(function ($item) {
            $product = $item['product'];
            $product->semantic_similarity = $item['similarity'];
            return $product;
        });
    }

    /**
     * Find similar products to a given product.
     * Useful for "you might also like" recommendations.
     */
    public function findSimilar(Product $product, int $limit = 5): Collection
    {
        $embedding = $product->aiIndex->embedding ?? null;
        
        if (!$embedding) {
            return collect();
        }

        $productsQuery = Product::query()
            ->where('id', '!=', $product->id)
            ->where('in_stock', true)
            ->whereHas('aiIndex', function ($q) {
                $q->whereNotNull('embedding')
                    ->where('embedding', '!=', '[]');
            })
            ->with('aiIndex');

        $products = $productsQuery->get();
        $results = [];

        foreach ($products as $p) {
            $pEmbedding = $p->aiIndex->embedding ?? null;
            if (!$pEmbedding) continue;

            $similarity = $this->embeddingService->cosineSimilarity($embedding, $pEmbedding);
            $results[] = [
                'product' => $p,
                'similarity' => $similarity,
            ];
        }

        usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        $results = array_slice($results, 0, $limit);

        return collect($results)->map(function ($item) {
            $product = $item['product'];
            $product->semantic_similarity = $item['similarity'];
            return $product;
        });
    }

    /**
     * Hybrid search: combine semantic and keyword search.
     * Returns union of results with combined scoring.
     */
    public function hybridSearch(
        string $query,
        Collection $keywordResults,
        int $limit = 20,
        float $semanticWeight = 0.3
    ): Collection {
        // Get semantic results
        $semanticResults = $this->search($query, $limit * 2, ['in_stock' => true], 0.25);

        // Combine results
        $combined = [];
        
        // Add keyword results with base score
        foreach ($keywordResults as $i => $product) {
            $keywordScore = 1.0 - ($i / max(1, $keywordResults->count())); // Position-based score
            $combined[$product->id] = [
                'product' => $product,
                'keyword_score' => $keywordScore,
                'semantic_score' => 0,
            ];
        }

        // Add/merge semantic results
        foreach ($semanticResults as $product) {
            if (isset($combined[$product->id])) {
                $combined[$product->id]['semantic_score'] = $product->semantic_similarity;
            } else {
                $combined[$product->id] = [
                    'product' => $product,
                    'keyword_score' => 0,
                    'semantic_score' => $product->semantic_similarity,
                ];
            }
        }

        // Calculate combined score
        foreach ($combined as &$item) {
            $item['combined_score'] = 
                (1 - $semanticWeight) * $item['keyword_score'] +
                $semanticWeight * $item['semantic_score'];
        }

        // Sort by combined score
        usort($combined, fn($a, $b) => $b['combined_score'] <=> $a['combined_score']);

        // Return limited results
        return collect(array_slice($combined, 0, $limit))->map(fn($item) => $item['product']);
    }

    /**
     * Get embedding for a search query.
     */
    protected function getQueryEmbedding(string $query): ?array
    {
        $cacheKey = self::QUERY_CACHE_PREFIX . md5($query);
        
        return Cache::remember($cacheKey, self::QUERY_CACHE_TTL, function () use ($query) {
            return $this->embeddingService->embed($query);
        });
    }
}
