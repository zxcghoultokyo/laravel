<?php

namespace App\Jobs;

use App\Services\Rozetka\RozetkaProductService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncRozetkaProductsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $tenantId) {}

    public function handle(RozetkaProductService $service): void
    {
        Cache::put("rozetka_sync_status_{$this->tenantId}", [
            'status' => 'running',
            'message' => 'Завантаження товарів з Розетки...',
        ], 600);

        try {
            $count = $service->syncProducts($this->tenantId);

            Cache::put("rozetka_sync_status_{$this->tenantId}", [
                'status' => 'done',
                'message' => "Синхронізовано {$count} товарів.",
            ], 600);

            Log::info("SyncRozetkaProductsJob: synced {$count} products for tenant {$this->tenantId}");
        } catch (\Throwable $e) {
            Cache::put("rozetka_sync_status_{$this->tenantId}", [
                'status' => 'error',
                'message' => 'Помилка: '.$e->getMessage(),
            ], 600);

            Log::error("SyncRozetkaProductsJob failed: {$e->getMessage()}", [
                'tenant_id' => $this->tenantId,
            ]);

            throw $e;
        }
    }
}
