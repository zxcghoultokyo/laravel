<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add indexes for Eloquent fallback search performance.
 * When Meilisearch is unavailable, these indexes speed up product search.
 * 
 * Note: This migration is idempotent - it skips indexes that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingIndexes = $this->getExistingIndexes('products');

        Schema::table('products', function (Blueprint $table) use ($existingIndexes) {
            // Composite index for filtering (in_stock + display_in_showcase)
            if (!in_array('idx_products_stock_showcase', $existingIndexes)) {
                $table->index(['in_stock', 'display_in_showcase'], 'idx_products_stock_showcase');
            }
            
            // Index for price range queries
            if (!in_array('idx_products_price', $existingIndexes)) {
                $table->index('price', 'idx_products_price');
            }
            
            // Index for sorting by popularity
            if (!in_array('idx_products_popularity', $existingIndexes)) {
                $table->index('popularity', 'idx_products_popularity');
            }
            
            // Index for category filtering
            if (!in_array('idx_products_category', $existingIndexes)) {
                $table->index('category_path', 'idx_products_category');
            }
            
            // Index for brand filtering
            if (!in_array('idx_products_brand', $existingIndexes)) {
                $table->index('brand', 'idx_products_brand');
            }
            
            // Composite index for common search patterns
            if (!in_array('idx_products_search_sort', $existingIndexes)) {
                $table->index(['in_stock', 'price', 'popularity'], 'idx_products_search_sort');
            }
        });

        // Full-text index for title search (MySQL 5.6+ / MariaDB)
        if (DB::getDriverName() === 'mysql') {
            if (!in_array('idx_products_title_fulltext', $existingIndexes)) {
                DB::statement('CREATE FULLTEXT INDEX idx_products_title_fulltext ON products(title)');
            }
            if (!in_array('idx_products_search_index_fulltext', $existingIndexes)) {
                DB::statement('CREATE FULLTEXT INDEX idx_products_search_index_fulltext ON products(search_index)');
            }
        }
    }

    public function down(): void
    {
        $existingIndexes = $this->getExistingIndexes('products');

        Schema::table('products', function (Blueprint $table) use ($existingIndexes) {
            if (in_array('idx_products_stock_showcase', $existingIndexes)) {
                $table->dropIndex('idx_products_stock_showcase');
            }
            if (in_array('idx_products_price', $existingIndexes)) {
                $table->dropIndex('idx_products_price');
            }
            if (in_array('idx_products_popularity', $existingIndexes)) {
                $table->dropIndex('idx_products_popularity');
            }
            if (in_array('idx_products_category', $existingIndexes)) {
                $table->dropIndex('idx_products_category');
            }
            if (in_array('idx_products_brand', $existingIndexes)) {
                $table->dropIndex('idx_products_brand');
            }
            if (in_array('idx_products_search_sort', $existingIndexes)) {
                $table->dropIndex('idx_products_search_sort');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            if (in_array('idx_products_title_fulltext', $existingIndexes)) {
                DB::statement('DROP INDEX idx_products_title_fulltext ON products');
            }
            if (in_array('idx_products_search_index_fulltext', $existingIndexes)) {
                DB::statement('DROP INDEX idx_products_search_index_fulltext ON products');
            }
        }
    }

    /**
     * Get list of existing index names for a table.
     */
    private function getExistingIndexes(string $table): array
    {
        if (DB::getDriverName() !== 'mysql') {
            return [];
        }

        $indexes = DB::select("SHOW INDEX FROM {$table}");
        return array_unique(array_column($indexes, 'Key_name'));
    }
};
