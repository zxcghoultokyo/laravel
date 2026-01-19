<?php

namespace App\Console\Commands;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateProductOrdersCountCommand extends Command
{
    protected $signature = 'products:update-orders-count 
        {--status=3,6 : Order status codes to count (comma-separated)}
        {--include-gifts : Include gift and set_item types}
        {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Update orders_count in products table based on order_items';

    public function handle(): int
    {
        $statuses = array_map('intval', explode(',', $this->option('status')));
        $includeGifts = $this->option('include-gifts');
        $dryRun = $this->option('dry-run');

        $this->info("=================================================");
        $this->info("[START] Updating orders_count in products");
        $this->info("  Statuses: " . implode(', ', $statuses));
        $this->info("  Include gifts: " . ($includeGifts ? 'yes' : 'no'));
        $this->info("  Dry run: " . ($dryRun ? 'yes' : 'no'));
        $this->info("=================================================");

        // Create SyncLog entry (only if not dry-run)
        $syncLog = null;
        if (!$dryRun) {
            $syncLog = SyncLog::create([
                'sync_type' => SyncLog::TYPE_STATS,
                'status' => SyncLog::STATUS_RUNNING,
                'started_at' => now(),
                'meta' => [
                    'statuses' => $statuses,
                    'include_gifts' => $includeGifts,
                ],
            ]);
        }

        // Build query for counting orders per article
        $query = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status_code', $statuses)
            ->groupBy('order_items.article')
            ->select('order_items.article', DB::raw('SUM(order_items.quantity) as total_ordered'));

        if (!$includeGifts) {
            $query->whereNotIn('order_items.type', ['gift', 'set_item']);
        }

        $counts = $query->get()->pluck('total_ordered', 'article');

        $this->info("Found orders for " . $counts->count() . " unique articles");

        if ($counts->isEmpty()) {
            $this->warn("No order items found. Make sure to run `php artisan orders:sync` first.");
            return self::SUCCESS;
        }

        // Show top 10 most ordered
        $top10 = $counts->sortDesc()->take(10);
        $this->info("\nTop 10 most ordered articles:");
        foreach ($top10 as $article => $count) {
            $product = Product::where('article', $article)->first();
            $title = $product ? mb_substr($product->title, 0, 40) : '(not found)';
            $this->info("  {$article}: {$count} — {$title}");
        }

        if ($dryRun) {
            $this->info("\n[DRY RUN] No changes made.");
            return self::SUCCESS;
        }

        // Reset all to 0 first
        $resetCount = Product::where('orders_count', '>', 0)->count();
        Product::query()->update(['orders_count' => 0]);
        $this->info("\nReset {$resetCount} products to orders_count=0");

        // Update products with counts
        $updated = 0;
        $notFound = 0;
        
        $bar = $this->output->createProgressBar($counts->count());
        $bar->start();

        foreach ($counts as $article => $count) {
            // Cast article to string for safe comparison
            $articleStr = (string) $article;
            
            $affected = Product::where('article', $articleStr)->update(['orders_count' => $count]);
            
            if ($affected === 0) {
                // Try parent_article
                $affected = Product::where('parent_article', $articleStr)->update(['orders_count' => $count]);
            }
            
            if ($affected > 0) {
                $updated += $affected;
            } else {
                $notFound++;
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("=================================================");
        $this->info("[DONE] Updated {$updated} products");
        if ($notFound > 0) {
            $this->warn("  {$notFound} articles not found in products table");
        }
        
        // Stats
        $withOrders = Product::where('orders_count', '>', 0)->count();
        $this->info("  Products with orders_count > 0: {$withOrders}");
        $this->info("=================================================");

        // Update SyncLog
        if ($syncLog) {
            $syncLog->update([
                'status' => SyncLog::STATUS_COMPLETED,
                'completed_at' => now(),
                'items_synced' => $updated,
                'meta' => array_merge($syncLog->meta ?? [], [
                    'updated' => $updated,
                    'not_found' => $notFound,
                    'with_orders' => $withOrders,
                ]),
            ]);
        }

        return self::SUCCESS;
    }
}
