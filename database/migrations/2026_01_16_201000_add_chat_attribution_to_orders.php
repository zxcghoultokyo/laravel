<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Chat attribution fields - only add if not exists
            if (!Schema::hasColumn('orders', 'session_id')) {
                $table->string('session_id')->nullable()->index();
            }
            if (!Schema::hasColumn('orders', 'had_chat')) {
                $table->boolean('had_chat')->default(false);
            }
            if (!Schema::hasColumn('orders', 'products_from_chat')) {
                $table->integer('products_from_chat')->default(0);
            }
            if (!Schema::hasColumn('orders', 'analytics')) {
                $table->json('analytics')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'session_id')) {
                $table->dropColumn('session_id');
            }
            if (Schema::hasColumn('orders', 'had_chat')) {
                $table->dropColumn('had_chat');
            }
            if (Schema::hasColumn('orders', 'products_from_chat')) {
                $table->dropColumn('products_from_chat');
            }
            if (Schema::hasColumn('orders', 'analytics')) {
                $table->dropColumn('analytics');
            }
        });
    }
};
