<?php

namespace App\Console\Commands;

use App\Jobs\IndexProductsToMeiliJob;
use Illuminate\Console\Command;

class MeiliReindexProducts extends Command
{
    protected $signature = 'meili:reindex-products {--chunk=500}';
    protected $description = 'Dispatch products reindex to Meilisearch';

    public function handle(): int
    {
        IndexProductsToMeiliJob::dispatch((int)$this->option('chunk'));
        $this->info('IndexProductsToMeiliJob dispatched.');
        return self::SUCCESS;
    }
}
