<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeStoreContextJob;
use App\Jobs\SyncHoroshopProductsJob;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Onboarding wizard controller.
 * 
 * Steps:
 * 1. Welcome / Plan selection (skip for trial)
 * 2. Platform connection (Horoshop/Shopify/Manual)
 * 3. Sync products
 * 4. Widget customization
 * 5. Embed code
 */
class OnboardingController extends Controller
{
    /**
     * Get current user's tenant.
     */
    protected function tenant(): Tenant
    {
        return Auth::user()->tenant;
    }

    /**
     * Get current onboarding step.
     */
    protected function getCurrentStep(): int
    {
        $tenant = $this->tenant();
        
        // Step 1: Platform not set
        if (!$tenant->platform) {
            return 1;
        }
        
        // Step 2: Credentials not set (for non-manual)
        if ($tenant->platform !== 'manual' && empty($tenant->platform_credentials)) {
            return 2;
        }
        
        // Step 3: No products synced
        if ($tenant->products()->count() === 0) {
            return 3;
        }
        
        // Widget settings created automatically with good defaults
        // Skip directly to Step 5 (embed code)
        return 5;
    }

    /**
     * Main onboarding page - shows current step.
     */
    public function index(): View|RedirectResponse
    {
        $tenant = $this->tenant();
        $step = $this->getCurrentStep();
        
        // If onboarding complete, go to dashboard
        if ($step === 5 && $tenant->settings['onboarding_completed'] ?? false) {
            return redirect()->route('dashboard');
        }
        
        // Base data for all steps
        $data = [
            'tenant' => $tenant,
            'currentStep' => $step,
            'totalSteps' => 5,
        ];
        
        // Add step-specific data
        switch ($step) {
            case 3:
                $data['productsCount'] = $tenant->products()->count();
                $data['categoriesCount'] = $tenant->products()->distinct('category_path')->count('category_path');
                break;
            case 4:
                $data['settings'] = $tenant->widgetSettings;
                $data['storeContext'] = $tenant->storeContext;
                break;
            case 5:
                $data['embedCode'] = $tenant->getEmbedCode();
                break;
        }
        
        return view('onboarding.index', $data);
    }

    /**
     * Step 1: Select platform.
     */
    public function step1(): View
    {
        $tenant = $this->tenant();
        return view('onboarding.index', [
            'tenant' => $tenant,
            'currentStep' => 1,
            'totalSteps' => 5,
        ]);
    }

    /**
     * Step 1: Save platform selection.
     */
    public function saveStep1(Request $request): RedirectResponse
    {
        $request->validate([
            'platform' => 'required|in:horoshop,shopify,manual',
        ]);

        $this->tenant()->update([
            'platform' => $request->platform,
        ]);

        // Manual platform skips credential step
        if ($request->platform === 'manual') {
            return redirect()->route('onboarding.step3');
        }

        return redirect()->route('onboarding.step2');
    }

    /**
     * Step 2: Platform credentials.
     */
    public function step2(): View
    {
        $tenant = $this->tenant();
        return view('onboarding.index', [
            'tenant' => $tenant,
            'currentStep' => 2,
            'totalSteps' => 5,
        ]);
    }

    /**
     * Step 2: Save credentials and test connection.
     */
    public function saveStep2(Request $request): RedirectResponse|JsonResponse
    {
        $tenant = $this->tenant();
        
        if ($tenant->platform === 'horoshop') {
            $request->validate([
                'api_domain' => 'required|string',
                'api_login' => 'required|string',
                'api_password' => 'required|string',
            ]);

            $credentials = [
                'domain' => rtrim($request->api_domain, '/'),
                'login' => $request->api_login,
                'password' => $request->api_password,
            ];

            // Test connection
            try {
                $testResult = $this->testHoroshopConnection($credentials);
                if (!$testResult['success']) {
                    return back()->withErrors(['connection' => $testResult['error']]);
                }
            } catch (\Exception $e) {
                return back()->withErrors(['connection' => 'Помилка підключення: ' . $e->getMessage()]);
            }

            $tenant->update([
                'platform_credentials' => $credentials,
                'domain' => $credentials['domain'],
            ]);

            // 🚀 Start background onboarding immediately after credentials saved
            // This is NON-BLOCKING - user continues through wizard while sync runs
            \App\Jobs\OnboardTenantJob::dispatch($tenant->id)->onQueue('default');
        }

        return redirect()->route('onboarding.step3');
    }

    /**
     * Test Horoshop API connection.
     */
    protected function testHoroshopConnection(array $credentials): array
    {
        $client = new \GuzzleHttp\Client(['timeout' => 10]);
        
        try {
            // Horoshop API endpoint is /api/auth/ (with trailing slash)
            $response = $client->post($credentials['domain'] . '/api/auth/', [
                'json' => [
                    'login' => $credentials['login'],
                    'password' => $credentials['password'],
                ],
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            // Horoshop returns status: "OK" and response.token
            if (($data['status'] ?? '') === 'OK' && isset($data['response']['token'])) {
                return ['success' => true, 'token' => $data['response']['token']];
            }
            
            // Get error message from response
            $errorMessage = $data['response']['message'] ?? 'Невірні облікові дані';
            return ['success' => false, 'error' => $errorMessage];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = json_decode($e->getResponse()->getBody(), true);
            $errorMessage = $body['response']['message'] ?? $e->getMessage();
            return ['success' => false, 'error' => $errorMessage];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Step 3: Sync products.
     */
    public function step3(): View
    {
        $tenant = $this->tenant();
        
        return view('onboarding.index', [
            'tenant' => $tenant,
            'currentStep' => 3,
            'totalSteps' => 5,
            'productsCount' => $tenant->products()->count(),
            'categoriesCount' => $tenant->products()->distinct('category_path')->count('category_path'),
        ]);
    }

    // NOTE: startSync() and syncStatus() removed - OnboardTenantJob handles everything async
    // Progress is tracked via TenantOnboardingProgress model and Livewire component

    /**
     * Step 3: Proceed to next step.
     */
    public function saveStep3(): RedirectResponse
    {
        $tenant = $this->tenant();
        
        // Dispatch AI analysis job for store context
        if ($tenant->products()->count() > 0) {
            AnalyzeStoreContextJob::dispatch($tenant->id);
            
            // 🧠 CRITICAL: Trigger AI enrichment for product search
            // This ensures all products have keywords, slang, categories in product_ai_index
            $productsCount = $tenant->products()->where('in_stock', true)->count();
            \App\Jobs\AnalyzeProductsWithAiJob::dispatch(
                batchSize: min(100, $productsCount),
                offset: 0,
                forceReanalyze: false,
                tenantId: $tenant->id
            )->onQueue('default');
            
            // 🔍 Trigger Meilisearch indexing
            \App\Jobs\IndexProductsToMeiliJob::dispatch($tenant->id)
                ->delay(now()->addSeconds(10))
                ->onQueue('default');
        }
        
        // Create default widget settings automatically
        $this->createDefaultWidgetSettings($tenant);
        
        // Skip widget customization step, go directly to embed code
        return redirect()->route('onboarding.step5');
    }
    
    /**
     * Create default widget settings for tenant.
     */
    protected function createDefaultWidgetSettings(Tenant $tenant): void
    {
        $tenant->widgetSettings()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'primary_color' => '#000000',
                'text_color' => '#ffffff',
                'position' => 'bottom-right',
                'start_state' => 'closed',
                'glow_color' => '#798ffb',
                'bot_name' => 'AIntento',
                'welcome_message' => 'Вітаю! 👋 Чим можу допомогти?',
                'input_placeholder' => 'Напишіть, що шукаєте...',
                'shop_phone' => '', // Empty by default, tenant fills in settings
                'callback_form_url' => '', // Empty by default
                'enable_delivery_tracking' => false, // Disabled by default
            ]
        );
    }

    /**
     * Step 4: Widget customization.
     */
    public function step4(): View
    {
        $tenant = $this->tenant();
        $settings = $tenant->widgetSettings;
        
        return view('onboarding.index', [
            'tenant' => $tenant,
            'currentStep' => 4,
            'totalSteps' => 5,
            'settings' => $settings,
            'storeContext' => $tenant->storeContext,
        ]);
    }

    /**
     * Step 4: Save widget settings.
     */
    public function saveStep4(Request $request): RedirectResponse
    {
        $request->validate([
            'primary_color' => 'required|string|max:20',
            'header_text' => 'required|string|max:100',
            'welcome_message' => 'required|string|max:500',
            'position' => 'required|in:bottom-right,bottom-left',
        ]);

        $tenant = $this->tenant();
        
        $tenant->widgetSettings()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'primary_color' => $request->primary_color,
                'header_text' => $request->header_text,
                'welcome_message' => $request->welcome_message,
                'position' => $request->position,
            ]
        );

        return redirect()->route('onboarding.step5');
    }

    /**
     * Step 5: Embed code and finish.
     */
    public function step5(): View
    {
        $tenant = $this->tenant();
        
        return view('onboarding.index', [
            'tenant' => $tenant,
            'currentStep' => 5,
            'totalSteps' => 5,
            'embedCode' => $tenant->getEmbedCode(),
        ]);
    }

    /**
     * Complete onboarding.
     */
    public function complete(): RedirectResponse
    {
        $tenant = $this->tenant();
        
        $tenant->update([
            'settings' => array_merge($tenant->settings ?? [], [
                'onboarding_completed' => true,
                'onboarding_completed_at' => now()->toIso8601String(),
            ]),
        ]);

        return redirect()->route('dashboard')->with('success', 'Вітаємо! Ваш AI-асистент готовий до роботи.');
    }

    /**
     * Get AI enrichment progress for current tenant.
     * Used for progress bar on step 5.
     */
    public function enrichmentProgress(): JsonResponse
    {
        $tenant = $this->tenant();
        
        // Count total products in stock
        $totalProducts = $tenant->products()
            ->where('in_stock', true)
            ->count();
        
        // Count products with AI enrichment
        $enrichedProducts = \App\Models\ProductAiIndex::where('tenant_id', $tenant->id)
            ->whereNotNull('keywords')
            ->count();
        
        // Count products indexed in Meilisearch (via search_index field)
        $meiliIndexed = $tenant->products()
            ->where('in_stock', true)
            ->whereNotNull('search_index')
            ->where('search_index', '!=', '')
            ->count();
        
        // Calculate percentages
        $enrichmentPercent = $totalProducts > 0 
            ? round(($enrichedProducts / $totalProducts) * 100, 1) 
            : 0;
        $meiliPercent = $totalProducts > 0 
            ? round(($meiliIndexed / $totalProducts) * 100, 1) 
            : 0;
        
        // Overall progress: AI enrichment (60%) + Meili indexing (40%)
        $overallPercent = round(($enrichmentPercent * 0.6) + ($meiliPercent * 0.4), 1);
        
        // Determine status
        $status = 'processing';
        if ($enrichmentPercent >= 100 && $meiliPercent >= 100) {
            $status = 'completed';
        } elseif ($totalProducts === 0) {
            $status = 'no_products';
        }
        
        return response()->json([
            'status' => $status,
            'total_products' => $totalProducts,
            'ai_enrichment' => [
                'completed' => $enrichedProducts,
                'percent' => $enrichmentPercent,
            ],
            'meili_indexing' => [
                'completed' => $meiliIndexed,
                'percent' => $meiliPercent,
            ],
            'overall_percent' => $overallPercent,
        ]);
    }
}
