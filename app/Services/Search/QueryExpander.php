<?php

namespace App\Services\Search;

use App\Models\ProductSynonym;
use App\Models\ColorSynonym;

class QueryExpander
{
    /**
     * Розширює пошуковий запит за рахунок:
     * - product_synonyms (типи товарів: плитоноска, турнікет, тощо)
     * - color_synonyms (кольори: чорний, мультикам, укрпіксель, тощо)
     *
     * Повертає розширений рядок для пошуку по search_index.
     *
     * @param  string       $query     оригінальний текст користувача
     * @param  string       $language  мова (наприклад 'uk' або 'ru')
     * @param  string|null  $domain    домен/ідентифікатор магазину (наприклад 'contractor.kiev.ua')
     * @param  int|null     $tenantId  ID тенанта для tenant-specific синонімів
     * @return string
     */
    public function expandQueryWithDomainSynonyms(
        string $query,
        string $language = 'uk',
        ?string $domain = null,
        ?int $tenantId = null
    ): string {
        $normalized = mb_strtolower(trim($query));

        if ($normalized === '') {
            return $normalized;
        }

        // 🔹 розбиваємо на токени по пробілам
        $tokens = preg_split('/\s+/u', $normalized) ?: [];
        $uniqueTokens = array_values(array_unique($tokens));

        if (empty($uniqueTokens)) {
            return $normalized;
        }

        // -----------------------------
        // 1) PRODUCT_SYNONYMS (з урахуванням tenant_id)
        // -----------------------------
        $productSynonymsQuery = ProductSynonym::query()
            ->where('is_active', true)
            ->whereIn('synonym', $uniqueTokens)
            // Tenant filter: tenant-specific OR global (NULL)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereNull('tenant_id');
            })
            ->when($language, function ($q) use ($language) {
                $q->where(function ($q2) use ($language) {
                    $q2->whereNull('language')->orWhere('language', $language);
                });
            })
            ->when($domain, function ($q) use ($domain) {
                $q->where(function ($q2) use ($domain) {
                    $q2->whereNull('domain')->orWhere('domain', $domain);
                });
            });

        $matchedProductSynonyms = $productSynonymsQuery->get();

        // які product_type ми зачепили цими токенами
        $matchedProductTypes = $matchedProductSynonyms
            ->pluck('product_type')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $productTypeExtraTerms = [];

        if (!empty($matchedProductTypes)) {
            // добираємо всі синоніми для цих product_type,
            // щоб додати їх у пошуковий запит
            $allProductTypeSynonyms = ProductSynonym::query()
                ->where('is_active', true)
                ->whereIn('product_type', $matchedProductTypes)
                // Tenant filter: tenant-specific OR global (NULL)
                ->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
                })
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
                ->get();

            $productTypeExtraTerms = array_unique(array_merge(
                $matchedProductTypes,
                $allProductTypeSynonyms->pluck('synonym')->filter()->all()
            ));
        }

        // -----------------------------
        // 2) COLOR_SYNONYMS
        // -----------------------------
        $colorSynonymsQuery = ColorSynonym::query()
            ->where('is_active', true)
            ->whereIn('synonym', $uniqueTokens)
            ->when($language, function ($q) use ($language) {
                $q->where(function ($q2) use ($language) {
                    $q2->whereNull('language')->orWhere('language', $language);
                });
            })
            ->when($domain, function ($q) use ($domain) {
                $q->where(function ($q2) use ($domain) {
                    $q2->whereNull('domain')->orWhere('domain', $domain);
                });
            });

        $matchedColorSynonyms = $colorSynonymsQuery->get();

        // які color_group ми зачепили (наприклад: "black", "мультикам", "укрпіксель")
        $matchedColorGroups = $matchedColorSynonyms
            ->pluck('color_group')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $colorExtraTerms = [];

        if (!empty($matchedColorGroups)) {
            $allColorSynonymsForGroups = ColorSynonym::query()
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
                ->get();

            $colorExtraTerms = array_unique(array_merge(
                $matchedColorGroups,
                $allColorSynonymsForGroups->pluck('synonym')->filter()->all()
            ));
        }

        // -----------------------------
        // 3) Зліплюємо все разом
        // -----------------------------
        $extraTerms = array_diff(
            array_unique(array_merge($productTypeExtraTerms, $colorExtraTerms)),
            $uniqueTokens // не дублюємо те, що вже було в запиті
        );

        if (empty($extraTerms)) {
            return $normalized;
        }

        $expanded = trim($normalized . ' ' . implode(' ', $extraTerms));

        return $expanded;
    }
}
