<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductSearchEngine
{
    public function __construct(protected ProductRanker $ranker)
    {
    }

    public function search(array $parsed, ?int $categoryId = null, int $limit = 10): Collection
    {
        $candidates = $this->findCandidates($parsed, $categoryId);

        if ($candidates->isEmpty()) {
            Log::info('ProductSearchEngine: no candidates', [
                'expanded' => $parsed['expanded'] ?? '',
            ]);
            return collect();
        }

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

        if (! empty($priceFilters['min'])) {
            $q->where('price', '>=', $priceFilters['min']);
        }
        if (! empty($priceFilters['max'])) {
            $q->where('price', '<=', $priceFilters['max']);
        }

        if (! empty($tokens)) {
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
                                ->orWhere('ai_category', 'LIKE', $like);
                        });
                }
            });
        }

        $products = $q->get();

        Log::info('ProductSearchEngine: candidates', [
            'count' => $products->count(),
        ]);

        return $products;
    }
}
