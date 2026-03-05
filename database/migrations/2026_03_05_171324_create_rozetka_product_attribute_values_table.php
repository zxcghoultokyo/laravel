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
        Schema::create('rozetka_product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rozetka_product_id')->constrained('rozetka_products')->cascadeOnDelete();
            $table->unsignedBigInteger('attribute_id')->index();
            $table->string('attribute_name');
            $table->unsignedBigInteger('value_id')->nullable();
            $table->text('value_text')->nullable();
            $table->timestamps();

            $table->unique(['rozetka_product_id', 'attribute_id'], 'prod_attr_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rozetka_product_attribute_values');
    }
};
