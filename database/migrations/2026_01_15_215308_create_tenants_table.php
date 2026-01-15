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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            
            // Basic info
            $table->string('name');                          // "Contractor Tactical"
            $table->string('slug', 100)->unique();           // "contractor" → widget URL
            $table->string('domain')->nullable();            // "contractor.com.ua"
            $table->string('email')->nullable();             // Contact email
            
            // Plan & Billing
            $table->string('plan', 50)->default('trial');    // trial, starter, pro, enterprise
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('plan_expires_at')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            
            // Usage limits
            $table->integer('messages_limit')->default(100);  // per month
            $table->integer('messages_used')->default(0);
            $table->timestamp('usage_reset_at')->nullable();  // monthly reset
            
            // Platform integration
            $table->string('platform', 50)->nullable();       // horoshop, shopify, manual
            $table->json('platform_credentials')->nullable(); // encrypted API keys
            $table->timestamp('last_sync_at')->nullable();
            
            // Status
            $table->string('status', 20)->default('active'); // active, suspended, cancelled
            $table->text('suspension_reason')->nullable();
            
            // Settings
            $table->json('settings')->nullable();             // misc tenant settings
            
            $table->timestamps();
            
            $table->index('slug');
            $table->index('status');
            $table->index('plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
