<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // 1. Знімаємо індекс, якщо він є
            try {
                $table->dropIndex(['search_index']);
            } catch (\Throwable $e) {
                // якщо індекса нема — ігноруємо
            }

            // 2. Міняємо тип поля
            $table->text('search_index')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // повертаємо назад VARCHAR(255)
            $table->string('search_index', 255)->change();

            // додаємо індекс назад
            $table->index('search_index');
        });
    }
};
