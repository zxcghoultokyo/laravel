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

        // 1) searchable fields (text relevance)
        $index->updateSearchableAttributes([
            'title',
            'search_index',
            'category_path',
            'ai_product_type',
            'ai_keywords',
            'ai_slang',
        ]);

        // 2) filterable fields
        $index->updateFilterableAttributes([
            'in_stock',
            'display_in_showcase',
            'price',
            'category_id',
            'camo_group',
            'ai_product_type',
        ]);

        // 3) sortable fields (IMPORTANT: only these can be used in `sort` param)
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
         * 4) ranking rules:
         * In this Meili version, you cannot use desc(field) here.
         * Business ranking must be applied via the `sort` search option,
         * and for that you must keep `sort` rule enabled.
         */
        $index->updateRankingRules([
            'words',
            'typo',
            'proximity',
            'attribute',
            'exactness',
            'sort',
        ]);

        $this->info('Meili products index configured (searchable/filterable/sortable + ranking rules with sort).');
        return self::SUCCESS;
    }
}
