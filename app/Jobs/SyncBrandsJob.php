<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\Product;
use App\Models\SyncLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncBrandsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $syncLog = SyncLog::start(SyncLog::TYPE_BRANDS, 'Sync brands from products');
        
        Log::info('SyncBrandsJob: starting');
        
        try {
            // Get unique brands from products with counts
            $brandStats = Product::whereNotNull('brand')
                ->where('brand', '!=', '')
                ->select('brand')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('brand')
                ->orderByDesc('count')
                ->get();
            
            if ($brandStats->isEmpty()) {
                Log::warning('SyncBrandsJob: no brands found in products table');
                $syncLog->complete(['message' => 'No brands found']);
                return;
            }
            
            $created = 0;
            $updated = 0;
            
            foreach ($brandStats as $stat) {
                $brandName = trim($stat->brand);
                $productCount = $stat->count;
                
                if (empty($brandName)) {
                    continue;
                }
                
                // Normalize brand name variants
                $normalizedName = $this->normalizeBrandName($brandName);
                
                $brand = Brand::firstOrNew(['name' => $normalizedName]);
                
                if ($brand->exists) {
                    // Update product count (accumulate if multiple variants)
                    $brand->product_count += $productCount;
                    $brand->save();
                    $updated++;
                } else {
                    // Create new brand
                    $brand->product_count = $productCount;
                    $brand->is_active = true;
                    $brand->save();
                    $created++;
                }
            }
            
            // Clear cache
            Cache::forget('brands:all');
            
            Log::info('SyncBrandsJob: completed', [
                'created' => $created,
                'updated' => $updated,
                'total' => Brand::count(),
            ]);
            
            $syncLog->complete([
                'created' => $created,
                'updated' => $updated,
                'total' => Brand::count(),
            ]);
            
        } catch (\Exception $e) {
            Log::error('SyncBrandsJob: failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $syncLog->fail($e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Normalize brand name variants to canonical form
     */
    private function normalizeBrandName(string $name): string
    {
        $normalized = trim($name);
        
        // Map of variants to canonical names
        $brandMap = [
            'ATAKA' => 'АТАКА',
            'ataka' => 'АТАКА',
            'Ataka' => 'АТАКА',
            'А.Т.А.К.А' => 'АТАКА',
            'А.Т.А.К.А.' => 'АТАКА',
            'Hoffmann Equipment' => 'HOFFMANN',
            'hoffman' => 'HOFFMANN',
            'Hoffman' => 'HOFFMANN',
            'USA ARMY' => 'USA Army',
            'usa army' => 'USA Army',
            'KOMBAT' => 'KOMBAT UK',
            'kombat' => 'KOMBAT UK',
            'Salomon Forces' => 'Salomon',
            'salomon' => 'Salomon',
        ];
        
        return $brandMap[$normalized] ?? $normalized;
    }
}
