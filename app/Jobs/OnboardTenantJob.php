<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Catalog\CategoryIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Automatic tenant onboarding job
 * 
 * Runs all necessary setup tasks when a new tenant is created:
 * 1. Sync products from Horoshop
 * 2. Rebuild categories
 * 3. Start AI enrichment
 * 4. Reindex in Meilisearch
 * 
 * @see docs/TENANT_ONBOARDING.md
 */
class OnboardTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600; // 1 hour max

    public function __construct(
        public int $tenantId
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);
        
        if (!$tenant) {
            Log::warning('OnboardTenantJob: Tenant not found', ['tenant_id' => $this->tenantId]);
            return;
        }

        Log::info('OnboardTenantJob: Starting onboarding', [
            'tenant_id' => $this->tenantId,
            'tenant_name' => $tenant->name,
        ]);

        try {
            // 1. Sync products from Horoshop (if configured)
            $this->syncProducts($tenant);
            
            // 2. Rebuild categories for this tenant
            $this->rebuildCategories();
            
            // 3. Start AI enrichment (async)
            $this->startAiEnrichment();
            
            // 4. Reindex in Meilisearch (async, after enrichment)
            $this->scheduleReindex();
            
            // Mark onboarding as completed
            $tenant->update(['onboarding_completed_at' => now()]);
            
            Log::info('OnboardTenantJob: Onboarding completed', [
                'tenant_id' => $this->tenantId,
            ]);
            
        } catch (\Throwable $e) {
            Log::error('OnboardTenantJob: Failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Sync products from Horoshop
     */
    protected function syncProducts(Tenant $tenant): void
    {
        $widgetSettings = $tenant->widgetSettings;
        
        // Check if Horoshop is configured
        if (!$widgetSettings || empty($widgetSettings->platform_credentials)) {
            Log::info('OnboardTenantJob: Horoshop not configured, skipping sync', [
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        Log::info('OnboardTenantJob: Syncing products from Horoshop', [
            'tenant_id' => $this->tenantId,
        ]);

        // Dispatch sync job synchronously to ensure categories have products
        SyncHoroshopProductsJob::dispatchSync($this->tenantId);
    }

    /**
     * Rebuild categories for this tenant
     */
    protected function rebuildCategories(): void
    {
        Log::info('OnboardTenantJob: Rebuilding categories', [
            'tenant_id' => $this->tenantId,
        ]);

        $service = app(CategoryIndexService::class);
        $service->rebuildForTenant($this->tenantId);
    }

    /**
     * Start AI enrichment for products
     */
    protected function startAiEnrichment(): void
    {
        $productsCount = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->where('in_stock', true)
            ->count();

        if ($productsCount === 0) {
            Log::info('OnboardTenantJob: No products to enrich', [
                'tenant_id' => $this->tenantId,
            ]);
            return;
        }

        Log::info('OnboardTenantJob: Starting AI enrichment', [
            'tenant_id' => $this->tenantId,
            'products_count' => $productsCount,
        ]);

        // Dispatch AI enrichment job (async)
        AnalyzeProductsWithAiJob::dispatch(
            batchSize: min(100, $productsCount),
            offset: 0,
            forceReanalyze: false,
            tenantId: $this->tenantId
        )->onQueue('default');
    }

    /**
     * Schedule Meilisearch reindex (after enrichment completes)
     */
    protected function scheduleReindex(): void
    {
        Log::info('OnboardTenantJob: Scheduling Meilisearch reindex', [
            'tenant_id' => $this->tenantId,
        ]);

        // Delay reindex by 10 minutes to allow AI enrichment to progress
        IndexProductsToMeiliJob::dispatch($this->tenantId)
            ->onQueue('meili')
            ->delay(now()->addMinutes(10));
    }
}
