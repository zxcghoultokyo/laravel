<?php

namespace App\Console\Commands;

use App\Jobs\IndexProductsToMeiliJob;
use App\Models\Product;
use Illuminate\Console\Command;

class MeiliReindexProducts extends Command
{
    protected $signature = 'meili:reindex-products {--chunk=500 : Products per job}';
    protected $description = 'Dispatch products reindex jobs to Meilisearch (queue: meili)';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $total = Product::query()->count();

        if ($total === 0) {
            $this->info('No products found. Nothing to index.');
            return self::SUCCESS;
        }

        // Job internally chunks all products
        IndexProductsToMeiliJob::dispatch($chunk)
            ->onQueue('meili');

        $this->info("Dispatched 1 job to queue=meili for {$total} product(s). Chunk={$chunk}.");

        return self::SUCCESS;
    }
}
