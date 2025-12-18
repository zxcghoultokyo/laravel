<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductSearchEngine
{
    public function __construct(protected MeiliClient $meili)
    {
    }

    /**
     * @param array $parsed результат SearchQueryParser::parse()
     * @param int|null $categoryId
     * @param int $limit скільки показати юзеру
     */
    public function search(array $parsed, ?int $categoryId = null, int $limit = 10): Collection
    {
        $q = trim((string)($parsed['expanded'] ?? $parsed['normalized'] ?? ''));
        if ($q === '') {
            return collect();
        }

        // 1) Meili-first
        if ((int)config('meilisearch.enabled', 0) === 1) {
            try {
                $hits = $this->searchViaMeili($parsed, $categoryId, candidateLimit: max(50, $limit * 6));

                if ($hits->isNotEmpty()) {
                    // 2) dedup варіантів/розмірів
                    $deduped = $this->deduplicate($hits);

                    // 3) беремо top N
                    return $deduped->take($limit)->values();
                }
            } catch (\Throwable $e) {
                Log::warning('ProductSearchEngine::searchViaMeili failed, fallback to DB', [
                    'err' => $e->getMessage(),
                ]);
            }
        }

        // 2) fallback: DB LIKE (як було)
        return $this->searchViaDb($parsed, $categoryId, $limit);
    }

    protected function searchViaMeili(array $parsed, ?int $categoryId, int $candidateLimit = 50): Collection
    {
        $index = $this->meili->productsIndex();

        $normalized = trim((string)($parsed['normalized'] ?? ''));
        $expanded   = trim((string)($parsed['expanded'] ?? $normalized));

        // базові фільтри: наявність + показ у вітрині + кількість
        $filters = [
            'in_stock = 1',
            'quantity > 0',
            'display_in_showcase = 1',
        ];

        if ($categoryId) {
            $filters[] = 'category_id = ' . (int)$categoryId;
        }

        $signals = (array)($parsed['signals'] ?? []);

        // цінові фільтри
        if (!empty($signals['price_min'])) {
            $filters[] = 'price >= ' . (int)$signals['price_min'];
        }
        if (!empty($signals['price_max'])) {
            $filters[] = 'price <= ' . (int)$signals['price_max'];
        }

        // якщо парсер/AI/синоніми витягли конкретний ai_product_type — фільтруємо ним
        $aiTypes = (array)($parsed['ai_intent']['product_types'] ?? []);
        $aiTypes = array_values(array_filter($aiTypes, fn($v) => is_string($v) && $v !== ''));

        // важливо: ми НЕ хардкодимо, ми просто фільтруємо тим, що вже є в індексі
        if (!empty($aiTypes)) {
            $or = [];
            foreach ($aiTypes as $t) {
                $t = addslashes(mb_strtolower($t));
                $or[] = 'ai_product_type = "' . $t . '"';
            }
            if (!empty($or)) {
                $filters[] = '(' . implode(' OR ', $or) . ')';
            }
        }

        $filterString = implode(' AND ', $filters);

        // бізнес-сортування
        $sort = [
            'we_recommended:desc',
            'popularity:desc',
            'orders_count:desc',
            'views_count:desc',
            'added_to_cart_count:desc',
            'updated_at_ts:desc',
            'price:asc',
        ];

        $options = [
            'limit'  => $candidateLimit,
            'filter' => $filterString,
            'sort'   => $sort,
            'attributesToRetrieve' => ['id'],
        ];

        Log::info('Meili search', [
            'q' => $expanded,
            'options' => $options,
        ]);

        $res = $index->search($expanded, $options);
        $hits = $res->getHits();

        if (empty($hits)) {
            return collect();
        }

        $ids = array_values(array_filter(array_map(
            fn($h) => (int)($h['id'] ?? 0),
            $hits
        )));

        if (empty($ids)) {
            return collect();
        }

        // дістаємо з БД та зберігаємо порядок як у Meili
        $products = Product::query()
            ->with('aiIndex')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($products[$id])) {
                $ordered[] = [
                    'product' => $products[$id],
                    'score'   => 0.0,
                    'flags'   => [],
                ];
            }
        }

        return collect($ordered);
    }

    protected function deduplicate(Collection $rows): Collection
    {
        $best = [];

        foreach ($rows as $row) {
            /** @var \App\Models\Product $p */
            $p = $row['product'];

            $key = trim((string)($p->parent_article ?: $p->article ?: $p->id));
            if ($key === '') {
                $key = (string)$p->id;
            }

            if (!isset($best[$key])) {
                $best[$key] = $row;
                continue;
            }

            $cur = $best[$key]['product'];

            // вибираємо кращий варіант
            $pick = $this->isBetterVariant($p, $cur) ? $row : $best[$key];
            $best[$key] = $pick;
        }

        return collect(array_values($best));
    }

    protected function isBetterVariant(Product $a, Product $b): bool
    {
        // 1) in_stock
        $aStock = (int)((bool)$a->in_stock);
        $bStock = (int)((bool)$b->in_stock);
        if ($aStock !== $bStock) return $aStock > $bStock;

        // 2) we_recommended
        $aRec = (int)((bool)$a->we_recommended);
        $bRec = (int)((bool)$b->we_recommended);
        if ($aRec !== $bRec) return $aRec > $bRec;

        // 3) popularity
        $aPop = (int)($a->popularity ?? 0);
        $bPop = (int)($b->popularity ?? 0);
        if ($aPop !== $bPop) return $aPop > $bPop;

        // 4) updated
        $aUpd = (int)($a->updated_at_ts ?? 0);
        $bUpd = (int)($b->updated_at_ts ?? 0);
        if ($aUpd !== $bUpd) return $aUpd > $bUpd;

        // 5) price (якщо все однаково — дешевше вище)
        $aPrice = (int)($a->price ?? 0);
        $bPrice = (int)($b->price ?? 0);
        if ($aPrice !== $bPrice) return $aPrice < $bPrice;

        return false;
    }

    protected function searchViaDb(array $parsed, ?int $categoryId, int $limit): Collection
    {
        $normalized = (string)($parsed['normalized'] ?? '');
        $expanded = (string)($parsed['expanded'] ?? $normalized);
        $tokens = preg_split('/\s+/u', $expanded) ?: [];
        $tokens = array_values(array_filter($tokens, fn($t) => mb_strlen($t) >= 3));

        /** @var Builder $q */
        $q = Product::query()->with('aiIndex');

        $q->where('display_in_showcase', true)
            ->where('in_stock', true)
            ->where('quantity', '>', 0);

        if ($categoryId !== null) {
            $q->where('category_id', $categoryId);
        }

        $signals = (array)($parsed['signals'] ?? []);
        if (!empty($signals['price_min'])) {
            $q->where('price', '>=', (int)$signals['price_min']);
        }
        if (!empty($signals['price_max'])) {
            $q->where('price', '<=', (int)$signals['price_max']);
        }

        if ($tokens) {
            $q->where(function (Builder $qb) use ($tokens) {
                foreach ($tokens as $t) {
                    $like = '%' . $t . '%';
                    $qb->orWhere('search_index', 'LIKE', $like)
                       ->orWhere('title', 'LIKE', $like)
                       ->orWhere('category_path', 'LIKE', $like)
                       ->orWhere('color', 'LIKE', $like)
                       ->orWhereHas('aiIndex', function (Builder $ai) use ($like) {
                           $ai->where('product_type', 'LIKE', $like)
                              ->orWhere('ai_category', 'LIKE', $like);
                       });
                }
            });
        }

        $products = $q->orderByDesc('popularity')->limit(max(60, $limit * 6))->get();

        $rows = $products->map(fn(Product $p) => ['product' => $p, 'score' => 0.0, 'flags' => []]);
        return $this->deduplicate($rows)->take($limit)->values();
    }
}
