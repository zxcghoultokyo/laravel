<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Chat attribution fields
            $table->string('session_id')->nullable()->after('order_id')->index();
            $table->boolean('had_chat')->default(false)->after('raw');
            $table->integer('products_from_chat')->default(0)->after('had_chat');
            
            // Analytics data (UTM etc from Horoshop)
            $table->json('analytics')->nullable()->after('products_from_chat');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['session_id', 'had_chat', 'products_from_chat', 'analytics']);
        });
    }
};
