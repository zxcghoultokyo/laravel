<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proactive_trigger_rules', function (Blueprint $table) {
            $table->id();
            
            // Rule identification
            $table->string('name', 100); // Human-readable name
            $table->string('trigger_type', 50); // exit_intent, time_on_page, utm_campaign, returning_visitor, pdp_no_variant
            $table->boolean('is_enabled')->default(true);
            $table->integer('priority')->default(100); // Lower = higher priority
            
            // Conditions (JSON)
            // Examples:
            // - utm_campaign: {"utm_source": "google", "utm_medium": "cpc"}
            // - time_on_page: {"min_seconds": 40, "page_type": "product"}
            // - exit_intent: {"min_time_on_page": 5, "page_types": ["product", "category"]}
            $table->json('conditions')->nullable();
            
            // Display settings
            $table->text('message'); // Main message text (supports {{variables}})
            $table->string('button_text', 50)->default('Показати');
            $table->string('icon', 20)->nullable(); // Emoji or icon class
            
            // Action configuration
            $table->string('action_type', 30)->default('open_chat'); // open_chat, open_chat_with_context, show_products
            $table->json('action_config')->nullable(); // {"query": "...", "category": "...", "price_max": 2000}
            
            // Frequency limits
            $table->integer('max_per_session')->default(1);
            $table->integer('max_per_day')->default(3);
            $table->integer('cooldown_minutes')->default(30);
            
            // Statistics
            $table->unsignedBigInteger('shown_count')->default(0);
            $table->unsignedBigInteger('clicked_count')->default(0);
            $table->unsignedBigInteger('converted_count')->default(0); // Added to cart
            $table->unsignedBigInteger('purchased_count')->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index(['trigger_type', 'is_enabled']);
            $table->index('priority');
        });
        
        // Create analytics table for detailed tracking
        Schema::create('proactive_trigger_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('proactive_trigger_rules')->onDelete('cascade');
            $table->string('session_id', 64)->index();
            $table->string('event_type', 30); // shown, clicked, dismissed, converted, purchased
            $table->json('context')->nullable(); // Page URL, product info, UTM params
            $table->timestamps();
            
            $table->index(['rule_id', 'event_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proactive_trigger_events');
        Schema::dropIfExists('proactive_trigger_rules');
    }
};
