<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Horoshop\HoroshopCatalogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncHoroshopCatalogJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200; // 2 hours max

    public int $tries = 1;

    public function __construct(
        public int $tenantId,
    ) {
        $this->onQueue('default');
    }

    public function handle(HoroshopCatalogService $service): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (! $tenant) {
            Log::error('SyncHoroshopCatalogJob: tenant not found', ['tenant_id' => $this->tenantId]);

            return;
        }

        $cacheKey = "horoshop_catalog_sync_status_{$this->tenantId}";
        Cache::put($cacheKey, ['status' => 'running', 'started_at' => now()->toIso8601String()], now()->addHours(3));

        try {
            $result = $service->syncFullCatalog($tenant);

            Cache::put($cacheKey, [
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now()->toIso8601String(),
            ], now()->addHours(3));

            Log::info('SyncHoroshopCatalogJob completed', [
                'tenant_id' => $this->tenantId,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ], now()->addHours(3));

            Log::error('SyncHoroshopCatalogJob failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
