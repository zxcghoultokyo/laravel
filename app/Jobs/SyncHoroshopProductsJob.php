<?php

namespace App\Jobs;

use App\Models\SyncLog;
use App\Services\Horoshop\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncHoroshopProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes timeout
    public int $tries = 2;

    public function __construct(
        protected int $limit = 200,
    ) {
        $this->onQueue('default');
    }

    public function handle(ProductService $productService): void
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
