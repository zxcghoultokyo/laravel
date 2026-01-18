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
     * Main dashboard page.
     */
    public function index(): View|RedirectResponse
    {
        $tenant = $this->tenant();
        $user = Auth::user();

        // Check if onboarding completed
        if (!($tenant->settings['onboarding_completed'] ?? false)) {
            return redirect()->route('onboarding.index');
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
            'days_left' => $tenant->trial_ends_at ? max(0, now()->diffInDays($tenant->trial_ends_at, false)) : null,
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
        ]);
    }
}
