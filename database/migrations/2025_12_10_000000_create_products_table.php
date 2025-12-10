<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Унікальний артикул з Хорошопу
            $table->string('article')->unique();

            // Людська назва (збережемо одразу uk, щоб швидко віддавати фронту)
            $table->string('title')->nullable();

            // Повний title з Хорошопу (json з ua/ru)
            $table->json('title_json')->nullable();

            // Ціни
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('price_old', 10, 2)->nullable();

            // Категорія-шлях "Тактичне спорядження/Підсумки/..."
            $table->string('category_path')->nullable();

            // SEO
            $table->string('slug')->nullable();
            $table->string('link')->nullable();

            // Картинки (масив url-ів)
            $table->json('images')->nullable();

            // Сирий json з Хорошопу (на всякий випадок)
            $table->json('raw')->nullable();

            // Спрощене поле для пошуку (нормалізований title+category)
            $table->text('search_index')->nullable();

            // Місце під наші майбутні метрики
            $table->unsignedBigInteger('orders_count')->default(0);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('added_to_cart_count')->default(0);

            $table->timestamps();

            // Індекс по search_index для LIKE пошуку
            $table->index('search_index');
            $table->index('category_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
