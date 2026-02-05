<?php

namespace App\Livewire;

use App\Jobs\OnboardTenantJob;
use App\Models\TenantOnboardingProgress;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Onboarding progress bar component
 * 
 * Shows real-time progress of tenant onboarding:
 * - Horoshop sync
 * - Categories rebuild
 * - Brands sync
 * - AI enrichment
 * - Meilisearch indexing
 * 
 * Use: <livewire:onboarding-progress />
 */
class OnboardingProgress extends Component
{
    public ?array $progress = null;
    public bool $showStartButton = true;
    public bool $isCompact = false; // For dashboard view
    
    protected $listeners = ['refreshProgress' => '$refresh'];

    public function mount(bool $compact = false): void
    {
        $this->isCompact = $compact;
        $this->loadProgress();
    }

    public function loadProgress(): void
    {
        $tenant = Auth::user()?->tenant;
        if (!$tenant) {
            return;
        }

        // CRITICAL: Use fresh() to bypass any caching and get latest data from DB
        $progressModel = TenantOnboardingProgress::where('tenant_id', $tenant->id)->first();
        
        if ($progressModel) {
            // Force refresh from database to get latest values
            $progressModel->refresh();
            $this->progress = $progressModel->toProgressArray();
            $this->showStartButton = false;
            
            // Debug log to track polling issues
            \Log::debug('OnboardingProgress::loadProgress', [
                'tenant_id' => $tenant->id,
                'status' => $this->progress['status'] ?? 'unknown',
                'overall_percent' => $this->progress['overall_percent'] ?? 0,
                'current_step' => $this->progress['current_step'] ?? null,
            ]);
        } else {
            // Check if tenant has products OR onboarding already started
            $hasProducts = $tenant->products()->count() > 0;
            $hasCredentials = !empty($tenant->platform_credentials);
            
            // Only show start button if credentials set but no progress yet
            // (normally OnboardTenantJob is dispatched right after saveStep2)
            $this->showStartButton = $hasCredentials && !$hasProducts;
            $this->progress = null;
            
            // If credentials set but no progress record, job might be queued - show waiting state
            if ($hasCredentials && !$hasProducts) {
                $this->progress = [
                    'status' => 'pending',
                    'overall_percent' => 0,
                    'current_step_detail' => 'Очікування черги...',
                    'steps' => [],
                    'error_message' => null,
                ];
                $this->showStartButton = false; // Job is already dispatched in saveStep2
            }
        }
    }

    /**
     * Start onboarding process
     */
    public function startOnboarding(): void
    {
        $tenant = Auth::user()?->tenant;
        if (!$tenant) {
            return;
        }

        // Initialize progress
        $progress = TenantOnboardingProgress::forTenant($tenant->id);
        
        // Dispatch job
        OnboardTenantJob::dispatch($tenant->id)->onQueue('default');
        
        $this->showStartButton = false;
        $this->loadProgress();
        
        session()->flash('message', 'Онбординг запущено! Процес може тривати кілька хвилин.');
    }

    /**
     * Polling to refresh progress every 3 seconds while in progress, pending, or AI still running
     */
    public function getPollingInterval(): ?int
    {
        if (!$this->progress) {
            return null;
        }
        
        // Poll if status is in_progress or pending
        if (in_array($this->progress['status'], ['in_progress', 'pending'])) {
            return 3000; // 3 seconds
        }
        
        // Also poll if onboarding completed but AI enrichment still in progress
        $aiStep = $this->progress['steps']['ai_enrichment'] ?? null;
        if ($aiStep && $aiStep['status'] === 'in_progress') {
            return 5000; // 5 seconds for background AI
        }
        
        return null;
    }

    /**
     * Check if AI enrichment is still running in background
     */
    public function isAiInProgress(): bool
    {
        if (!$this->progress) {
            return false;
        }
        
        $aiStep = $this->progress['steps']['ai_enrichment'] ?? null;
        return $aiStep && $aiStep['status'] === 'in_progress';
    }

    public function render()
    {
        // Refresh progress on each render
        $this->loadProgress();
        
        return view('livewire.onboarding-progress', [
            'pollingInterval' => $this->getPollingInterval(),
            'aiInProgress' => $this->isAiInProgress(),
        ]);
    }
}
