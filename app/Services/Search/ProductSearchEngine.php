<?php

namespace App\Services\Search;

use App\Models\Product;
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
        // 1) retrieval (Meili candidates)
        $candidates = $this->findCandidatesInMeili($parsed, $categoryId, 120);

        if ($candidates->isEmpty()) {
            Log::info('ProductSearchEngine: no candidates (Meili)', [
                'expanded' => $parsed['expanded'] ?? '',
            ]);
            return collect();
        }

        // 2) rerank (your business logic)
        $scored = $this->ranker->score($parsed, $candidates);
        if ($scored->isEmpty()) {
            return collect();
        }

        // 3) dedupe variants (same model different size)
        $deduped = $this->dedupeVariantProducts($scored);

        // 4) cut by score and limit
        $max = (float) ($deduped->max('score') ?? 0.0);
        $filtered = $deduped->filter(fn (array $row) => (float) $row['score'] >= $max * 0.3)->values();

        return $filtered->sortByDesc('score')->values()->take($limit);
    }

    protected function findCandidatesInMeili(array $parsed, ?int $categoryId, int $limit): Collection
    {
        $q = (string) ($parsed['expanded'] ?? '');
        $signals = (array) ($parsed['signals'] ?? []);
        $price = (array) ($parsed['price'] ?? []);

        // product types WITHOUT hardcode:
        // - from DB synonyms (signals.product_types)
        // - from AI intent router (ai_intent.product_types)
        $types = [];
        if (!empty($signals['product_types']) && is_array($signals['product_types'])) {
            $types = array_merge($types, $signals['product_types']);
        }
        if (!empty($parsed['ai_intent']['product_types']) && is_array($parsed['ai_intent']['product_types'])) {
            $types = array_merge($types, $parsed['ai_intent']['product_types']);
        }
        $types = array_values(array_unique(array_filter($types, fn($t) => is_string($t) && $t !== '')));

        $filters = [];
        $filters[] = 'display_in_showcase = true';
        $filters[] = 'in_stock = true';

        if (!empty($price['min'])) $filters[] = 'price >= ' . (float) $price['min'];
        if (!empty($price['max'])) $filters[] = 'price <= ' . (float) $price['max'];

        // If you pass categoryId in your app, keep it; otherwise remove this block.
        // (Leaving as-is because you already use category_id in code.)
        if ($categoryId !== null) {
            $filters[] = 'category_id = ' . (int) $categoryId;
        }

        // IMPORTANT: If query contains a clear type intent (plates), we filter by product_type,
        // so “plate” will not return “IOTV vest”, because it will be a different product_type.
        if (!empty($types)) {
            $or = array_map(fn($t) => 'product_type = "' . addslashes($t) . '"', $types);
            $filters[] = '(' . implode(' OR ', $or) . ')';
        }

        // Business sort (this is how you do popularity/orders in your Meili version)
        $sort = [
            'we_recommended:desc',
            'popularity:desc',
            'orders_count:desc',
            'views_count:desc',
            'added_to_cart_count:desc',
            'updated_at_ts:desc',
        ];

        $options = [
            'limit' => $limit,
            'sort' => $sort,
        ];

        if (!empty($filters)) {
            $options['filter'] = implode(' AND ', $filters);
        }

        $res = $this->meili->productsIndex()->search($q, $options);
        $hits = $res->getHits() ?? [];

        $ids = array_values(array_filter(array_map(fn($h) => (int)($h['id'] ?? 0), $hits)));
        $ids = array_values(array_filter($ids, fn($id) => $id > 0));

        if (empty($ids)) return collect();

        // fetch full models from DB and preserve Meili order
        $products = Product::query()
            ->with('aiIndex')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($products[$id])) $ordered[] = $products[$id];
        }

        return collect($ordered);
    }

    /**
     * Dedupe: if one product has variants (same name different size),
     * we keep only the best-scored one per variant group.
     *
     * Generic (no niche hardcode): group by parent_article if present, else by article.
     */
    protected function dedupeVariantProducts(Collection $scored): Collection
    {
        $best = [];

        foreach ($scored as $row) {
            /** @var \App\Models\Product $p */
            $p = $row['product'];
            $score = (float) ($row['score'] ?? 0);

            $groupKey = $p->parent_article ?: $p->article;
            $groupKey = (string) $groupKey;

            if ($groupKey === '') {
                $groupKey = (string) $p->id;
            }

            if (!isset($best[$groupKey])) {
                $best[$groupKey] = $row;
                continue;
            }

            $prevScore = (float) ($best[$groupKey]['score'] ?? 0);
            if ($score > $prevScore) {
                $best[$groupKey] = $row;
                continue;
            }

            // tie-break: prefer in stock then higher quantity
            if (abs($score - $prevScore) < 0.0001) {
                $prevP = $best[$groupKey]['product'];

                if (($p->in_stock ?? false) && !($prevP->in_stock ?? false)) {
                    $best[$groupKey] = $row;
                    continue;
                }
                if ((int)($p->quantity ?? 0) > (int)($prevP->quantity ?? 0)) {
                    $best[$groupKey] = $row;
                    continue;
                }
            }
        }

        return collect(array_values($best));
    }
}
