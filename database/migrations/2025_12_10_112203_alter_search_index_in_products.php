<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // 1. Спочатку видаляємо індекс, якщо він існує
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes('products');

            if (array_key_exists('products_search_index_index', $indexes)) {
                $table->dropIndex('products_search_index_index');
            }

            // 2. Тепер знімаємо обмеження і міняємо тип
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
