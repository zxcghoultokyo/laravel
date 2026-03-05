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
        Schema::create('rozetka_category_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rozetka_category_id')->index();
            $table->unsignedBigInteger('attribute_id')->index();
            $table->string('name');
            $table->string('attr_type', 50);
            $table->string('filter_type', 50)->default('disable');
            $table->string('unit')->nullable();
            $table->boolean('is_global')->default(false);
            $table->json('values')->nullable();
            $table->timestamps();

            $table->unique(['rozetka_category_id', 'attribute_id'], 'cat_attr_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rozetka_category_attributes');
    }
};
