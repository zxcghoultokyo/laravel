<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('search_eval_cases')) {
            return;
        }

        Schema::create('search_eval_cases', function (Blueprint $table) {
            $table->id();
            $table->text('query');
            $table->json('expected_product_ids')->nullable();
            $table->string('language', 8)->default('uk');
            $table->string('domain', 191)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_eval_cases');
    }
};
