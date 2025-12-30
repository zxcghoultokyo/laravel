<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WidgetSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WidgetController extends Controller
{
    public function settings(): JsonResponse
    {
        Log::info('WidgetController::settings called');
        
        $settings = WidgetSettings::first();
        
        Log::info('WidgetSettings fetched', ['settings' => $settings]);
        
        $data = $settings ? [
            'enabled' => $settings->enabled,
            'primary_color' => $settings->primary_color,
            'text_color' => $settings->text_color,
            'position' => $settings->position,
            'border_radius' => $settings->border_radius,
            'welcome_message' => $settings->welcome_message,
            'input_placeholder' => $settings->input_placeholder,
            'consent_notice' => $settings->consent_notice,
            // Store info for quick actions
            'store_phone' => $settings->shop_phone,
            'store_address' => $settings->faq_contacts_text,
            'store_hours' => null, // Can be added to WidgetSettings model later
            'store_about' => $settings->faq_about_text,
        ] : [
            'enabled' => true,
            'primary_color' => '#2563eb',
            'text_color' => '#ffffff',
            'position' => 'right',
            'border_radius' => 12,
            'welcome_message' => 'Вітаю! 👋 Я AILure асистент. Чим можу допомогти?',
            'input_placeholder' => 'Напишіть повідомлення...',
            'consent_notice' => null,
            'store_phone' => null,
            'store_address' => null,
            'store_hours' => null,
            'store_about' => null,
        ];
        
        return response()->json($data, 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}
