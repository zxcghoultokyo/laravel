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
        // 1) Беремо кандидатів (Meili якщо доступний, інакше fallback SQL)
        $candidates = $this->findCandidates($parsed, $categoryId);

        if ($candidates->isEmpty()) {
            Log::info('ProductSearchEngine: no candidates', [
                'expanded' => $parsed['expanded'] ?? '',
            ]);
            return collect();
        }

        // 2) Локальний ranker (твій), щоб “добити” релевантність + сигнали
        $scored = $this->ranker->score($parsed, $candidates);
        if ($scored->isEmpty()) {
            return collect();
        }

        // 3) Відсікаємо “сміття” (залишаємо >= 30% від max)
        $max = (float) ($scored->max('score') ?? 0.0);
        $filtered = $scored
            ->filter(fn (array $row) => (float) $row['score'] >= $max * 0.3)
            ->values();

        // 4) Видаємо більше, ніж limit, бо далі буде dedup в ProductService
        //    (щоб після дедупу ти все одно міг показати 10)
        return $filtered
            ->sortByDesc('score')
            ->values()
            ->take(max($limit * 5, 50));
    }

    protected function findCandidates(array $parsed, ?int $categoryId = null): Collection
    {
        // ✅ якщо Meili увімкнений — беремо кандидатів з Meili
        try {
            $this->meili->assertAvailable();
            return $this->findCandidatesViaMeili($parsed, $categoryId, candidateLimit: 200);
        } catch (\Throwable $e) {
            Log::warning('ProductSearchEngine: Meili unavailable, fallback to SQL', [
                'error' => $e->getMessage(),
            ]);
            return $this->findCandidatesViaSql($parsed, $categoryId);
        }
    }

    protected function findCandidatesViaMeili(array $parsed, ?int $categoryId, int $candidateLimit = 200): Collection
    {
        $expandedQuery = (string) ($parsed['expanded'] ?? $parsed['normalized'] ?? '');
        $priceFilters = (array) ($parsed['price'] ?? []);

        // product_types: беремо з AI intent (і з signals як fallback)
        $ai = (array) ($parsed['ai_intent'] ?? []);
        $signals = (array) ($parsed['signals'] ?? []);

        $types = array_values(array_unique(array_filter(array_merge(
            (array) ($ai['product_types'] ?? []),
            (array) ($signals['product_types'] ?? [])
        ), fn($v) => is_string($v) && $v !== '')));

        // БАЗОВІ фільтри (ніякого хардкоду під нішу)
        $filters = [];
        $filters[] = 'display_in_showcase = 1';
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

        // ✅ Ключове для “плити ≠ IOTV”: фільтр по product_type (це масштабується на будь-яку нішу)
        if (!empty($types)) {
            // Meili filter syntax: product_type IN ["a","b"]
            $quoted = array_map(fn($t) => '"' . addslashes($t) . '"', $types);
            $filters[] = 'product_type IN [' . implode(',', $quoted) . ']';
        }

        $filterStr = implode(' AND ', $filters);

        // ✅ sort — це і є “популярність/рекомендації”
        // важливо: ці поля мають бути в sortableAttributes
        $sort = [
            'we_recommended:desc',
            'orders_count:desc',
            'popularity:desc',
            'added_to_cart_count:desc',
            'views_count:desc',
            'updated_at_ts:desc',
        ];

        $index = $this->meili->productsIndex();

        $res = $index->search($expandedQuery, [
            'limit' => $candidateLimit,
            'filter' => $filterStr,
            'attributesToRetrieve' => ['id'],
            'sort' => $sort,
        ]);

        $hits = $res->getHits() ?? [];
        $ids = array_values(array_filter(array_map(fn($h) => $h['id'] ?? null, $hits)));

        if (empty($ids)) {
            Log::info('ProductSearchEngine: Meili returned 0 ids', [
                'q' => $expandedQuery,
                'filter' => $filterStr,
                'types' => $types,
            ]);
            return collect();
        }

        // Забираємо реальні продукти з БД, щоб фронт/логіка працювали як зараз
        $products = Product::query()
            ->with('aiIndex')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // Зберігаємо порядок Meili
        $ordered = collect();
        foreach ($ids as $id) {
            if ($products->has($id)) {
                $ordered->push($products->get($id));
            }
        }

        Log::info('ProductSearchEngine: Meili candidates', [
            'count' => $ordered->count(),
            'q' => $expandedQuery,
        ]);

        return $ordered;
    }

    protected function findCandidatesViaSql(array $parsed, ?int $categoryId = null): Collection
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

        Log::info('ProductSearchEngine: SQL candidates', [
            'count' => $products->count(),
        ]);

        return $products;
    }
}
