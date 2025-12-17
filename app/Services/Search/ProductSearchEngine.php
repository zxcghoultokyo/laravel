<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductSearchEngine
{
    public function __construct(
        protected ProductRanker $ranker,
        protected MeiliClient $meili,
    ) {}

    public function search(array $parsed, ?int $categoryId = null, int $limit = 10): Collection
    {
        $candidates = $this->findCandidates($parsed, $categoryId);

        if ($candidates->isEmpty()) {
            Log::info('ProductSearchEngine: no candidates', [
                'expanded' => $parsed['expanded'] ?? '',
            ]);
            return collect();
        }

        // rerank with your business logic (synonyms, must_have, penalties etc.)
        $scored = $this->ranker->score($parsed, $candidates);

        if ($scored->isEmpty()) {
            return collect();
        }

        $max = (float) ($scored->max('score') ?? 0.0);
        $filtered = $scored->filter(fn (array $row) => (float) $row['score'] >= $max * 0.3)->values();

        return $filtered
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }

    protected function findCandidates(array $parsed, ?int $categoryId = null): Collection
    {
        // Prefer Meili as retrieval, fallback to SQL if Meili disabled/unavailable
        try {
            if (config('meilisearch.enabled')) {
                $hits = $this->findCandidatesInMeili($parsed, $categoryId, 120); // take more, then rerank
                if ($hits->isNotEmpty()) {
                    return $hits;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ProductSearchEngine: Meili retrieval failed, fallback to SQL', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->findCandidatesInSql($parsed, $categoryId);
    }

    /**
     * Meili retrieval: get IDs from Meili, then fetch Products from DB (so you keep same model structure).
     */
    protected function findCandidatesInMeili(array $parsed, ?int $categoryId, int $limit): Collection
    {
        $q = (string) ($parsed['expanded'] ?? '');
        $priceFilters = (array) ($parsed['price'] ?? []);

        $filters = [];

        // keep only showcase products (if you index it)
        $filters[] = 'display_in_showcase = 1';

        // stock preference: you can keep only in stock, or allow both
        $filters[] = 'in_stock = 1';

        if ($categoryId !== null) {
            $filters[] = 'category_id = ' . (int) $categoryId;
        }

        if (!empty($priceFilters['min'])) {
            $filters[] = 'price >= ' . (float) $priceFilters['min'];
        }
        if (!empty($priceFilters['max'])) {
            $filters[] = 'price <= ' . (float) $priceFilters['max'];
        }

        // Optional: if parser provides product_types
        if (!empty($parsed['product_types']) && is_array($parsed['product_types'])) {
            $types = array_values(array_filter($parsed['product_types'], fn($t) => is_string($t) && $t !== ''));
            if (!empty($types)) {
                $or = array_map(fn($t) => 'ai_product_type = "' . addslashes($t) . '"', $types);
                $filters[] = '(' . implode(' OR ', $or) . ')';
            }
        }

        // Optional: if parser provides color/camo group
        if (!empty($parsed['camo_group']) && is_string($parsed['camo_group'])) {
            $filters[] = 'camo_group = "' . addslashes($parsed['camo_group']) . '"';
        }

        $options = [
            'limit' => $limit,
        ];

        if (!empty($filters)) {
            $options['filter'] = implode(' AND ', $filters);
        }

        // NOTE: if you want explicit sorting (instead of ranking rules), you can also pass:
        // $options['sort'] = ['we_recommended:desc','popularity:desc','orders_count:desc','updated_at_ts:desc'];

        $res = $this->meili->productsIndex()->search($q, $options);
        $hits = $res->getHits() ?? [];

        $ids = array_values(array_filter(array_map(fn($h) => $h['id'] ?? null, $hits)));
        $ids = array_map('intval', $ids);

        if (empty($ids)) {
            return collect();
        }

        // Fetch from DB and preserve Meili order
        $products = Product::query()
            ->with('aiIndex')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($products[$id])) {
                $ordered[] = $products[$id];
            }
        }

        return collect($ordered);
    }

    /**
     * Old SQL retrieval (your current fallback).
     */
    protected function findCandidatesInSql(array $parsed, ?int $categoryId = null): Collection
    {
        $expandedQuery = (string) ($parsed['expanded'] ?? '');
        $priceFilters = (array) ($parsed['price'] ?? []);

        $tokens = preg_split('/\s+/u', $expandedQuery) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 3));

        /** @var Builder $q */
        $q = Product::query()->with('aiIndex');

        $q->where('display_in_showcase', true)
          ->where('in_stock', true);

        if ($categoryId !== null) {
            $q->where('category_id', $categoryId);
        }

        if (!empty($priceFilters['min'])) {
            $q->where('price', '>=', $priceFilters['min']);
        }
        if (!empty($priceFilters['max'])) {
            $q->where('price', '<=', $priceFilters['max']);
        }

        if (!empty($tokens)) {
            $q->where(function (Builder $q) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';

                    $q->orWhere('search_index', 'LIKE', $like)
                      ->orWhere('title', 'LIKE', $like)
                      ->orWhere('category_path', 'LIKE', $like)
                      ->orWhere('color', 'LIKE', $like)
                      ->orWhere('brand', 'LIKE', $like)
                      ->orWhereHas('aiIndex', function (Builder $ai) use ($like) {
                          $ai->where('product_type', 'LIKE', $like)
                             ->orWhere('keywords', 'LIKE', $like)
                             ->orWhere('slang', 'LIKE', $like);
                      });
                }
            });
        }

        return $q->limit(200)->get();
    }
}
