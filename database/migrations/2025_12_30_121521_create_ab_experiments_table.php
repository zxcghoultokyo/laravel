<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A/B Testing infrastructure for AI model experiments.
 * 
 * Usage:
 * 1. Create experiment in ab_experiments table
 * 2. System assigns users to variants based on session_id
 * 3. Track conversions in ab_conversions table
 * 4. Analyze results in admin panel
 */
return new class extends Migration
{
    public function up(): void
    {
        // Experiments table
        if (!Schema::hasTable('ab_experiments')) {
            Schema::create('ab_experiments', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();           // e.g., 'ai_model_comparison'
                $table->string('description')->nullable();
                $table->boolean('is_active')->default(false);
                $table->json('variants');                   // e.g., {"control": {"model": "gpt-4.1"}, "treatment": {"model": "gpt-4.1-mini"}}
                $table->unsignedInteger('traffic_percent')->default(100); // % of users in experiment
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();
                
                $table->index('is_active');
            });
        }

        // User assignments to experiment variants
        if (!Schema::hasTable('ab_assignments')) {
            Schema::create('ab_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('experiment_id')->constrained('ab_experiments')->onDelete('cascade');
                $table->string('session_id', 64)->index();  // Chat session ID
                $table->string('variant', 50);              // 'control' or 'treatment'
                $table->timestamp('assigned_at');
                
                $table->unique(['experiment_id', 'session_id']);
            });
        }

        // Conversion/success events
        if (!Schema::hasTable('ab_conversions')) {
            Schema::create('ab_conversions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('experiment_id')->constrained('ab_experiments')->onDelete('cascade');
                $table->string('session_id', 64)->index();
                $table->string('variant', 50);
                $table->string('event_type', 50);           // 'product_click', 'add_to_cart', 'purchase'
                $table->json('event_data')->nullable();     // Additional context
                $table->timestamp('created_at');
                
                $table->index(['experiment_id', 'variant', 'event_type']);
            });
        }

        // AI response metrics for analysis
        if (!Schema::hasTable('ab_metrics')) {
            Schema::create('ab_metrics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('experiment_id')->constrained('ab_experiments')->onDelete('cascade');
                $table->string('session_id', 64);
                $table->string('variant', 50);
                $table->string('request_id', 36)->index();  // Links to chat request
                $table->unsignedInteger('response_time_ms');  // How long AI took
                $table->unsignedInteger('tokens_used')->nullable();
                $table->boolean('is_fallback')->default(false); // Did we fall back to non-AI?
                $table->float('user_rating')->nullable();   // Optional user feedback (1-5)
                $table->timestamp('created_at');
                
                $table->index(['experiment_id', 'variant']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ab_metrics');
        Schema::dropIfExists('ab_conversions');
        Schema::dropIfExists('ab_assignments');
        Schema::dropIfExists('ab_experiments');
    }
};
