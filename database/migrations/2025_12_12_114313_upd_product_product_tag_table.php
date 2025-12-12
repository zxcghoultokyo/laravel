<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * product_tags
         */
        if (!Schema::hasTable('product_tags')) {
            Schema::create('product_tags', function (Blueprint $table) {
                $table->id();

                $table->string('name', 191);
                $table->string('slug', 191)->unique();

                $table->string('type', 50)->nullable();
                $table->boolean('is_auto_generated')->default(false);
                $table->string('domain', 191)->nullable();

                $table->timestamps();

                $table->index(['type']);
                $table->index(['domain']);
            });
        }

        /**
         * product_product_tag (pivot)
         */
        if (!Schema::hasTable('product_product_tag')) {
            Schema::create('product_product_tag', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_tag_id');

                $table->primary(['product_id', 'product_tag_id']);

                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->cascadeOnDelete();

                $table->foreign('product_tag_id')
                    ->references('id')
                    ->on('product_tags')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // down робимо “м’яко”, щоб не падало, якщо чогось нема
        if (Schema::hasTable('product_product_tag')) {
            Schema::table('product_product_tag', function (Blueprint $table) {
                // dropForeign може впасти, якщо constraint інакше названий,
                // але з array-формою Laravel зазвичай підхоплює правильно.
                $table->dropForeign(['product_id']);
                $table->dropForeign(['product_tag_id']);
            });

            Schema::dropIfExists('product_product_tag');
        }

        Schema::dropIfExists('product_tags');
    }
};
