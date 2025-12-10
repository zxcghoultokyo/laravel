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
            /**
             * Таблиця тегів для товарів
             * Приклади тегів:
             * - "з принтом"
             * - "без принта"
             * - "укрпіксель"
             * - "multicam"
             * - "для плитоноски"
             */
            Schema::create('product_tags', function (Blueprint $table) {
                $table->id();

                $table->string('name', 191);
                $table->string('slug', 191)->unique();

                // Тип тегу (опціонально): feature, style, use_case, etc
                $table->string('type', 50)->nullable();

                // Чи тег згенерований автоматично через LLM
                $table->boolean('is_auto_generated')->default(false);

                // Для домену/магазину, якщо треба сегрегація
                $table->string('domain', 191)->nullable();

                $table->timestamps();

                $table->index(['type']);
                $table->index(['domain']);
            });

            /**
             * Pivot-таблиця: багато-до-багатьох між products та product_tags
             * Припускаю, що в тебе таблиця products вже існує, primary key = id (bigint).
             */
            Schema::create('product_product_tag', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_tag_id');

                $table->primary(['product_id', 'product_tag_id']);

                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->onDelete('cascade');

                $table->foreign('product_tag_id')
                    ->references('id')
                    ->on('product_tags')
                    ->onDelete('cascade');
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_product_tag', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['product_tag_id']);
        });

        Schema::dropIfExists('product_product_tag');
        Schema::dropIfExists('product_tags');
    }
};
