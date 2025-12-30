<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add indexes for Eloquent fallback search performance.
 * When Meilisearch is unavailable, these indexes speed up product search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Composite index for filtering (in_stock + display_in_showcase)
            $table->index(['in_stock', 'display_in_showcase'], 'idx_products_stock_showcase');
            
            // Index for price range queries
            $table->index('price', 'idx_products_price');
            
            // Index for sorting by popularity
            $table->index('popularity', 'idx_products_popularity');
            
            // Index for category filtering
            $table->index('category_path', 'idx_products_category');
            
            // Index for brand filtering
            $table->index('brand', 'idx_products_brand');
            
            // Composite index for common search patterns
            $table->index(['in_stock', 'price', 'popularity'], 'idx_products_search_sort');
        });

        // Full-text index for title search (MySQL 5.6+ / MariaDB)
        // This dramatically speeds up LIKE '%query%' searches
        if (DB::getDriverName() === 'mysql') {
            DB::statement('CREATE FULLTEXT INDEX idx_products_title_fulltext ON products(title)');
            DB::statement('CREATE FULLTEXT INDEX idx_products_search_index_fulltext ON products(search_index)');
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_stock_showcase');
            $table->dropIndex('idx_products_price');
            $table->dropIndex('idx_products_popularity');
            $table->dropIndex('idx_products_category');
            $table->dropIndex('idx_products_brand');
            $table->dropIndex('idx_products_search_sort');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP INDEX idx_products_title_fulltext ON products');
            DB::statement('DROP INDEX idx_products_search_index_fulltext ON products');
        }
    }
};
