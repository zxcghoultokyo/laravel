<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes for product filtering and sorting.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Index for filtering by stock availability
            $table->index('in_stock', 'idx_products_in_stock');
            
            // Index for brand filtering
            $table->index('brand', 'idx_products_brand');
            
            // Composite index for common filter combinations
            $table->index(['in_stock', 'price'], 'idx_products_stock_price');
            
            // Index for popularity sorting
            $table->index('popularity', 'idx_products_popularity');
            
            // Index for parent-child product relationships
            $table->index('parent_article', 'idx_products_parent_article');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_in_stock');
            $table->dropIndex('idx_products_brand');
            $table->dropIndex('idx_products_stock_price');
            $table->dropIndex('idx_products_popularity');
            $table->dropIndex('idx_products_parent_article');
        });
    }
};
