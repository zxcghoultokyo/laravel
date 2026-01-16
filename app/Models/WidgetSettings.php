<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WidgetSettings extends Model
{
    protected $fillable = [
        'domain',
        'primary_color',
        'text_color',
        'position',
        'start_state',
        'border_radius',
        'font_family',
        'show_shadow',
        'logo_url',
        'bot_name',
        'bot_avatar_url',
        'bot_avatar_base64',
        'glow_color',
        'bot_status_text',
        'welcome_message',
        'greetings',
        'tone',
        'brand_rules',
        'input_placeholder',
        'consent_notice',
        'enabled',
        'api_token',
        'shop_phone',
        'callback_form_url',
        'nova_poshta_tracking_url',
        'enable_delivery_tracking',
        'enable_faq_from_horoshop',
        'horoshop_domain',
        'horoshop_api_login',
        'horoshop_api_password',
        'enable_faq_custom_content',
        'faq_payment_delivery_url',
        'faq_payment_delivery_text',
        'faq_returns_url',
        'faq_returns_text',
        'faq_contacts_url',
        'faq_contacts_text',
        'faq_about_url',
        'faq_about_text',
        // AI context fields
        'store_name',
        'store_context',
        'store_description',
        'customer_types',
        'product_categories',
        'accessory_keywords',
        'main_product_keywords',
        'brand_transliterations',
        'store_hours',
        'ai_use_dynamic_prompts',
        'ai_strict_category_filter',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'border_radius' => 'integer',
        'show_shadow' => 'boolean',
        'enable_delivery_tracking' => 'boolean',
        'enable_faq_from_horoshop' => 'boolean',
        'enable_faq_custom_content' => 'boolean',
        // JSON fields
        'greetings' => 'array',
        'brand_rules' => 'array',
        // JSON fields for AI context
        'customer_types' => 'array',
        'product_categories' => 'array',
        'accessory_keywords' => 'array',
        'main_product_keywords' => 'array',
        'brand_transliterations' => 'array',
        'ai_use_dynamic_prompts' => 'boolean',
        'ai_strict_category_filter' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->api_token) {
                $model->api_token = bin2hex(random_bytes(32));
            }
            if (! $model->welcome_message) {
                $model->welcome_message = 'Вітаю! 👋 Я AILure Асистент. Напишіть, що шукаєте.';
            }
        });
    }

    /**
     * Store context relationship.
     */
    public function storeContext()
    {
        return $this->hasOne(StoreContext::class);
    }
}
