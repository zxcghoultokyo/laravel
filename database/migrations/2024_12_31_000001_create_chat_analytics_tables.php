<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chat events - всі події в чаті
        if (!Schema::hasTable('chat_events')) {
        Schema::create('chat_events', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->index();
            $table->string('merchant_id', 64)->nullable()->index(); // ID магазину
            $table->string('event_type', 50)->index(); // message, product_view, product_click, add_to_cart, checkout, purchase
            $table->string('event_source', 30)->default('widget'); // widget, api, webhook
            
            // Event data
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_article', 100)->nullable();
            $table->decimal('product_price', 12, 2)->nullable();
            $table->string('message_type', 30)->nullable(); // user, assistant, products
            $table->text('message_text')->nullable();
            
            // Attribution data
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            
            // Client info
            $table->string('client_id', 64)->nullable()->index(); // Анонімний ID клієнта
            $table->string('device_type', 20)->nullable(); // mobile, desktop, tablet
            $table->string('page_url', 500)->nullable();
            $table->string('referrer', 500)->nullable();
            
            $table->json('metadata')->nullable(); // Додаткові дані
            $table->timestamp('created_at')->useCurrent()->index();
            
            $table->index(['session_id', 'event_type']);
            $table->index(['merchant_id', 'created_at']);
            $table->index(['client_id', 'created_at']);
        });
        }

        // Conversions - атрибутовані конверсії
        if (!Schema::hasTable('chat_conversions')) {
        Schema::create('chat_conversions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->index();
            $table->string('merchant_id', 64)->index();
            $table->string('client_id', 64)->nullable()->index();
            
            // Conversion type
            $table->string('conversion_type', 30)->index(); // add_to_cart, checkout, purchase, lead
            $table->string('conversion_status', 30)->default('pending'); // pending, confirmed, cancelled
            
            // Order data (aggregated, no PII)
            $table->string('order_id', 100)->nullable();
            $table->decimal('order_total', 12, 2)->nullable();
            $table->unsignedInteger('items_count')->nullable();
            $table->json('product_ids')->nullable(); // IDs товарів з чату що були куплені
            
            // Attribution
            $table->string('attribution_model', 30)->default('last_touch'); // last_touch, first_touch, linear
            $table->unsignedInteger('attribution_window_hours')->default(72);
            $table->timestamp('chat_timestamp')->nullable(); // Коли був чат
            $table->timestamp('conversion_timestamp')->nullable(); // Коли була конверсія
            $table->unsignedInteger('minutes_to_conversion')->nullable();
            
            // Was product from chat?
            $table->boolean('product_from_chat')->default(false); // Чи був куплений товар з рекомендацій чату
            $table->decimal('chat_attributed_value', 12, 2)->nullable(); // Сума товарів з чату
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['merchant_id', 'conversion_type', 'created_at']);
            $table->index(['session_id', 'conversion_type']);
        });
        }

        // Session outcomes - результат сесії
        if (!Schema::hasTable('chat_session_outcomes')) {
        Schema::create('chat_session_outcomes', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 64)->unique();
            $table->string('merchant_id', 64)->index();
            
            // Outcome
            $table->string('outcome', 50)->index(); // order_placed, add_to_cart, lead_captured, handoff_to_manager, no_answer, no_relevant_products, user_abandoned, out_of_scope
            $table->string('outcome_category', 20)->index(); // success, failure, neutral
            
            // Session stats
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('products_shown')->default(0);
            $table->unsignedInteger('products_clicked')->default(0);
            $table->unsignedInteger('add_to_cart_count')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            
            // Features used
            $table->boolean('used_search')->default(false);
            $table->boolean('used_filters')->default(false);
            $table->boolean('used_cross_sell')->default(false);
            $table->boolean('used_size_guide')->default(false);
            $table->boolean('escalated_to_human')->default(false);
            $table->boolean('left_contact')->default(false);
            
            // Quality signals
            $table->tinyInteger('user_rating')->nullable(); // 1-5
            $table->string('user_feedback', 500)->nullable();
            $table->boolean('had_fallback')->default(false); // Бот не зміг відповісти
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['merchant_id', 'outcome', 'created_at']);
        });
        }

        // Daily aggregates for dashboards
        if (!Schema::hasTable('chat_daily_stats')) {
        Schema::create('chat_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('merchant_id', 64)->index();
            
            // Volume
            $table->unsignedInteger('sessions_count')->default(0);
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unique_users')->default(0);
            
            // Engagement
            $table->decimal('avg_messages_per_session', 5, 2)->nullable();
            $table->decimal('avg_session_duration_seconds', 8, 2)->nullable();
            $table->unsignedInteger('empty_sessions')->default(0); // 1-2 messages
            
            // Conversions
            $table->unsignedInteger('add_to_cart_sessions')->default(0);
            $table->unsignedInteger('purchase_sessions')->default(0);
            $table->unsignedInteger('lead_sessions')->default(0);
            $table->decimal('conversion_rate', 5, 4)->nullable(); // % сесій з конверсією
            
            // Revenue attribution
            $table->decimal('total_attributed_revenue', 14, 2)->default(0);
            $table->decimal('avg_order_value', 12, 2)->nullable();
            
            // Quality
            $table->unsignedInteger('successful_outcomes')->default(0);
            $table->unsignedInteger('failed_outcomes')->default(0);
            $table->decimal('success_rate', 5, 4)->nullable();
            $table->decimal('avg_user_rating', 3, 2)->nullable();
            
            // Features
            $table->unsignedInteger('cross_sell_shown')->default(0);
            $table->unsignedInteger('cross_sell_clicked')->default(0);
            $table->unsignedInteger('escalations')->default(0);
            
            $table->timestamps();
            
            $table->unique(['date', 'merchant_id']);
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_daily_stats');
        Schema::dropIfExists('chat_session_outcomes');
        Schema::dropIfExists('chat_conversions');
        Schema::dropIfExists('chat_events');
    }
};
