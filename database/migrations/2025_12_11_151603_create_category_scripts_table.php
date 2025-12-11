<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('category_scripts', function (Blueprint $table) {
            $table->id();
            $table->string('category_key');           // наприклад: "tourniquets", "cold_protection"
            $table->unsignedTinyInteger('level');     // 1, 2, 3 – рівень сценарію
            $table->text('question_template');        // текст питання до юзера
            $table->json('metadata')->nullable();     // інше (слоти, підказки для AI і т.д.)
            $table->boolean('is_auto_generated')->default(true);
            $table->timestamps();

            $table->index(['category_key', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_scripts');
    }
};
