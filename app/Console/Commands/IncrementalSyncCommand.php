<?php

namespace App\Console\Commands;

use App\Jobs\IncrementalProductSyncJob;
use Illuminate\Console\Command;

class IncrementalSyncCommand extends Command
{
    protected $signature = 'products:incremental-sync 
        {--no-enrichment : Skip AI enrichment for new products}
        {--no-meili : Skip Meilisearch reindex}
        {--sync : Run synchronously instead of dispatching job}';

    protected $description = 'Інкрементальна синхронізація товарів з Horoshop (тільки змінені)';

    public function handle(): int
    {
        $triggerEnrichment = !$this->option('no-enrichment');
        $triggerMeili = !$this->option('no-meili');

        $this->info('🔄 Starting incremental product sync...');
        $this->info("  - AI enrichment: " . ($triggerEnrichment ? '✅' : '❌'));
        $this->info("  - Meili reindex: " . ($triggerMeili ? '✅' : '❌'));

        if ($this->option('sync')) {
            $this->info('Running synchronously...');
            
            $job = new IncrementalProductSyncJob($triggerEnrichment, $triggerMeili);
            $job->handle(app(\App\Services\Horoshop\ProductService::class));
            
            // Показуємо статистику
            $stats = \Illuminate\Support\Facades\Cache::get('incremental_sync_stats');
            if ($stats) {
                $this->newLine();
                $this->info('📊 Results:');
                $this->table(
                    ['Metric', 'Count'],
                    collect($stats['stats'])->map(fn($v, $k) => [$k, $v])->values()->toArray()
                );
                $this->info("⏱️  Elapsed: {$stats['elapsed_seconds']}s");
            }
        } else {
            IncrementalProductSyncJob::dispatch($triggerEnrichment, $triggerMeili);
            $this->info('✅ Job dispatched to queue');
        }

        return Command::SUCCESS;
    }
}
