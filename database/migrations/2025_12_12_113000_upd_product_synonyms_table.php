<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ Фіксуємо таблицю під РЕЛЯЦІЙНУ схему (ВАРІАНТ A):
        // product_type + synonym (+ weight/language/domain/is_active)

        // Якщо таблиці нема — створюємо
        if (! Schema::hasTable('product_synonyms')) {
            Schema::create('product_synonyms', function (Blueprint $table) {
                $table->id();
                $table->string('product_type', 191);
                $table->string('synonym', 191);
                $table->string('language', 8)->nullable();
                $table->float('weight')->default(1.0);
                $table->string('domain', 191)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['synonym', 'language']);
                $table->index(['product_type', 'language']);
                $table->index(['domain']);
            });
            return;
        }

        // Якщо таблиця вже є — догарантовуємо колонки
        Schema::table('product_synonyms', function (Blueprint $table) {
            if (! Schema::hasColumn('product_synonyms', 'product_type')) {
                $table->string('product_type', 191)->nullable();
            }
            if (! Schema::hasColumn('product_synonyms', 'synonym')) {
                $table->string('synonym', 191)->nullable();
            }
            if (! Schema::hasColumn('product_synonyms', 'language')) {
                $table->string('language', 8)->nullable();
            }
            if (! Schema::hasColumn('product_synonyms', 'weight')) {
                $table->float('weight')->default(1.0);
            }
            if (! Schema::hasColumn('product_synonyms', 'domain')) {
                $table->string('domain', 191)->nullable();
            }
            if (! Schema::hasColumn('product_synonyms', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        // Якщо у когось вже встигли зʼявитись колонки phrase/synonyms —
        // ми їх не чіпаємо (не ламаємо прод). Код їх просто ігнорує.
    }

    public function down(): void
    {
        // safe no-op
    }
};
