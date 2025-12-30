<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique()->comment('Horoshop order_id');
            $table->unsignedTinyInteger('status_code')->default(1)->index();
            $table->string('status_label', 50)->nullable();
            $table->string('currency', 10)->default('UAH');
            
            // Totals
            $table->decimal('total_default', 12, 2)->default(0);
            $table->decimal('total_sum', 12, 2)->default(0);
            $table->unsignedInteger('total_quantity')->default(0);
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->string('coupon_code', 50)->nullable();
            $table->decimal('coupon_discount_value', 12, 2)->default(0);
            
            // Customer
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_city')->nullable();
            $table->text('customer_address')->nullable();
            
            // Delivery
            $table->unsignedSmallInteger('delivery_type_id')->nullable();
            $table->string('delivery_type_title')->nullable();
            $table->decimal('delivery_price', 12, 2)->default(0);
            $table->text('delivery_comment')->nullable();
            
            // Payment
            $table->unsignedSmallInteger('payment_type_id')->nullable();
            $table->string('payment_type_title')->nullable();
            $table->decimal('payment_price', 12, 2)->default(0);
            $table->boolean('payed')->default(false);
            
            // Raw data for reference
            $table->json('raw')->nullable();
            
            $table->timestamp('ordered_at')->nullable()->index();
            $table->timestamps();
            
            $table->index(['status_code', 'ordered_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('article', 50)->index();
            $table->string('title');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('discount_marker', 30)->nullable();
            $table->string('type', 20)->default('product');
            $table->timestamps();
            
            $table->index(['article', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
