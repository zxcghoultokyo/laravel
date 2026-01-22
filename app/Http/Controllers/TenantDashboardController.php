<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Tenant dashboard controller.
 */
class TenantDashboardController extends Controller
{
    /**
     * Get current user's tenant.
     */
    protected function tenant(): Tenant
    {
        return Auth::user()->tenant;
    }

    /**
     * Determine which onboarding step user should be on.
     */
    protected function determineOnboardingStep(Tenant $tenant): int
    {
        // Step 1: Platform not set
        if (!$tenant->platform) {
            return 1;
        }
        
        // Step 2: Credentials not set (for non-manual)
        if ($tenant->platform !== 'manual' && empty($tenant->platform_credentials)) {
            return 2;
        }
        
        // Step 3: No products synced (skip for manual)
        if ($tenant->platform !== 'manual' && $tenant->products()->count() === 0) {
            return 3;
        }
        
        // Widget settings are created automatically with good defaults
        // Skip directly to Step 5 (embed code)
        return 5;
    }

    /**
     * Get route name for onboarding step.
     */
    protected function getOnboardingRoute(int $step): string
    {
        return match($step) {
            1 => 'onboarding.step1',
            2 => 'onboarding.step2',
            3 => 'onboarding.step3',
            4 => 'onboarding.step4',
            5 => 'onboarding.step5',
            default => 'onboarding.index',
        };
    }

    /**
     * Main dashboard page.
     */
    public function index(): View|RedirectResponse
    {
        $tenant = $this->tenant();
        $user = Auth::user();

        // Check if onboarding completed - redirect to specific step
        if (!($tenant->settings['onboarding_completed'] ?? false)) {
            // Determine which step user should be on
            $step = $this->determineOnboardingStep($tenant);
            return redirect()->route($this->getOnboardingRoute($step));
        }

        // Stats for last 30 days
        $startDate = now()->subDays(30);

        $stats = [
            // Usage
            'messages_used' => $tenant->messages_used,
            'messages_limit' => $tenant->messages_limit,
            'usage_percentage' => $tenant->getUsagePercentage(),
            
            // Sessions
            'total_sessions' => ChatSession::where('tenant_id', $tenant->id)->count(),
            'sessions_30d' => ChatSession::where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $startDate)
                ->count(),
            
            // Messages
            'total_messages' => ChatMessage::where('tenant_id', $tenant->id)->count(),
            'messages_30d' => ChatMessage::where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $startDate)
                ->count(),
            
            // Products
            'products_count' => $tenant->products()->count(),
            
            // Plan
            'plan' => $tenant->plan,
            'plan_label' => $tenant->getPlanLabel(),
            'trial_ends_at' => $tenant->trial_ends_at,
            'is_trial' => $tenant->isOnTrial(),
            'is_trial_expired' => $tenant->isTrialExpired(),
            'days_left' => $tenant->trial_ends_at ? max(0, (int) floor(now()->diffInDays($tenant->trial_ends_at, false))) : null,
        ];

        // Daily messages for chart (last 14 days)
        $dailyMessages = ChatMessage::where('tenant_id', $tenant->id)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Fill missing dates with 0
        $chartData = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $chartData[$date] = $dailyMessages[$date] ?? 0;
        }

        return view('dashboard', [
            'tenant' => $tenant,
            'user' => $user,
            'stats' => $stats,
            'chartData' => $chartData,
            'embedCode' => $tenant->getEmbedCode(),
            'features' => $tenant->getFeaturesStatus(),
        ]);
    }
}
