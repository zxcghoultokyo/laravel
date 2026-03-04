<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration fixes the unique constraint on products table
     * to allow same article across different tenants.
     */
    public function up(): void
    {
        // Ensure tenant_id column exists (on fresh DB it may not,
        // because add_tenant_id_to_existing_tables runs before products table is created)
        if (! Schema::hasColumn('products', 'tenant_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
        }

        // Drop the old unique constraint on article alone (if it exists)
        if (Schema::hasIndex('products', 'products_article_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique('products_article_unique');
            });
        }

        // Add composite unique index for tenant_id + article (if not already present)
        if (! Schema::hasIndex('products', 'products_tenant_article_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique(['tenant_id', 'article'], 'products_tenant_article_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_tenant_article_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique('article');
        });
    }
};
