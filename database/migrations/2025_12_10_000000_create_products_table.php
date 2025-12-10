<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // На всяк випадок чистимо, якщо таблиця залишилась після невдалої міграції
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Унікальний артикул з Хорошопу
            $table->string('article')->unique();

            // Людська назва (одна мова, щоб швидко віддавати фронту)
            $table->string('title')->nullable();

            // Повний title з Хорошопу (json з різними мовами)
            $table->json('title_json')->nullable();

            // Ціни
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('price_old', 10, 2)->nullable();

            // Категорія-шлях типу "Тактичне спорядження/Підсумки/..."
            $table->string('category_path')->nullable();

            // SEO / посилання
            $table->string('slug')->nullable();
            $table->string('link')->nullable();

            // Картинки (масив url-ів)
            $table->json('images')->nullable();

            // Сирий json з Хорошопу (на запас)
            $table->json('raw')->nullable();

            // Спрощене поле для пошуку
            $table->string('search_index', 255)->nullable();

            // Майбутні метрики
            $table->unsignedBigInteger('orders_count')->default(0);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('added_to_cart_count')->default(0);

            $table->timestamps();

            // Індекси для пошуку/фільтрації
            $table->index('search_index');
            $table->index('category_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
