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
        
        // Global sync (legacy mode)
        $this->syncGlobal($productService);
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
        
        // Check if cancelled
        if (!Cache::get($cacheKey)) {
            Log::info('SyncHoroshopProductsJob: Sync was cancelled', ['tenant_id' => $this->tenantId]);
            return;
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
    }

    /**
     * Global sync (legacy mode for main tenant).
     */
    protected function syncGlobal(ProductService $productService): void
    {
        $syncLog = SyncLog::start(SyncLog::TYPE_HOROSHOP_PRODUCTS, "Full sync (limit: {$this->limit})");
        
        try {
            $result = $productService->syncFromHoroshop($this->limit);
            
            $syncLog->complete([
                'limit' => $this->limit,
                'result' => $result,
            ]);
            
            Log::info('SyncHoroshopProductsJob completed', ['limit' => $this->limit, 'result' => $result]);
        } catch (\Throwable $e) {
            $syncLog->fail($e->getMessage());
            Log::error('SyncHoroshopProductsJob failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
