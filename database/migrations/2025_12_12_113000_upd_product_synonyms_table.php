<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('phrase');              // те, що ввів юзер
            $table->json('synonyms')->nullable();  // ["бронік","бронежилет",...]
            $table->string('language', 5)->default('uk');
            $table->float('weight')->default(1.0);
            $table->string('domain')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['phrase', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_synonyms');
    }
};
