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
        Schema::create('tenant_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Kind of record:
            //   faq          — Q&A (FAQ)
            //   product_hint — marketing description linked to product articles
            //   script       — situational script / operator guidance
            $table->string('type', 32)->index();

            $table->string('question')->nullable();
            $table->text('answer');

            // Searchable keywords (tokens/phrases) and optional article list for product_hint.
            $table->json('keywords')->nullable();
            $table->json('articles')->nullable();

            $table->string('category', 100)->nullable();
            $table->string('language', 8)->default('uk');
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);

            $table->string('source', 255)->nullable();       // e.g. pdf:shablony, pdf:skripty
            $table->string('external_id', 255)->nullable();  // for idempotent imports
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'is_active']);
            $table->index(['tenant_id', 'language']);
            $table->unique(['tenant_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_knowledge_base');
    }
};
