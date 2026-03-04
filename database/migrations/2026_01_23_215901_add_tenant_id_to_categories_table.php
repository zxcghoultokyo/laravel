<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Only add tenant_id if it doesn't exist
            if (! Schema::hasColumn('categories', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            }
        });

        // Handle unique constraint change in separate call
        Schema::table('categories', function (Blueprint $table) {
            // Try to drop old unique constraint (may not exist)
            try {
                $table->dropUnique(['path']);
            } catch (\Exception $e) {
                // Ignore if doesn't exist
            }
        });

        // Check if composite unique exists, if not - add it
        $driver = Schema::getConnection()->getDriverName();
        $indexExists = false;

        if ($driver === 'sqlite') {
            $indexes = collect(\DB::select("PRAGMA index_list('categories')"));
            $indexExists = $indexes->contains(fn ($idx) => str_contains($idx->name, 'tenant_id_path_unique'));
        } else {
            $indexExists = collect(\DB::select("SHOW INDEX FROM categories WHERE Key_name = 'categories_tenant_id_path_unique'"))->isNotEmpty();
        }

        if (! $indexExists) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unique(['tenant_id', 'path']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            try {
                $table->dropUnique(['tenant_id', 'path']);
            } catch (\Exception $e) {
            }

            try {
                $table->unique('path');
            } catch (\Exception $e) {
            }

            if (Schema::hasColumn('categories', 'tenant_id')) {
                $table->dropColumn('tenant_id');
            }
        });
    }
};
