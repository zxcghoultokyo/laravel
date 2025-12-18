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
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'border_radius' => 'integer',
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
