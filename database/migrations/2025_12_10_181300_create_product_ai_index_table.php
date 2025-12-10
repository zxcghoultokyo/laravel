<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_ai_index', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Зв'язок з products
            $table->unsignedBigInteger('product_id')->unique();

            // Основний тип / клас товару (helmet, plate carrier, t-shirt, etc)
            $table->string('product_type')->nullable();

            // Узагальнена категорія від AI (наприклад "armor", "apparel", "accessory")
            $table->string('ai_category')->nullable();

            // Сировина / матеріали (UHMWPE, steel, cordura, cotton...)
            $table->text('materials')->nullable();

            // Стандарти, класи захисту (NIJ III+, STANAG, FR, etc)
            $table->text('standards')->nullable();

            // Сленг, скорочення, альтернативні назви (каска, шолом, helmet, шлем, uhmwpe/uhmw, fr, "тритон", etc)
            $table->text('slang')->nullable();

            // Ключові слова (ключові фрази, по яких будемо матчити пошук)
            $table->text('keywords')->nullable();

            // Типове застосування (піхота, штурм, поліція, стрільба в тирі, кежуал, мерч...)
            $table->text('usage')->nullable();

            // Ембедінг — зберігаємо як JSON
            $table->json('embedding')->nullable();

            $table->timestamps();

            $table->foreign('product_id')
                ->references('id')->on('products')
                ->onDelete('cascade');

            // Індекси під пошук
            $table->index('product_type');
            $table->index('ai_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ai_index');
    }
};
