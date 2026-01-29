<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add tenant_id to product_synonyms table for multi-tenant SaaS support.
 * 
 * This allows each tenant to have their own synonyms based on their product catalog.
 * tenant_id = NULL means global/shared synonym (fallback for all tenants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_synonyms', function (Blueprint $table) {
            if (!Schema::hasColumn('product_synonyms', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
                
                // Update index to include tenant_id for efficient queries
                $table->index(['tenant_id', 'synonym', 'language'], 'synonyms_tenant_lookup');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_synonyms', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex('synonyms_tenant_lookup');
            $table->dropColumn('tenant_id');
        });
    }
};
