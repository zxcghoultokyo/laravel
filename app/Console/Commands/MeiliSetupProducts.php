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
            'display_in_showcase',
            'in_stock',
            'category_id',
            'price',
            'product_type',
            'ai_category',
        ]);

        $index->updateSortableAttributes([
            'we_recommended',
            'orders_count',
            'popularity',
            'views_count',
            'added_to_cart_count',
            'updated_at_ts',
            'price',
            'quantity',
        ]);

        // ✅ rankingRules — тільки дозволені правила
        $index->updateRankingRules([
            'words',
            'typo',
            'proximity',
            'attribute',
            'exactness',
            'sort', // дозволяє використовувати sort в search()
        ]);

        $this->info('Meili products index configured.');
        return self::SUCCESS;
    }
}
