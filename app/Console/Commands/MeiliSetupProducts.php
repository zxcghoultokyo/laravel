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

        $index->updateSearchableAttributes([
            'title',
            'search_index',
            'category_path',
            'brand',
            'color',
            'product_type',
            'ai_category',
        ]);

        $index->updateFilterableAttributes([
            'price',
            'in_stock',
            'brand',
            'camo_group',
            'product_type',
            'ai_category',
        ]);

        $index->updateSortableAttributes([
            'we_recommended',
            'orders_count',
            'popularity',
            'added_to_cart_count',
            'views_count',
            'updated_at_ts',
            'price',
        ]);

        $index->updateRankingRules([
            'words',
            'typo',
            'proximity',
            'attribute',
            'sort',
            'exactness',
        ]);

        $this->info('Meili products index configured.');
        return self::SUCCESS;
    }
}
