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
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'session_id')) {
                $table->string('session_id')->nullable()->after('order_id')->index();
            }
            if (!Schema::hasColumn('orders', 'had_chat')) {
                $table->boolean('had_chat')->default(false)->after('payed');
            }
            if (!Schema::hasColumn('orders', 'products_from_chat')) {
                $table->integer('products_from_chat')->default(0)->after('had_chat');
            }
            if (!Schema::hasColumn('orders', 'analytics')) {
                $table->json('analytics')->nullable()->after('raw');
            }
        });
        
        // Add index on session_id if it exists but has no index
        if (Schema::hasColumn('orders', 'session_id')) {
            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->index('session_id', 'orders_session_id_index');
                });
            } catch (\Exception $e) {
                // Index may already exist
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('orders', 'session_id')) {
                $columns[] = 'session_id';
            }
            if (Schema::hasColumn('orders', 'had_chat')) {
                $columns[] = 'had_chat';
            }
            if (Schema::hasColumn('orders', 'products_from_chat')) {
                $columns[] = 'products_from_chat';
            }
            if (Schema::hasColumn('orders', 'analytics')) {
                $columns[] = 'analytics';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
