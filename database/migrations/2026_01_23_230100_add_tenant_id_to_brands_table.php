<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add tenant_id to brands table for multi-tenancy support
 * 
 * Different shops may have different brands.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('brands', 'tenant_id')) {
            Schema::table('brands', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
            
            // Change unique constraint from (slug) to (tenant_id, slug)
            try {
                Schema::table('brands', function (Blueprint $table) {
                    $table->dropUnique(['slug']);
                });
            } catch (\Exception $e) {
                // Constraint may not exist
            }
            
            Schema::table('brands', function (Blueprint $table) {
                $table->unique(['tenant_id', 'slug']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->unique(['slug']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
