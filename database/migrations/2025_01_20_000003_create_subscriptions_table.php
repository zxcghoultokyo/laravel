<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // Plan details
            $table->string('plan_id', 50); // starter, pro, enterprise
            $table->string('status', 30)->default('trialing'); // trialing, active, past_due, cancelled, unpaid
            
            // Provider info
            $table->string('provider', 30); // wayforpay, liqpay
            $table->string('provider_subscription_id')->nullable();
            $table->string('provider_customer_id')->nullable();
            
            // Period tracking
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            
            // Cancellation
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            
            // Extra data
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('tenant_id');
            $table->index('status');
            $table->index('provider');
            $table->index(['provider', 'provider_subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
