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
        Schema::create('product_cross_sells', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('cross_sell_product_id')->index();
            $table->string('type')->default('accessory'); // accessory, bundle, alternative
            $table->string('reason')->nullable(); // "сумісно з вашим товаром", "тепло до -15°C"
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('cross_sell_product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['product_id', 'cross_sell_product_id']);
        });
        
        // Table for cross-sell rules based on categories
        Schema::create('cross_sell_rules', function (Blueprint $table) {
            $table->id();
            $table->string('source_category'); // e.g. "plate_carriers"
            $table->string('target_category'); // e.g. "pouches"
            $table->string('reason')->nullable(); // "підсумки для плитоноски"
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['source_category', 'target_category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cross_sell_rules');
        Schema::dropIfExists('product_cross_sells');
    }
};
