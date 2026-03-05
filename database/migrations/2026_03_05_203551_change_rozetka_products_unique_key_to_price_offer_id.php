<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            if (! Schema::hasColumn('rozetka_products', 'is_duplicate')) {
                $table->boolean('is_duplicate')->default(false)->after('price_offer_id');
            }
            if (! Schema::hasColumn('rozetka_products', 'primary_offer_id')) {
                $table->string('primary_offer_id')->nullable()->after('is_duplicate');
            }
        });

        // Separate statement for the index (can't mix hasColumn checks with index ops in same blueprint)
        try {
            Schema::table('rozetka_products', function (Blueprint $table) {
                $table->unique(['tenant_id', 'price_offer_id'], 'rozetka_products_tenant_price_offer_unique');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Index already exists — ignore
        }
    }

    public function down(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->dropUnique('rozetka_products_tenant_price_offer_unique');
            $table->dropColumn(['is_duplicate', 'primary_offer_id']);
        });
    }
};
