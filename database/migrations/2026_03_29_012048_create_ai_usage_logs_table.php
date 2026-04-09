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
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('source', 50)->index(); // chat, enrichment, router, benchmark
            $table->string('model', 50); // gpt-4o, gpt-4o-mini, etc.
            $table->string('session_id')->nullable()->index();
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0); // estimated cost in USD
            $table->string('endpoint', 100)->nullable(); // chat/completions, etc.
            $table->integer('response_time_ms')->nullable();
            $table->boolean('is_error')->default(false);
            $table->timestamps();

            $table->index('created_at');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['source', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
