<?php

namespace App\Jobs;

use App\Models\SyncLog;
use App\Models\Tenant;
use App\Services\Horoshop\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncHoroshopProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes timeout
    public int $tries = 2;

    public function __construct(
        protected ?int $tenantId = null,
        protected int $limit = 200,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProductService $productService): void
    {
        // If tenant specified, sync for that tenant only
        if ($this->tenantId) {
            $this->syncForTenant($productService);
            return;
        }
        
        // Global sync: iterate over all tenants with Horoshop credentials
        $this->syncAllTenants($productService);
    }

    /**
     * Sync products for all tenants with Horoshop platform.
     */
    protected function syncAllTenants(ProductService $productService): void
    {
        $tenants = Tenant::whereNotNull('platform_credentials')
            ->where('platform', 'horoshop')
            ->get();
        
        if ($tenants->isEmpty()) {
            Log::warning('SyncHoroshopProductsJob: No tenants with Horoshop credentials found');
            return;
        }
        
        $syncLog = SyncLog::start(SyncLog::TYPE_HOROSHOP_PRODUCTS, "All tenants sync ({$tenants->count()} tenants)");
        $results = [];
        $errors = [];
        
        foreach ($tenants as $tenant) {
            try {
                // Check if tenant has valid credentials
                $creds = $tenant->platform_credentials;
                if (empty($creds['domain']) || empty($creds['login'])) {
                    Log::warning('SyncHoroshopProductsJob: Tenant has incomplete credentials', ['tenant_id' => $tenant->id]);
                    continue;
                }
                
                $result = $productService->syncFromHoroshopForTenant($tenant, $this->limit);
                $results[$tenant->id] = $result;
                
                // Update tenant last_sync_at
                $tenant->update(['last_sync_at' => now()]);
                
                Log::info('SyncHoroshopProductsJob completed for tenant', [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                $errors[$tenant->id] = $e->getMessage();
                Log::error('SyncHoroshopProductsJob failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $syncLog->complete([
            'tenants_processed' => count($results),
            'tenants_failed' => count($errors),
            'results' => $results,
            'errors' => $errors,
        ]);
    }

    /**
     * Sync products for a specific tenant.
     */
    protected function syncForTenant(ProductService $productService): void
    {
        $tenant = Tenant::find($this->tenantId);
        
        if (!$tenant) {
            Log::error('SyncHoroshopProductsJob: Tenant not found', ['tenant_id' => $this->tenantId]);
            return;
        }
        
        $cacheKey = "sync_running_{$this->tenantId}";
        
        // Check if cancelled (only if flag was set - for manual runs with cancel button)
        // For scheduled runs, flag might not be set, so we proceed anyway
        $wasManuallyStarted = Cache::has($cacheKey);
        if ($wasManuallyStarted && !Cache::get($cacheKey)) {
            Log::info('SyncHoroshopProductsJob: Sync was cancelled', ['tenant_id' => $this->tenantId]);
            return;
        }
        
        // Set flag for scheduled runs (so cancel button works if someone clicks it)
        if (!$wasManuallyStarted) {
            Cache::put($cacheKey, true, now()->addMinutes(30));
        }
        
        $syncLog = SyncLog::start(SyncLog::TYPE_HOROSHOP_PRODUCTS, "Tenant sync: {$tenant->name}");
        
        try {
            $result = $productService->syncFromHoroshopForTenant($tenant, $this->limit);
            
            // Update tenant last_sync_at
            $tenant->update(['last_sync_at' => now()]);
            
            $syncLog->complete([
                'tenant_id' => $this->tenantId,
                'tenant_name' => $tenant->name,
                'limit' => $this->limit,
                'result' => $result,
            ]);
            
            Log::info('SyncHoroshopProductsJob completed for tenant', [
                'tenant_id' => $this->tenantId,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            $syncLog->fail($e->getMessage());
            Log::error('SyncHoroshopProductsJob failed for tenant', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            // Clear running flag
            Cache::forget($cacheKey);
        }
    }}