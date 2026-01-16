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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('horoshop_order_id')->unique()->index();
            $table->string('session_id')->nullable()->index();
            $table->string('user_id')->nullable(); // Horoshop user id
            
            // Customer info
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_city')->nullable();
            
            // Order totals
            $table->decimal('total_sum', 12, 2)->default(0);
            $table->decimal('total_default', 12, 2)->default(0); // without discounts
            $table->integer('total_quantity')->default(0);
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->string('currency', 10)->default('UAH');
            
            // Status
            $table->tinyInteger('status')->default(1); // 1=new, 2=processing, 3=delivered, 4=not_delivered
            $table->string('status_name')->nullable();
            $table->boolean('is_paid')->default(false);
            
            // Delivery
            $table->string('delivery_type')->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('delivery_price', 10, 2)->nullable();
            
            // Payment
            $table->string('payment_type')->nullable();
            
            // Products (JSON array)
            $table->json('products')->nullable();
            
            // Analytics (UTM etc)
            $table->json('analytics')->nullable();
            
            // Raw API response for debugging
            $table->json('raw_data')->nullable();
            
            // Chat attribution
            $table->boolean('had_chat')->default(false);
            $table->integer('products_from_chat')->default(0);
            
            // Timestamps from Horoshop
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('ordered_at');
            $table->index('status');
            $table->index('had_chat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
