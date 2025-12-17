<?php

namespace App\Console\Commands;

use App\Jobs\IndexProductsToMeiliJob;
use App\Models\Product;
use Illuminate\Console\Command;

class MeiliReindexProducts extends Command
{
    protected $signature = 'meili:reindex-products {--chunk=500 : Number of products per job (by ID ranges)}';
    protected $description = 'Dispatch products reindex jobs to Meilisearch (queue: meili)';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $total = Product::query()->count();

        if ($total === 0) {
            $this->info('No products found. Nothing to index.');
            return self::SUCCESS;
        }

        $jobs = 0;

        // Dispatch one job per ID range (roughly $chunk products per job)
        Product::query()
            ->select('id')
            ->orderBy('id')
            ->chunk($chunk, function ($rows) use (&$jobs, $chunk) {
                $fromId = (int) $rows->first()->id;
                $toId   = (int) $rows->last()->id;

                IndexProductsToMeiliJob::dispatch($fromId, $toId, $chunk)->onQueue('meili');

                $jobs++;
            });

        $this->info("Dispatched {$jobs} Meili indexing job(s) for {$total} product(s) to queue=meili (chunk={$chunk}).");

        return self::SUCCESS;
    }
}
