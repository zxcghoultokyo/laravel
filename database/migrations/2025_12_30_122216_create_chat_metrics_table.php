<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metrics tracking for chat system monitoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Request-level metrics
        Schema::create('chat_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 36)->index();
            $table->string('session_id', 64)->index();
            $table->string('intent', 50)->nullable();
            $table->unsignedInteger('response_time_ms');
            $table->unsignedInteger('ai_time_ms')->nullable();
            $table->unsignedInteger('search_time_ms')->nullable();
            $table->unsignedInteger('products_count')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->boolean('ai_used')->default(true);
            $table->boolean('is_fallback')->default(false);
            $table->string('error')->nullable();
            $table->timestamp('created_at');
            
            $table->index(['created_at', 'intent']);
        });

        // Aggregated daily stats (for dashboard)
        Schema::create('chat_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('total_requests')->default(0);
            $table->unsignedInteger('unique_sessions')->default(0);
            $table->unsignedInteger('product_searches')->default(0);
            $table->unsignedInteger('ai_calls')->default(0);
            $table->unsignedInteger('ai_failures')->default(0);
            $table->unsignedInteger('cache_hits')->default(0);
            $table->unsignedInteger('fallbacks')->default(0);
            $table->float('avg_response_time_ms')->default(0);
            $table->float('p95_response_time_ms')->default(0);
            $table->unsignedInteger('operator_takeovers')->default(0);
            $table->timestamps();
        });

        // Active chat sessions (for live monitoring)
        Schema::create('active_chat_sessions', function (Blueprint $table) {
            $table->string('session_id', 64)->primary();
            $table->string('status', 20)->default('ai'); // 'ai', 'operator', 'idle'
            $table->unsignedBigInteger('operator_id')->nullable(); // Who took over
            $table->timestamp('last_message_at');
            $table->timestamp('operator_took_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->string('last_query', 255)->nullable();
            $table->json('context')->nullable(); // Last shown products, category, etc.
            $table->timestamps();
            
            $table->index(['status', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_chat_sessions');
        Schema::dropIfExists('chat_daily_stats');
        Schema::dropIfExists('chat_metrics');
    }
};
