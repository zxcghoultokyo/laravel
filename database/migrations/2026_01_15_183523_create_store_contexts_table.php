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
        if (Schema::hasTable('store_contexts')) {
            return;
        }

        Schema::create('store_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('widget_settings_id')->nullable()->constrained('widget_settings')->nullOnDelete();

            // Auto-detected from products
            $table->string('store_type')->default('general'); // tactical, fashion, electronics, general
            $table->json('primary_categories')->nullable();   // ["Плитоноски", "Шоломи"]
            $table->json('brands')->nullable();               // ["Crye", "Ops-Core"]
            $table->json('price_segments')->nullable();       // {budget: 2000, mid: 5000, premium: 15000}
            $table->string('catalog_size')->default('medium'); // small, medium, large

            // From FAQ/Policies
            $table->text('delivery_info')->nullable();
            $table->text('payment_info')->nullable();
            $table->text('return_policy')->nullable();
            $table->text('warranty_info')->nullable();
            $table->string('working_hours')->nullable();

            // For prompt generation
            $table->json('expertise_areas')->nullable();      // ["бронезахист", "тактичне спорядження"]
            $table->json('common_questions')->nullable();     // Most asked questions
            $table->json('product_tips')->nullable();         // ["плити мають відповідати розміру"]

            // Generated prompt
            $table->text('generated_prompt')->nullable();
            $table->integer('prompt_version')->default(1);
            $table->timestamp('last_analyzed_at')->nullable();

            $table->timestamps();

            $table->index('store_type');
            $table->index('widget_settings_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_contexts');
    }
};
