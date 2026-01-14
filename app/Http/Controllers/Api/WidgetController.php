<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Greeting;
use App\Models\WidgetSettings;
use App\Services\Store\StoreContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WidgetController extends Controller
{
    public function __construct(private StoreContextService $storeContext)
    {
    }
    
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
            'font_family' => $settings->font_family,
            'show_shadow' => $settings->show_shadow ?? true,
            // Bot branding
            'bot_name' => $settings->bot_name ?: $settings->store_name ?: 'AIntento',
            'bot_avatar_url' => $settings->bot_avatar_url,
            'bot_status_text' => $settings->bot_status_text ?: 'Завжди онлайн',
            'logo_url' => $settings->logo_url,
            // Messages
            'welcome_message' => $settings->welcome_message,
            'input_placeholder' => $settings->input_placeholder,
            'consent_notice' => $settings->consent_notice,
            // Greetings & Tone
            'greetings' => $settings->greetings ?? [],
            'tone' => $settings->tone ?? 'official',
            'brand_rules' => $settings->brand_rules ?? [],
            // Store info for quick actions
            'store_phone' => $settings->shop_phone,
            'store_address' => $settings->faq_contacts_text,
            'store_hours' => $settings->store_hours,
            'store_about' => $settings->faq_about_text,
        ] : [
            'enabled' => true,
            'primary_color' => '#2563eb',
            'text_color' => '#ffffff',
            'position' => 'right',
            'border_radius' => 12,
            'font_family' => null,
            'show_shadow' => true,
            'bot_name' => 'AIntento',
            'bot_avatar_url' => null,
            'bot_status_text' => 'Завжди онлайн',
            'logo_url' => null,
            'welcome_message' => 'Вітаю! 👋 Я AIntento — ваш персональний помічник. Чим можу допомогти?',
            'input_placeholder' => 'Напишіть повідомлення...',
            'consent_notice' => null,
            'greetings' => [],
            'tone' => 'official',
            'brand_rules' => [],
            'store_phone' => null,
            'store_address' => null,
            'store_hours' => null,
            'store_about' => null,
        ];
        
        return response()->json($data, 200, [
            'Content-Type' => 'application/json',
        ]);
    }
    
    /**
     * Get AI context settings for admin panel.
     */
    public function getAiContext(): JsonResponse
    {
        $settings = WidgetSettings::first();
        
        if (!$settings) {
            return response()->json(['error' => 'Settings not found'], 404);
        }
        
        return response()->json([
            'store_name' => $settings->store_name,
            'store_context' => $settings->store_context,
            'store_description' => $settings->store_description,
            'store_hours' => $settings->store_hours,
            'customer_types' => $settings->customer_types ?? [],
            'product_categories' => $settings->product_categories ?? [],
            'accessory_keywords' => $settings->accessory_keywords ?? [],
            'main_product_keywords' => $settings->main_product_keywords ?? [],
            'brand_transliterations' => $settings->brand_transliterations ?? [],
            'ai_use_dynamic_prompts' => $settings->ai_use_dynamic_prompts,
            'ai_strict_category_filter' => $settings->ai_strict_category_filter,
        ]);
    }
    
    /**
     * Update AI context settings.
     */
    public function updateAiContext(Request $request): JsonResponse
    {
        $settings = WidgetSettings::first();
        
        if (!$settings) {
            return response()->json(['error' => 'Settings not found'], 404);
        }
        
        $validated = $request->validate([
            'store_name' => 'nullable|string|max:255',
            'store_context' => 'nullable|string|max:1000',
            'store_description' => 'nullable|string|max:5000',
            'store_hours' => 'nullable|string|max:255',
            'customer_types' => 'nullable|array',
            'customer_types.*' => 'string|max:100',
            'product_categories' => 'nullable|array',
            'product_categories.*' => 'string|max:100',
            'accessory_keywords' => 'nullable|array',
            'accessory_keywords.*' => 'string|max:100',
            'main_product_keywords' => 'nullable|array',
            'main_product_keywords.*' => 'string|max:100',
            'brand_transliterations' => 'nullable|array',
            'ai_use_dynamic_prompts' => 'nullable|boolean',
            'ai_strict_category_filter' => 'nullable|boolean',
        ]);
        
        $settings->update($validated);
        
        // Clear store context cache
        $this->storeContext->clearCache();
        
        Log::info('AI context updated', ['validated' => $validated]);
        
        return response()->json([
            'success' => true,
            'message' => 'AI context updated successfully',
        ]);
    }

    /**
     * Get appropriate greeting based on visitor context.
     * 
     * Query params:
     * - utm_campaign, utm_source, utm_medium: UTM parameters
     * - url: Current page URL
     * - category: Current category path
     * - device: 'mobile' or 'desktop'
     * - is_returning: boolean (based on session storage)
     * - language: Browser language
     */
    public function greeting(Request $request): JsonResponse
    {
        $context = [
            'utm_campaign' => $request->query('utm_campaign'),
            'utm_source' => $request->query('utm_source'),
            'utm_medium' => $request->query('utm_medium'),
            'url' => $request->query('url'),
            'category' => $request->query('category'),
            'device' => $request->query('device'),
            'is_returning' => filter_var($request->query('is_returning'), FILTER_VALIDATE_BOOLEAN),
            'language' => $request->query('language'),
        ];

        $greeting = Greeting::matchContext($context);

        if (!$greeting) {
            // No greetings in DB, return default
            $settings = WidgetSettings::first();
            return response()->json([
                'message' => $settings?->welcome_message ?? 'Вітаю! 👋 Чим можу допомогти?',
                'quick_actions' => [],
                'matched_greeting_id' => null,
                'matched_greeting_name' => null,
            ]);
        }

        return response()->json([
            'message' => $greeting->message,
            'quick_actions' => $greeting->quick_actions ?? [],
            'matched_greeting_id' => $greeting->id,
            'matched_greeting_name' => $greeting->name,
        ]);
    }
}
