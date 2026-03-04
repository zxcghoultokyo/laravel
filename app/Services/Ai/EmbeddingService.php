<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating text embeddings using OpenAI API.
 *
 * Embeddings are vector representations of text that capture semantic meaning.
 * Similar concepts have similar vectors, enabling semantic search.
 */
class EmbeddingService
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    protected int $dimensions;

    // Cache embeddings for 30 days (they don't change for same text)
    protected const CACHE_TTL = 60 * 60 * 24 * 30;

    protected const CACHE_PREFIX = 'embedding_';

    public function __construct()
    {
        $this->apiKey = config('services.openai.key', '');
        $this->baseUrl = rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        // text-embedding-3-small is cheap ($0.00002/1K tokens) and good quality
        $this->model = config('services.openai.embedding_model', 'text-embedding-3-small');

        // 1536 dimensions is standard, can reduce to 512 or 256 for storage savings
        $this->dimensions = (int) config('services.openai.embedding_dimensions', 1536);
    }

    /**
     * Check if embedding service is available.
     */
    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Generate embedding for a single text.
     *
     * @param  string  $text  The text to embed
     * @param  bool  $useCache  Whether to cache the result
     * @return array|null Vector of floats, or null on error
     */
    public function embed(string $text, bool $useCache = true): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('EmbeddingService: API key not configured');

            return null;
        }

        $text = $this->normalizeText($text);
        if (empty($text)) {
            return null;
        }

        // Check cache
        if ($useCache) {
            $cacheKey = self::CACHE_PREFIX.md5($text.$this->model.$this->dimensions);
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->acceptJson()
                ->post($this->baseUrl.'/embeddings', [
                    'model' => $this->model,
                    'input' => $text,
                    'dimensions' => $this->dimensions,
                ]);

            if (! $response->successful()) {
                Log::error('EmbeddingService: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $embedding = $response->json('data.0.embedding');

            if (! is_array($embedding) || empty($embedding)) {
                Log::warning('EmbeddingService: Empty embedding in response');

                return null;
            }

            // Cache the result
            if ($useCache) {
                Cache::put($cacheKey, $embedding, self::CACHE_TTL);
            }

            return $embedding;

        } catch (\Throwable $e) {
            Log::error('EmbeddingService: Exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate embeddings for multiple texts in batch.
     * More efficient than calling embed() multiple times.
     *
     * @param  array  $texts  Array of texts to embed
     * @return array Array of embeddings (same order as input), null for failed ones
     */
    public function embedBatch(array $texts): array
    {
        if (empty($this->apiKey) || empty($texts)) {
            return array_fill(0, count($texts), null);
        }

        // Normalize and filter
        $normalizedTexts = array_map(fn ($t) => $this->normalizeText($t), $texts);

        // Check cache for each
        $results = [];
        $uncachedIndices = [];
        $uncachedTexts = [];

        foreach ($normalizedTexts as $i => $text) {
            if (empty($text)) {
                $results[$i] = null;

                continue;
            }

            $cacheKey = self::CACHE_PREFIX.md5($text.$this->model.$this->dimensions);
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                $results[$i] = $cached;
            } else {
                $uncachedIndices[] = $i;
                $uncachedTexts[] = $text;
            }
        }

        // Batch request for uncached
        if (! empty($uncachedTexts)) {
            try {
                // OpenAI allows up to 2048 inputs per request
                $chunks = array_chunk($uncachedTexts, 100, true);
                $chunkIndices = array_chunk($uncachedIndices, 100, true);

                foreach ($chunks as $chunkKey => $chunk) {
                    $response = Http::withToken($this->apiKey)
                        ->timeout(60)
                        ->acceptJson()
                        ->post($this->baseUrl.'/embeddings', [
                            'model' => $this->model,
                            'input' => array_values($chunk),
                            'dimensions' => $this->dimensions,
                        ]);

                    if ($response->successful()) {
                        $data = $response->json('data', []);

                        foreach ($data as $item) {
                            $idx = $item['index'] ?? null;
                            $embedding = $item['embedding'] ?? null;

                            if ($idx !== null && is_array($embedding)) {
                                $originalIndex = $chunkIndices[$chunkKey][$idx] ?? null;
                                if ($originalIndex !== null) {
                                    $results[$originalIndex] = $embedding;

                                    // Cache it
                                    $text = $chunk[$idx] ?? '';
                                    if ($text) {
                                        $cacheKey = self::CACHE_PREFIX.md5($text.$this->model.$this->dimensions);
                                        Cache::put($cacheKey, $embedding, self::CACHE_TTL);
                                    }
                                }
                            }
                        }
                    } else {
                        Log::error('EmbeddingService: Batch request failed', [
                            'status' => $response->status(),
                            'chunk_size' => count($chunk),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('EmbeddingService: Batch exception', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fill missing with null
        for ($i = 0; $i < count($texts); $i++) {
            if (! isset($results[$i])) {
                $results[$i] = null;
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * Calculate cosine similarity between two embeddings.
     * Returns value from -1 to 1 (1 = identical, 0 = orthogonal, -1 = opposite)
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Build text for embedding from product data.
     * Combines relevant fields for best semantic representation.
     */
    public function buildProductText(array $productData): string
    {
        $parts = [];

        // Title is most important
        if (! empty($productData['title'])) {
            $parts[] = $productData['title'];
        }

        // Category provides context
        if (! empty($productData['category_path'])) {
            $parts[] = $productData['category_path'];
        }

        // Brand
        if (! empty($productData['brand'])) {
            $parts[] = $productData['brand'];
        }

        // AI-generated keywords and slang
        if (! empty($productData['keywords']) && is_array($productData['keywords'])) {
            $parts[] = implode(' ', $productData['keywords']);
        }

        if (! empty($productData['slang']) && is_array($productData['slang'])) {
            $parts[] = implode(' ', $productData['slang']);
        }

        // Description (truncated)
        if (! empty($productData['description'])) {
            $desc = strip_tags($productData['description']);
            $desc = mb_substr($desc, 0, 500);
            $parts[] = $desc;
        }

        return $this->normalizeText(implode('. ', $parts));
    }

    /**
     * Normalize text for embedding.
     */
    protected function normalizeText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Limit length (embedding models have token limits)
        // ~8000 tokens max, but we'll be conservative
        if (mb_strlen($text) > 8000) {
            $text = mb_substr($text, 0, 8000);
        }

        return $text;
    }
}
