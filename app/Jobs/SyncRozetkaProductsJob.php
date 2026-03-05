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
        $tenantId = $this->tenantId;

        Cache::put("rozetka_sync_status_{$tenantId}", [
            'status' => 'running',
            'message' => 'Завантаження товарів з Розетки...',
            'synced' => 0,
            'page' => 0,
            'total_pages' => 0,
            'percent' => 0,
        ], 600);

        try {
            $count = $service->syncProducts($tenantId, function (int $synced, int $page, int $totalPages) use ($tenantId) {
                $percent = $totalPages > 0 ? (int) round($page / $totalPages * 100) : 0;

                Cache::put("rozetka_sync_status_{$tenantId}", [
                    'status' => 'running',
                    'message' => "Завантажено {$synced} товарів (сторінка {$page}/{$totalPages}) — {$percent}%",
                    'synced' => $synced,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'percent' => $percent,
                ], 600);
            });

            Cache::put("rozetka_sync_status_{$tenantId}", [
                'status' => 'done',
                'message' => "✅ Синхронізовано {$count} товарів (унікальних за артикулом).",
                'synced' => $count,
                'percent' => 100,
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
