<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job to check and notify tenants about trial ending.
 * Run daily via scheduler.
 */
class CheckTrialEndingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(NotificationService $notifications): void
    {
        // Notify 3 days before trial ends
        $this->notifyTrialEnding($notifications, 3);
        
        // Notify 1 day before trial ends
        $this->notifyTrialEnding($notifications, 1);
    }

    protected function notifyTrialEnding(NotificationService $notifications, int $daysLeft): void
    {
        $targetDate = now()->addDays($daysLeft)->startOfDay();
        
        $tenants = Tenant::where('plan', Tenant::PLAN_TRIAL)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->whereDate('trial_ends_at', $targetDate)
            ->get();

        foreach ($tenants as $tenant) {
            $notifications->notifyTrialEnding($tenant, $daysLeft);
        }
    }
}
