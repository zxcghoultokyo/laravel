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
        
        // Step 4: Widget not customized (check if still default)
        $settings = $tenant->widgetSettings;
        if (!$settings || $settings->header_text === 'AI Асистент') {
            return 4;
        }
        
        // All done
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

    /**
     * Step 3: Start sync job.
     */
    public function startSync(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        
        if ($tenant->platform === 'horoshop') {
            // Set sync running flag
            \Illuminate\Support\Facades\Cache::put("sync_running_{$tenant->id}", true, 3600);
            
            // Dispatch sync job
            SyncHoroshopProductsJob::dispatch($tenant->id);
            
            return response()->json([
                'status' => 'started',
                'message' => 'Синхронізація розпочата',
            ]);
        }
        
        return response()->json([
            'status' => 'skip',
            'message' => 'Пропущено (ручний режим)',
        ]);
    }

    /**
     * Step 3: Check sync status.
     */
    public function syncStatus(): JsonResponse
    {
        $tenant = $this->tenant();
        
        $productsCount = $tenant->products()->count();
        $categoriesCount = $tenant->products()->distinct('category_path')->count('category_path');
        
        return response()->json([
            'products' => $productsCount,
            'categories' => $categoriesCount,
            'completed' => $productsCount > 0,
            'lastSync' => $tenant->last_sync_at?->diffForHumans(),
        ]);
    }

    /**
     * Step 3: Proceed to next step.
     */
    public function saveStep3(): RedirectResponse
    {
        $tenant = $this->tenant();
        
        // Dispatch AI analysis job
        if ($tenant->products()->count() > 0) {
            AnalyzeStoreContextJob::dispatch($tenant->id);
        }
        
        return redirect()->route('onboarding.step4');
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
}
