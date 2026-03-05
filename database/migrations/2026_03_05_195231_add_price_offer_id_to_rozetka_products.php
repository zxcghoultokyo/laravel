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
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->unsignedBigInteger('price_offer_id')->nullable()->index()->after('rozetka_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->dropColumn('price_offer_id');
        });
    }
};
