<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            // Store identity & context for AI prompts
            $table->string('store_name')->nullable()->after('domain');
            $table->text('store_context')->nullable()->after('store_name'); // "тактичне військове спорядження"
            $table->text('store_description')->nullable()->after('store_context'); // Детальний опис для AI
            
            // Customer types (JSON array)
            $table->json('customer_types')->nullable()->after('store_description'); // ["військові", "правоохоронці"]
            
            // Product categories for AI understanding (JSON array)
            $table->json('product_categories')->nullable()->after('customer_types'); // ["плитоноски", "шоломи", "берці"]
            
            // Accessory detection rules (JSON) - replaces hardcoded AccessoryFilterTool
            $table->json('accessory_keywords')->nullable()->after('product_categories'); // ["ремінь", "кріплення", "чохол"]
            $table->json('main_product_keywords')->nullable()->after('accessory_keywords'); // ["плитоноска", "шолом", "броня"]
            
            // Brand transliterations (JSON object) - replaces hardcoded brand mappings
            $table->json('brand_transliterations')->nullable()->after('main_product_keywords'); // {"опскор": "Ops-Core"}
            
            // Store hours
            $table->string('store_hours')->nullable()->after('brand_transliterations'); // "Пн-Пт: 9:00-18:00"
            
            // AI behavior settings
            $table->boolean('ai_use_dynamic_prompts')->default(true)->after('store_hours');
            $table->boolean('ai_strict_category_filter')->default(false)->after('ai_use_dynamic_prompts');
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropColumn([
                'store_name',
                'store_context',
                'store_description',
                'customer_types',
                'product_categories',
                'accessory_keywords',
                'main_product_keywords',
                'brand_transliterations',
                'store_hours',
                'ai_use_dynamic_prompts',
                'ai_strict_category_filter',
            ]);
        });
    }
};
