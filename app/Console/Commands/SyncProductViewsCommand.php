<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncProductViewsCommand extends Command
{
    protected $signature = 'products:sync-views {--days=30 : Days to aggregate views from}';
    protected $description = 'Sync product views from chat_events to products table for ranking';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $startDate = now()->subDays($days)->startOfDay();
        
        $this->info("Syncing product views from last {$days} days...");
        
        // Get view counts from chat_events
        $viewCounts = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'product_shown')
            ->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as views')
            ->groupBy('product_id')
            ->get();
        
        $this->info("Found {$viewCounts->count()} products with views");
        
        $updated = 0;
        foreach ($viewCounts as $row) {
            $affected = DB::table('products')
                ->where('id', $row->product_id)
                ->update(['views_count' => $row->views]);
            
            if ($affected) {
                $updated++;
            }
        }
        
        $this->info("Updated {$updated} products");
        
        // Also get click counts
        $clickCounts = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'product_click')
            ->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as clicks')
            ->groupBy('product_id')
            ->get();
        
        $this->info("Found {$clickCounts->count()} products with clicks");
        
        $clicksUpdated = 0;
        foreach ($clickCounts as $row) {
            // Use added_to_cart_count as proxy for clicks (or add new column)
            $affected = DB::table('products')
                ->where('id', $row->product_id)
                ->increment('added_to_cart_count', $row->clicks);
            
            if ($affected) {
                $clicksUpdated++;
            }
        }
        
        $this->info("Updated {$clicksUpdated} products with clicks");
        
        return Command::SUCCESS;
    }
}
