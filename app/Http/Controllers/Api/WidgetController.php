<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WidgetSettings;
use Illuminate\Http\JsonResponse;

class WidgetController extends Controller
{
    public function settings(): JsonResponse
    {
        $settings = WidgetSettings::first();
        
        if (!$settings) {
            return response()->json([
                'enabled' => true,
                'primary_color' => '#2563eb',
                'text_color' => '#ffffff',
                'position' => 'right',
                'border_radius' => 12,
                'welcome_message' => 'Вітаю! 👋 Я AILure асистент. Чим можу допомогти?',
                'input_placeholder' => 'Напишіть повідомлення...',
                'consent_notice' => null,
            ]);
        }
        
        return response()->json([
            'enabled' => $settings->enabled,
            'primary_color' => $settings->primary_color,
            'text_color' => $settings->text_color,
            'position' => $settings->position,
            'border_radius' => $settings->border_radius,
            'welcome_message' => $settings->welcome_message,
            'input_placeholder' => $settings->input_placeholder,
            'consent_notice' => $settings->consent_notice,
        ]);
    }
}
