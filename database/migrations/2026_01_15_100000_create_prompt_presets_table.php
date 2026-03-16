<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_presets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('system_prompt'); // With {{variables}}

            // Conditions
            $table->json('categories')->nullable(); // ["Одяг", "Взуття"]
            $table->string('language')->nullable(); // uk, en, ru
            $table->string('tone')->nullable(); // official, spartan, friendly
            $table->string('campaign')->nullable(); // UTM campaign match

            // Variables definition
            $table->json('variables')->nullable(); // [{"name": "brand_name", "default": "Contractor"}]

            // Meta
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_presets');
    }
};
