<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Models\ProductAiIndex;
use Illuminate\Support\Collection;

class ProductRanker
{
    public function score(array $parsed, Collection $candidates): Collection
    {
        $query = mb_strtolower((string) ($parsed['expanded'] ?? ''));
        $queryTokens = preg_split('/\s+/u', $query) ?: [];
        $queryTokens = array_values(array_filter($queryTokens, fn ($t) => $t !== ''));

        $primaryNorm = $queryTokens[0] ?? '';

        $signals = (array) ($parsed['signals'] ?? []);
        $dbProductTypes = array_map('mb_strtolower', (array) ($signals['product_types'] ?? []));
        $dbColorGroups = (array) ($signals['color_groups'] ?? []);
        $colorSynonymsMap = (array) ($signals['color_synonyms_map'] ?? []);

        $ai = (array) ($parsed['ai_intent'] ?? []);
        $aiProductTypes = array_map('mb_strtolower', (array) ($ai['product_types'] ?? []));
        $mustHaveKeywords = array_map('mb_strtolower', (array) ($ai['must_have_keywords'] ?? []));

        $productTypeTokens = array_values(array_unique(array_filter(array_merge($aiProductTypes, $dbProductTypes))));

        $aiIndexMap = ProductAiIndex::query()
            ->whereIn('product_id', $candidates->pluck('id'))
            ->get()
            ->keyBy('product_id');

        $candidates = $candidates->map(function (Product $product) use ($aiIndexMap) {
            $aiIndex = $aiIndexMap->get($product->id);
            if ($aiIndex) {
                $product->setRelation('aiIndex', $aiIndex);
            }
            return $product;
        });

        return $candidates->map(function (Product $product) use (
            $queryTokens,
            $productTypeTokens,
            $mustHaveKeywords,
            $primaryNorm,
            $dbColorGroups,
            $colorSynonymsMap
        ) {
            $title = mb_strtolower($product->title ?? '');
            $index = mb_strtolower($product->search_index ?? '');
            $cats  = mb_strtolower($product->category_path ?? '');
            $brand = mb_strtolower($product->brand ?? '');

            $aiChunk = '';
            if ($product->relationLoaded('aiIndex') && $product->aiIndex) {
                $aiChunkParts = [
                    (string) ($product->aiIndex->product_type ?? ''),
                    (string) ($product->aiIndex->ai_category ?? ''),
                    is_array($product->aiIndex->materials) ? implode(' ', $product->aiIndex->materials) : (string) ($product->aiIndex->materials ?? ''),
                    is_array($product->aiIndex->standards) ? implode(' ', $product->aiIndex->standards) : (string) ($product->aiIndex->standards ?? ''),
                    is_array($product->aiIndex->keywords) ? implode(' ', $product->aiIndex->keywords) : (string) ($product->aiIndex->keywords ?? ''),
                    is_array($product->aiIndex->slang) ? implode(' ', $product->aiIndex->slang) : (string) ($product->aiIndex->slang ?? ''),
                ];
                $aiChunk = mb_strtolower(implode(' ', array_filter($aiChunkParts)));
            }

            $haystack = $title . ' ' . $index . ' ' . $cats . ' ' . $brand . ' ' . $aiChunk;

            $baseScore = 0.0;

            if ($primaryNorm !== '') {
                if (str_starts_with($title, $primaryNorm)) {
                    $baseScore += 25.0;
                } elseif (str_contains($title, $primaryNorm)) {
                    $baseScore += 15.0;
                }
            }

            $termMatches = 0;
            foreach ($queryTokens as $token) {
                if ($token === '') continue;
                if (str_contains($haystack, $token)) {
                    $termMatches++;
                    $baseScore += 3.0;
                }
            }

            foreach ($productTypeTokens as $pType) {
                if ($pType !== '' && str_contains($haystack, $pType)) {
                    $baseScore += 12.0;
                }
            }

            $mustHavePenalty = 0.0;
            foreach ($mustHaveKeywords as $must) {
                if ($must !== '' && ! str_contains($haystack, $must)) {
                    $mustHavePenalty += 10.0;
                }
            }

            $colorBonus = $this->getColorBonusFromDb($dbColorGroups, $colorSynonymsMap, $product->color ?? null);
            $categoryBonus = $this->getProductTypeBonus($productTypeTokens, $cats, $aiChunk);
            $popularityBonus = $this->getPopularityBonus((int) ($product->popularity ?? 0));

            $titlePenalty = 0.0;
            if (mb_strlen($title) > 120 && $termMatches <= 1) {
                $titlePenalty = 5.0;
            }

            $score = $baseScore - $titlePenalty - $mustHavePenalty + $colorBonus + $categoryBonus + $popularityBonus;

            return [
                'product' => $product,
                'score' => $score,
                'flags' => [
                    'missing_must_have' => ! empty($mustHaveKeywords) && ($mustHavePenalty > 0),
                ],
            ];
        });
    }

    protected function getPopularityBonus(int $popularity): float
    {
        if ($popularity <= 0) return 0.0;
        if ($popularity >= 100) return 10.0;
        return min(10.0, $popularity / 10.0);
    }

    protected function getColorBonusFromDb(array $colorGroups, array $colorSynonymsMap, ?string $productColor): float
    {
        if (! $productColor || empty($colorGroups)) return 0.0;

        $productColorNorm = mb_strtolower((string) $productColor);

        foreach ($colorGroups as $group) {
            $g = (string) $group;
            if ($g === '') continue;

            if (str_contains($productColorNorm, mb_strtolower($g))) {
                return 8.0;
            }

            $syns = $colorSynonymsMap[$g] ?? [];
            foreach ($syns as $s) {
                $s = (string) $s;
                if ($s !== '' && str_contains($productColorNorm, $s)) {
                    return 8.0;
                }
            }
        }

        return 0.0;
    }

    protected function getProductTypeBonus(array $productTypeTokens, string $categoryPathNorm, string $aiChunkNorm): float
    {
        if (empty($productTypeTokens)) return 0.0;

        $bonus = 0.0;
        foreach ($productTypeTokens as $pType) {
            $pType = (string) $pType;
            if ($pType === '') continue;

            if (str_contains($categoryPathNorm, $pType)) {
                $bonus += 6.0;
                continue;
            }

            if (str_contains($aiChunkNorm, $pType)) {
                $bonus += 8.0;
            }
        }

        return $bonus;
    }
}
