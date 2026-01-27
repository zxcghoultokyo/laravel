<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Fix brands.name unique constraint for multi-tenancy
 * 
 * Change from unique(name) to unique(tenant_id, name)
 * so different tenants can have the same brand names.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop old unique constraint on name only
        try {
            Schema::table('brands', function (Blueprint $table) {
                $table->dropUnique(['name']);
            });
        } catch (\Exception $e) {
            // Try alternative index name
            try {
                Schema::table('brands', function (Blueprint $table) {
                    $table->dropIndex('brands_name_unique');
                });
            } catch (\Exception $e2) {
                // Constraint may not exist
            }
        }

        // Add new composite unique constraint (tenant_id, name)
        Schema::table('brands', function (Blueprint $table) {
            $table->unique(['tenant_id', 'name'], 'brands_tenant_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique('brands_tenant_name_unique');
            $table->unique(['name']);
        });
    }
};
