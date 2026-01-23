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

        $progressModel = TenantOnboardingProgress::where('tenant_id', $tenant->id)->first();
        
        if ($progressModel) {
            $this->progress = $progressModel->toProgressArray();
            $this->showStartButton = false;
        } else {
            // Check if tenant has products already (manual import or previous onboarding)
            $hasProducts = $tenant->products()->count() > 0;
            $this->showStartButton = !$hasProducts;
            $this->progress = null;
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
     * Polling to refresh progress every 3 seconds while in progress
     */
    public function getPollingInterval(): ?int
    {
        if ($this->progress && $this->progress['status'] === 'in_progress') {
            return 3000; // 3 seconds
        }
        return null;
    }

    public function render()
    {
        // Refresh progress on each render
        $this->loadProgress();
        
        return view('livewire.onboarding-progress', [
            'pollingInterval' => $this->getPollingInterval(),
        ]);
    }
}
