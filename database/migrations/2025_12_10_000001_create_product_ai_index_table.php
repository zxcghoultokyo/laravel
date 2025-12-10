<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_ai_index', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();

            // Узагальнений тип товару: "tshirt", "helmet", "armor_plate", "pouch", ...
            $table->string('product_type')->nullable();

            // Груба категорія: "armor", "clothing", "med", ...
            $table->string('ai_category')->nullable();

            // JSON-поля
            $table->json('usage')->nullable();      // ["infantry","frontline"]
            $table->json('materials')->nullable();  // ["UHMWPE","Kevlar"]
            $table->json('standards')->nullable();  // ["NIJ III","FR"]
            $table->json('slang')->nullable();      // ["ухмвп","uhmwpe plates"]
            $table->json('keywords')->nullable();   // ["плита","бронеплита","plate"]

            // На майбутнє – ембедінги (можеш поки не чіпати)
            $table->json('embedding')->nullable();

            $table->json('raw_ai_json')->nullable(); // повна відповідь LLM для дебага

            $table->timestamps();

            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ai_index');
    }
};
