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
            $table->string('session_id')->nullable()->after('order_id')->index();
            $table->boolean('had_chat')->default(false)->after('payed');
            $table->integer('products_from_chat')->default(0)->after('had_chat');
            $table->json('analytics')->nullable()->after('raw');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['session_id', 'had_chat', 'products_from_chat', 'analytics']);
        });
    }
};
