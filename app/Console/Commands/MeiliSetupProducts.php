<?php

namespace App\Console\Commands;

use App\Services\Search\MeiliClient;
use Illuminate\Console\Command;

class MeiliSetupProducts extends Command
{
    protected $signature = 'meili:setup-products';
    protected $description = 'Configure Meilisearch products index';

    public function handle(MeiliClient $meili): int
    {
        $index = $meili->productsIndex();

        // 1) What fields are searchable (text relevance)
        $index->updateSearchableAttributes([
            'title',
            'search_index',
            'category_path',
            'ai_product_type',
            'ai_keywords',
            'brand',
            'color',
        ]);

        // 2) What can be used in filters
        $index->updateFilterableAttributes([
            'in_stock',
            'display_in_showcase',
            'price',
            'category_id',
            'camo_group',
            'ai_product_type',
            'brand',
            'color',
        ]);

        // 3) What can be used in sort (and in custom ranking rules)
        $index->updateSortableAttributes([
            'we_recommended',
            'popularity',
            'orders_count',
            'views_count',
            'added_to_cart_count',
            'updated_at_ts',
            'price',
        ]);

        /**
         * 4) Ranking rules:
         * - first Meili does textual relevance (words/typo/proximity/attribute/exactness)
         * - then our custom business ranking kicks in (recommended/popularity/orders/...)
         *
         * IMPORTANT: custom rules must be AFTER the text relevance rules, otherwise you’ll get “popular but irrelevant”.
         */
        $index->updateRankingRules([
            'words',
            'typo',
            'proximity',
            'attribute',
            'exactness',

            // business ranking (our fields)
            'desc(we_recommended)',
            'desc(popularity)',
            'desc(orders_count)',
            'desc(views_count)',
            'desc(added_to_cart_count)',
            'desc(updated_at_ts)',

            // allow explicit sort from queries too (optional)
            'sort',
        ]);

        $this->info('Meili products index configured (searchable/filterable/sortable + ranking rules).');
        return self::SUCCESS;
    }
}
