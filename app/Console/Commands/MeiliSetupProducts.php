<?php

namespace App\Console\Commands;

use App\Services\Search\MeiliClient;
use Illuminate\Console\Command;

class MeiliSetupProducts extends Command
{
    protected $signature = 'meili:setup-products';
    protected $description = 'Setup Meilisearch settings for products index';

    public function handle(MeiliClient $meili): int
    {
        $index = $meili->productsIndex();

        // 1) які поля шукаємо
        $index->updateSearchableAttributes([
            'title',
            'search_index',
            'category_path',
            'brand',
            'color',
            'ai_product_type',
            'ai_category',
            'article',
            'parent_article',
        ]);

        // 2) які поля можна фільтрувати (filter)
        $index->updateFilterableAttributes([
            'in_stock',
            'display_in_showcase',
            'price',
            'ai_product_type',
            'brand',
            'color',
            'color_norm',
            'quantity',
        ]);

        // 3) які поля можна сортувати (sort в запиті)
        $index->updateSortableAttributes([
            'we_recommended',
            'popularity',
            'orders_count',
            'views_count',
            'added_to_cart_count',
            'price',
            'updated_at_ts',
        ]);

        // 4) ranking rules: лишаємо нормальні + дозволяємо sort
        $index->updateRankingRules([
            'words',
            'typo',
            'proximity',
            'attribute',
            'sort',
            'exactness',
        ]);

        $this->info('Meili products index settings updated.');
        return self::SUCCESS;
    }
}
