<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeProductsWithAiJob;
use App\Models\Product;
use App\Models\ProductAiIndex;
use Illuminate\Console\Command;

class AnalyzeProductsCommand extends Command
{
    protected $signature = 'products:analyze 
        {--batch=10 : Products per batch}
        {--force : Re-analyze already processed products}
        {--sync : Run synchronously instead of queuing}';

    protected $description = 'Analyze products with AI to generate search keywords, slang, synonyms';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $force = $this->option('force');
        $sync = $this->option('sync');

        // Count products to analyze
        $query = Product::query()
            ->where('in_stock', true)
            ->whereNotNull('title');

        if (!$force) {
            $query->whereNotIn('id', function ($q) {
                $q->select('product_id')
                    ->from('product_ai_index')
                    ->whereNotNull('keywords');
            });
        }

        $total = $query->count();
        $alreadyAnalyzed = ProductAiIndex::whereNotNull('keywords')->count();

        $this->info("Products to analyze: {$total}");
        $this->info("Already analyzed: {$alreadyAnalyzed}");

        if ($total === 0) {
            $this->info('Nothing to analyze!');
            return 0;
        }

        if (!$this->confirm("Start AI analysis of {$total} products?")) {
            return 0;
        }

        if ($sync) {
            $this->info('Running synchronously...');
            $bar = $this->output->createProgressBar($total);
            
            $offset = 0;
            while (true) {
                $job = new AnalyzeProductsWithAiJob($batchSize, $offset, $force);
                $job->handle();
                
                $offset += $batchSize;
                $bar->advance($batchSize);
                
                if ($offset >= $total) {
                    break;
                }
                
                // Rate limiting
                sleep(1);
            }
            
            $bar->finish();
            $this->newLine();
        } else {
            $this->info('Dispatching to queue...');
            AnalyzeProductsWithAiJob::dispatch($batchSize, 0, $force);
            $this->info('Job dispatched! Run `php artisan queue:work` to process.');
        }

        $this->info('Done!');
        return 0;
    }
}
