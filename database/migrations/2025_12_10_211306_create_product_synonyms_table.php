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
        Schema::create('product_synonyms', function (Blueprint $table) {
            $table->id();

            // Наприклад: "плитоноска", "турнікет", "футболка з принтом"
            $table->string('product_type', 191);

            // Конкретний синонім, який зустрічається в запиті
            // Наприклад: "бронік", "plate carrier", "cat", "tq"
            $table->string('synonym', 191);

            // Мова (uk, ru, en, тощо) - опціонально
            $table->string('language', 8)->nullable();

            // Вага для тюнінгу (1 за замовчуванням, можна підкрутити)
            $table->tinyInteger('weight')->default(1);

            // Щоб мати домен/магазін-специфіку (якщо треба)
            // Наприклад: contractor.kiev.ua, або null = глобальний
            $table->string('domain', 191)->nullable();

            // Чи активний цей синонім
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['synonym', 'language']);
            $table->index(['product_type', 'language']);
            $table->index(['domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_synonyms');
    }
};
