<?php

namespace App\Services\Search;

use App\Models\ColorSynonym;
use App\Models\ProductSynonym;

class SearchQueryParser
{
    public function __construct(protected QueryExpander $expander)
    {
    }

    public function parse(string $rawQuery, string $language = 'uk', ?string $domain = null): array
    {
        $normalized = mb_strtolower(trim($rawQuery));
        if ($normalized === '') {
            return [
                'raw' => $rawQuery,
                'normalized' => '',
                'expanded' => '',
                'tokens' => [],
                'price' => ['min' => null, 'max' => null],
                'signals' => ['product_types' => [], 'color_groups' => []],
            ];
        }

        $expanded = $this->expander->expandQueryWithDomainSynonyms($normalized, $language, $domain);
        [$price, $withoutPrice] = $this->extractPriceFilters($expanded);
        $expanded = $withoutPrice !== '' ? $withoutPrice : $expanded;

        $tokens = preg_split('/\s+/u', $expanded) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, fn ($t) => $t !== '')));

        $signals = $this->detectSignalsFromDb($tokens, $language, $domain);

        return [
            'raw' => $rawQuery,
            'normalized' => $normalized,
            'expanded' => $expanded,
            'tokens' => $tokens,
            'price' => $price,
            'signals' => $signals,
        ];
    }

    protected function extractPriceFilters(string $query): array
    {
        $price = ['min' => null, 'max' => null];

        $pattern = '/(?:до|менше|<)\s*(\d+)\s*(грн|uah|₴)?/ui';
        if (preg_match($pattern, $query, $m)) {
            $price['max'] = (float) $m[1];
            $query = str_replace($m[0], ' ', $query);
        }

        $pattern = '/(?:від|більше|>|\+)\s*(\d+)\s*(грн|uah|₴)?/ui';
        if (preg_match($pattern, $query, $m)) {
            $price['min'] = (float) $m[1];
            $query = str_replace($m[0], ' ', $query);
        }

        return [$price, trim(preg_replace('/\s+/u', ' ', $query))];
    }

    protected function detectSignalsFromDb(array $tokens, string $language = 'uk', ?string $domain = null): array
    {
        if (empty($tokens)) {
            return ['product_types' => [], 'color_groups' => [], 'color_synonyms_map' => []];
        }

        $matchedProductTypes = ProductSynonym::query()
            ->where('is_active', true)
            ->whereIn('synonym', $tokens)
            ->when($language, function ($q) use ($language) {
                $q->where(function ($q2) use ($language) {
                    $q2->whereNull('language')->orWhere('language', $language);
                });
            })
            ->when($domain, function ($q) use ($domain) {
                $q->where(function ($q2) use ($domain) {
                    $q2->whereNull('domain')->orWhere('domain', $domain);
                });
            })
            ->pluck('product_type')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $matchedColorGroups = ColorSynonym::query()
            ->where('is_active', true)
            ->whereIn('synonym', $tokens)
            ->when($language, function ($q) use ($language) {
                $q->where(function ($q2) use ($language) {
                    $q2->whereNull('language')->orWhere('language', $language);
                });
            })
            ->when($domain, function ($q) use ($domain) {
                $q->where(function ($q2) use ($domain) {
                    $q2->whereNull('domain')->orWhere('domain', $domain);
                });
            })
            ->pluck('color_group')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $colorSynonymsMap = [];
        if (! empty($matchedColorGroups)) {
            $rows = ColorSynonym::query()
                ->where('is_active', true)
                ->whereIn('color_group', $matchedColorGroups)
                ->when($language, function ($q) use ($language) {
                    $q->where(function ($q2) use ($language) {
                        $q2->whereNull('language')->orWhere('language', $language);
                    });
                })
                ->when($domain, function ($q) use ($domain) {
                    $q->where(function ($q2) use ($domain) {
                        $q2->whereNull('domain')->orWhere('domain', $domain);
                    });
                })
                ->get(['color_group', 'synonym']);

            foreach ($rows as $r) {
                $g = (string) $r->color_group;
                $s = mb_strtolower((string) $r->synonym);
                if ($g === '' || $s === '') {
                    continue;
                }
                $colorSynonymsMap[$g] ??= [];
                $colorSynonymsMap[$g][] = $s;
            }
            foreach ($colorSynonymsMap as $g => $syns) {
                $colorSynonymsMap[$g] = array_values(array_unique($syns));
            }
        }

        return [
            'product_types' => $matchedProductTypes,
            'color_groups' => $matchedColorGroups,
            'color_synonyms_map' => $colorSynonymsMap,
        ];
    }
}
