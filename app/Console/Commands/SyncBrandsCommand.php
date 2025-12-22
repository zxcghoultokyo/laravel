<?php

namespace App\Console\Commands;

use App\Jobs\SyncBrandsJob;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncBrandsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brands:sync {--async : Run as background job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync brands from products table to brands table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check if should run async
        if ($this->option('async')) {
            SyncBrandsJob::dispatch();
            $this->info('✓ Brand sync job dispatched to queue');
            return Command::SUCCESS;
        }
        
        $this->info('Starting brands sync...');
        
        // Get unique brands from products with counts
        $brandStats = Product::whereNotNull('brand')
            ->where('brand', '!=', '')
            ->select('brand')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('brand')
            ->orderByDesc('count')
            ->get();
        
        if ($brandStats->isEmpty()) {
            $this->warn('No brands found in products table.');
            return Command::SUCCESS;
        }
        
        $this->info("Found {$brandStats->count()} unique brands in products.");
        
        $created = 0;
        $updated = 0;
        
        foreach ($brandStats as $stat) {
            $brandName = trim($stat->brand);
            $productCount = $stat->count;
            
            if (empty($brandName)) {
                continue;
            }
            
            $brand = Brand::firstOrNew(['name' => $brandName]);
            
            if ($brand->exists) {
                // Update product count
                $brand->product_count = $productCount;
                $brand->save();
                $updated++;
            } else {
                // Create new brand
                $brand->product_count = $productCount;
                $brand->is_active = true;
                $brand->save();
                $created++;
            }
            
            $this->line("  {$brandName}: {$productCount} товарів");
        }
        
        $this->newLine();
        $this->info("✓ Created: {$created} brands");
        $this->info("✓ Updated: {$updated} brands");
        $this->info("✓ Total: " . Brand::count() . " brands in database");
        
        // Clear cache
        \Illuminate\Support\Facades\Cache::forget('brands:all');
        $this->info("✓ Cache cleared");
        
        return Command::SUCCESS;
    }
}
