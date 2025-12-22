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
        'logo_url',
        'welcome_message',
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
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'border_radius' => 'integer',
        'enable_delivery_tracking' => 'boolean',
        'enable_faq_from_horoshop' => 'boolean',
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
}
