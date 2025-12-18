<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Services\Ai\ProductIndexBuilder;

class BuildProductAiIndex extends Command
{
    protected $signature = 'products:build-ai-index {--limit=0 : Max products to process} {--only-missing : Only products without AI index}';
    protected $aliases = ['build:product-ai-index'];

    protected $description = 'Build or rebuild AI index for products';

    public function handle(ProductIndexBuilder $builder): int
    {
        $query = Product::query()
            ->where('display_in_showcase', true);

        if ($this->option('only-missing')) {
            $query->whereDoesntHave('aiIndex');
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('No products to index.');
            return self::SUCCESS;
        }

        $this->info("Building AI index for {$total} products...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($products) use ($builder, $bar) {
            foreach ($products as $product) {
                $builder->buildForProduct($product);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
