<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }
        
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_id')->nullable()->constrained()->onDelete('set null');
            
            // Amount in kopecks (cents)
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('UAH');
            
            // Status
            $table->string('status', 30)->default('pending'); // pending, processing, success, failed, refunded
            
            // Provider info
            $table->string('provider', 30); // wayforpay, liqpay
            $table->string('provider_payment_id')->nullable(); // Transaction ID from provider
            $table->string('provider_order_id')->nullable(); // Order ID sent to provider
            
            // Description
            $table->string('description')->nullable();
            
            // Card info (masked)
            $table->string('card_mask', 30)->nullable(); // **** 1234
            $table->string('card_type', 20)->nullable(); // Visa, Mastercard
            $table->string('card_bank')->nullable(); // ПриватБанк, Моно
            
            // Timestamps
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedBigInteger('refunded_amount')->nullable();
            
            // Extra data
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('tenant_id');
            $table->index('subscription_id');
            $table->index('status');
            $table->index('provider');
            $table->index(['provider', 'provider_payment_id']);
            $table->index(['provider', 'provider_order_id']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
