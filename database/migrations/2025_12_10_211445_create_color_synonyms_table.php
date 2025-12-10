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
        Schema::create('color_synonyms', function (Blueprint $table) {
            $table->id();

            // Група кольору, з якою ти працюєш у коді
            // Наприклад: "black", "мультикам", "coyote", "укрпіксель"
            $table->string('color_group', 191);

            // Конкретний варіант, який може зустрічатись в пошуку або в магазині
            // Наприклад: "чорний", "черный", "black", "multicam", "mc", "піксель"
            $table->string('synonym', 191);

            // Мова/локаль (за бажанням)
            $table->string('language', 8)->nullable();

            // Чи це основна форма для відображення (наприклад, "Чорний")
            $table->boolean('is_primary')->default(false);

            // Щоб прив’язати до конкретного магазину (якщо треба)
            $table->string('domain', 191)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['synonym', 'language']);
            $table->index(['color_group']);
            $table->index(['domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('color_synonyms');
    }
};
