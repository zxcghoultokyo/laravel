<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_generation_logs', function (Blueprint $table) {
            $table->id();

            $table->string('entity_type', 64); // product_synonym | category_alias | other
            $table->string('entity_ref', 191)->nullable(); // id or key reference

            $table->string('domain', 191)->nullable()->index();
            $table->string('language', 8)->nullable()->index();

            $table->string('prompt_hash', 64)->index();
            $table->text('input_excerpt')->nullable();
            $table->longText('raw_ai_json')->nullable();

            $table->string('status', 16)->default('ok')->index(); // ok | error | fallback
            $table->text('error_message')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_logs');
    }
};
