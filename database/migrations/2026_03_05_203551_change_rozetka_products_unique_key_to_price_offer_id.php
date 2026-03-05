<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->boolean('is_duplicate')->default(false)->after('price_offer_id');
            $table->string('primary_offer_id')->nullable()->after('is_duplicate');

            // Drop old composite unique on tenant_id + article, replace with regular index
            $table->dropIndex('rozetka_products_tenant_id_article_index');
            $table->index(['tenant_id', 'article'], 'rozetka_products_tenant_article_idx');

            // Unique on tenant_id + price_offer_id so each variant has its own row
            $table->unique(['tenant_id', 'price_offer_id'], 'rozetka_products_tenant_price_offer_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->dropUnique('rozetka_products_tenant_price_offer_unique');
            $table->dropIndex('rozetka_products_tenant_article_idx');
            $table->index(['tenant_id', 'article'], 'rozetka_products_tenant_id_article_index');
            $table->dropColumn(['is_duplicate', 'primary_offer_id']);
        });
    }
};
