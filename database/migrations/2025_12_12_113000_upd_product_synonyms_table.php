<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Якщо таблиці нема — створюємо (на дев/локал)
        if (! Schema::hasTable('product_synonyms')) {
            Schema::create('product_synonyms', function (Blueprint $table) {
                $table->id();
                $table->string('phrase');
                $table->json('synonyms')->nullable();
                $table->string('language', 5)->default('uk');
                $table->float('weight')->default(1.0);
                $table->string('domain')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['phrase', 'language']);
            });

            return;
        }

        // Якщо таблиця вже є — АЛЬТЕР
        Schema::table('product_synonyms', function (Blueprint $table) {

            // ✅ Нова схема, яку використовує ProductService::expandQueryWithDomainSynonyms()
            if (! Schema::hasColumn('product_synonyms', 'phrase')) {
                // якщо у тебе раніше було synonym — можна пробувати перейменувати (дивись нижче)
                $table->string('phrase')->nullable()->index();
            }

            if (! Schema::hasColumn('product_synonyms', 'synonyms')) {
                $table->json('synonyms')->nullable();
            }

            if (! Schema::hasColumn('product_synonyms', 'language')) {
                $table->string('language', 5)->default('uk');
            }

            if (! Schema::hasColumn('product_synonyms', 'weight')) {
                $table->float('weight')->default(1.0);
            }

            if (! Schema::hasColumn('product_synonyms', 'domain')) {
                $table->string('domain')->nullable();
            }

            if (! Schema::hasColumn('product_synonyms', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }

            // ✅ Якщо у тебе є старі колонки, які більше не потрібні — НЕ чіпаю автоматом.
            // (в проді краще чистити окремою міграцією після міграції даних)
        });

        // (Опційно) Тут можна зробити міграцію даних: якщо у тебе було поле `synonym`,
        // і ти хочеш заповнити `phrase` ним — робиться через DB::statement/update.
    }

    public function down(): void
    {
        // Безпечний rollback: нічого не дропаємо в проді
        // Можеш залишити порожнім
    }
};
