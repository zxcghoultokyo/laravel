<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            // AI product type arrays for filtering logic (uses ai_product_type field values)
            $table->json('accessory_ai_types')->nullable()->after('main_product_keywords');
            $table->json('main_ai_types')->nullable()->after('accessory_ai_types');
            
            // Product type detection keywords (for detectPrimaryType)
            $table->json('product_type_keywords')->nullable()->after('main_ai_types');
            
            // Popular search queries for quick responses
            $table->json('popular_queries')->nullable()->after('product_type_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropColumn([
                'accessory_ai_types',
                'main_ai_types',
                'product_type_keywords',
                'popular_queries',
            ]);
        });
    }
};
