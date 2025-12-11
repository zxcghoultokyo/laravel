<?php

namespace App\Jobs;

use App\Services\Horoshop\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncHoroshopProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $limit = 200,
    ) {
    }

    public function handle(ProductService $productService): void
    {
        $productService->syncFromHoroshop($this->limit);
    }
}
