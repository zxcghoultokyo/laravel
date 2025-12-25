<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add soft deletes to products table for data recovery.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
            
            // Index for soft delete queries
            $table->index('deleted_at', 'idx_products_deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_deleted_at');
            $table->dropSoftDeletes();
        });
    }
};
