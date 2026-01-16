<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * Controller for dynamic widget serving per tenant.
 */
class TenantWidgetController extends Controller
{
    /**
     * Serve the widget JS file for a tenant.
     * Route: GET /widget/{slug}.js
     */
    public function serveWidget(string $slug): mixed
    {
        $tenant = Tenant::where('slug', $slug)
                        ->where('status', Tenant::STATUS_ACTIVE)
                        ->first();

        if (!$tenant) {
            return response("console.error('Widget: Unknown tenant');", 200)
                ->header('Content-Type', 'application/javascript');
        }

        // Check trial expiration
        if ($tenant->isTrialExpired()) {
            return response("console.warn('Widget: Trial expired');", 200)
                ->header('Content-Type', 'application/javascript');
        }

        // Load widget settings
        $settings = $tenant->widgetSettings ?? null;
        
        // Build widget config
        $config = [
            'tenant' => $tenant->slug,
            'apiUrl' => config('app.url') . '/api',
            'primaryColor' => $settings?->primary_color ?? '#007bff',
            'headerText' => $settings?->header_text ?? 'Чат-помічник',
            'welcomeMessage' => $settings?->welcome_message ?? 'Привіт! Чим можу допомогти?',
            'position' => $settings?->position ?? 'bottom-right',
            'showOnMobile' => $settings?->show_on_mobile ?? true,
        ];

        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);

        // Return widget loader JS
        $widgetJs = $this->generateWidgetLoader($configJson, $tenant->slug);
        
        return response($widgetJs, 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Get widget configuration for a tenant.
     * Route: GET /api/widget/{slug}/config
     */
    public function getConfig(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)
                        ->where('status', Tenant::STATUS_ACTIVE)
                        ->first();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!$tenant->isActive()) {
            return response()->json([
                'error' => 'Tenant inactive',
                'reason' => $tenant->isTrialExpired() ? 'trial_expired' : 'suspended',
            ], 403);
        }

        $settings = $tenant->widgetSettings;

        return response()->json([
            'tenant' => $tenant->slug,
            'name' => $tenant->name,
            'settings' => [
                'primary_color' => $settings?->primary_color ?? '#007bff',
                'header_text' => $settings?->header_text ?? 'Чат-помічник',
                'welcome_message' => $settings?->welcome_message ?? 'Привіт! Чим можу допомогти?',
                'position' => $settings?->position ?? 'bottom-right',
                'show_on_mobile' => $settings?->show_on_mobile ?? true,
                'avatar_url' => $settings?->avatar_url,
                'custom_css' => $settings?->custom_css,
            ],
        ]);
    }

    /**
     * Generate widget loader JavaScript.
     */
    protected function generateWidgetLoader(string $configJson, string $tenantSlug): string
    {
        $baseUrl = config('app.url');
        
        return <<<JS
(function() {
    'use strict';
    
    var AILURE_CONFIG = {$configJson};
    AILURE_CONFIG.tenantSlug = '{$tenantSlug}';
    
    // Prevent double initialization
    if (window.AilureWidgetInitialized) return;
    window.AilureWidgetInitialized = true;
    window.AilureConfig = AILURE_CONFIG;
    
    // Load main widget script (with cache buster)
    var script = document.createElement('script');
    script.src = '{$baseUrl}/widget.js?v=2.5.6';
    script.async = true;
    script.onload = function() {
        if (typeof window.AilureWidget !== 'undefined') {
            window.AilureWidget.init(AILURE_CONFIG);
        }
    };
    document.head.appendChild(script);
    
    // Load widget styles
    var style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = '{$baseUrl}/widget.css';
    document.head.appendChild(style);
})();
JS;
    }

    /**
     * Get embed instructions for a tenant.
     * Route: GET /api/widget/{slug}/embed
     */
    public function getEmbedCode(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->first();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $widgetUrl = config('app.widget_url', config('app.url'));
        
        return response()->json([
            'embed_code' => "<script src=\"{$widgetUrl}/widget/{$tenant->slug}.js\" async></script>",
            'tenant' => $tenant->slug,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
        ]);
    }
}
