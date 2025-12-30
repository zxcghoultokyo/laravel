<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Horoshop\HoroshopClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncOrdersCommand extends Command
{
    protected $signature = 'orders:sync 
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD)}
        {--days=30 : Sync last N days if no dates specified}
        {--status=* : Filter by status codes (1=new, 2=processing, 3=delivered, 4=not delivered, 6=delivering)}
        {--limit=100 : Orders per API request}
        {--batch=50 : Orders per batch for output}
        {--timeout=600 : Max runtime in seconds}
        {--resume : Continue from last saved offset}
        {--reset : Reset saved position}
        {--update-counts : Update orders_count in products table after sync}
        {--stats : Show statistics only}';

    protected $description = 'Sync orders from Horoshop API and optionally update product orders_count';

    protected const CACHE_KEY = 'sync_orders:offset';

    public function handle(HoroshopClient $client): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $startTime = microtime(true);
        $timeout = (int) $this->option('timeout');
        $batchSize = max(1, (int) $this->option('batch'));
        $limit = (int) $this->option('limit');

        // Reset position if requested
        if ($this->option('reset')) {
            Cache::forget(self::CACHE_KEY);
            $this->info('[RESET] Cleared saved position.');
        }

        // Date range
        $from = $this->option('from');
        $to = $this->option('to');
        
        if (!$from) {
            $days = (int) $this->option('days');
            $from = now()->subDays($days)->format('Y-m-d');
        }
        if (!$to) {
            $to = now()->format('Y-m-d');
        }

        // Status filter
        $statuses = $this->option('status');
        if (empty($statuses)) {
            $statuses = null; // all statuses
        }

        // Resume from last position
        $offset = 0;
        if ($this->option('resume')) {
            $offset = (int) Cache::get(self::CACHE_KEY, 0);
            if ($offset > 0) {
                $this->info("[RESUME] Continuing from offset {$offset}");
            }
        }

        $this->info("=================================================");
        $this->info("[START] Syncing orders from {$from} to {$to}");
        $this->info("  Limit: {$limit} | Timeout: {$timeout}s");
        if ($statuses) {
            $this->info("  Statuses: " . implode(', ', $statuses));
        }
        $this->info("=================================================");

        $totalSynced = 0;
        $totalItems = 0;
        $errors = 0;
        $batchNum = 0;
        $hasMore = true;

        while ($hasMore) {
            // Check timeout
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $timeout) {
                $this->newLine();
                $this->warn("=================================================");
                $this->warn("[TIMEOUT] Reached {$timeout}s limit after {$totalSynced} orders.");
                $this->warn("[RESUME] Run with --resume to continue from offset {$offset}");
                $this->warn("=================================================");
                Cache::put(self::CACHE_KEY, $offset, now()->addDays(7));
                
                if ($this->option('update-counts')) {
                    $this->updateOrdersCounts();
                }
                return self::SUCCESS;
            }

            try {
                $payload = [
                    'from' => $from,
                    'to' => $to,
                    'offset' => $offset,
                    'limit' => $limit,
                    'additionalData' => false, // не потребуємо delivery details
                ];

                if ($statuses) {
                    $payload['status'] = array_map('intval', $statuses);
                }

                $response = $client->request('orders/get', $payload);
                $orders = $response['orders'] ?? [];

                if (empty($orders)) {
                    $hasMore = false;
                    break;
                }

                foreach ($orders as $raw) {
                    try {
                        $this->syncOrder($raw);
                        $totalSynced++;
                        $totalItems += count($raw['products'] ?? []);
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->error("[ERROR] Order #{$raw['order_id']}: " . $e->getMessage());
                    }
                }

                $offset += count($orders);

                // Heartbeat output
                if ($totalSynced % $batchSize === 0 || count($orders) < $limit) {
                    $batchNum++;
                    $elapsed = round(microtime(true) - $startTime, 1);
                    $this->info(sprintf(
                        "[BATCH %d] %d orders synced | %d items | %.1fs elapsed | offset: %d",
                        $batchNum,
                        $totalSynced,
                        $totalItems,
                        $elapsed,
                        $offset
                    ));
                    Cache::put(self::CACHE_KEY, $offset, now()->addDays(7));
                }

                // If we got less than limit, we've reached the end
                if (count($orders) < $limit) {
                    $hasMore = false;
                }

            } catch (\Throwable $e) {
                $errors++;
                $this->error("[API ERROR] " . $e->getMessage());
                
                if ($errors > 10) {
                    $this->error("[ABORT] Too many API errors.");
                    break;
                }
                
                // Wait and retry
                sleep(2);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->newLine();
        $this->info("=================================================");
        $this->info("[DONE] Synced {$totalSynced} orders with {$totalItems} items in {$elapsed}s");
        if ($errors > 0) {
            $this->warn("[WARN] {$errors} errors encountered");
        }
        $this->info("=================================================");

        // Update orders_count in products
        if ($this->option('update-counts')) {
            $this->updateOrdersCounts();
        }

        return self::SUCCESS;
    }

    protected function syncOrder(array $raw): Order
    {
        $orderedAt = isset($raw['stat_created']) 
            ? \Carbon\Carbon::parse($raw['stat_created']) 
            : null;

        $order = Order::updateOrCreate(
            ['order_id' => $raw['order_id']],
            [
                'status_code'           => $raw['stat_status'] ?? 1,
                'status_label'          => $this->getStatusLabel($raw['stat_status'] ?? 1),
                'currency'              => $raw['currency'] ?? 'UAH',
                'total_default'         => $raw['total_default'] ?? 0,
                'total_sum'             => $raw['total_sum'] ?? 0,
                'total_quantity'        => $raw['total_quantity'] ?? 0,
                'discount_value'        => $raw['discount_value'] ?? 0,
                'coupon_code'           => $raw['coupon_code'] ?? null,
                'coupon_discount_value' => $raw['coupon_discount_value'] ?? 0,
                'customer_name'         => $raw['delivery_name'] ?? null,
                'customer_email'        => $raw['delivery_email'] ?? null,
                'customer_phone'        => $raw['delivery_phone'] ?? null,
                'customer_city'         => $raw['delivery_city'] ?? ($raw['delivery_city_stable'] ?? null),
                'customer_address'      => $raw['delivery_address'] ?? null,
                'delivery_type_id'      => $raw['delivery_type']['id'] ?? null,
                'delivery_type_title'   => $raw['delivery_type']['title'] ?? null,
                'delivery_price'        => $raw['delivery_price'] ?? 0,
                'delivery_comment'      => $raw['comment'] ?? null,
                'payment_type_id'       => $raw['payment_type']['id'] ?? null,
                'payment_type_title'    => $raw['payment_type']['title'] ?? null,
                'payment_price'         => $raw['payment_price'] ?? 0,
                'payed'                 => (bool) ($raw['payed'] ?? false),
                'raw'                   => $raw,
                'ordered_at'            => $orderedAt,
            ]
        );

        // Sync order items
        $order->items()->delete();
        
        foreach ($raw['products'] ?? [] as $item) {
            // Skip gifts and set items for counting (only count main products)
            $type = $item['type'] ?? 'product';
            
            OrderItem::create([
                'order_id'        => $order->id,
                'article'         => $item['article'] ?? '',
                'title'           => $item['title'] ?? '',
                'price'           => $item['price'] ?? 0,
                'quantity'        => $item['quantity'] ?? 1,
                'total_price'     => $item['total_price'] ?? 0,
                'discount_marker' => $item['discount_marker'] ?? null,
                'type'            => $type,
            ]);
        }

        return $order;
    }

    protected function getStatusLabel(int $code): string
    {
        return match ($code) {
            1 => 'новий',
            2 => 'в обробці',
            3 => 'доставлено',
            4 => 'не доставлено',
            6 => 'доставляється',
            default => 'невідомий',
        };
    }

    protected function updateOrdersCounts(): void
    {
        $this->info("\n[UPDATE] Calculating orders_count for products...");

        // Count orders per article (only from delivered/delivering orders, excluding gifts)
        $counts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status_code', [3, 6]) // delivered + delivering
            ->whereNotIn('order_items.type', ['gift', 'set_item']) // exclude gifts and set items
            ->groupBy('order_items.article')
            ->select('order_items.article', DB::raw('SUM(order_items.quantity) as total_ordered'))
            ->get()
            ->pluck('total_ordered', 'article');

        $this->info("  Found orders for " . $counts->count() . " unique articles");

        // Reset all to 0 first
        Product::query()->update(['orders_count' => 0]);

        // Update products with counts - separate queries to avoid type mismatch
        $updated = 0;
        $notFound = 0;
        
        foreach ($counts as $article => $count) {
            // Cast article to string for safe comparison
            $articleStr = (string) $article;
            
            // First try exact article match
            $affected = Product::where('article', $articleStr)->update(['orders_count' => $count]);
            
            // If not found, try parent_article
            if ($affected === 0) {
                $affected = Product::where('parent_article', $articleStr)->update(['orders_count' => $count]);
            }
            
            if ($affected > 0) {
                $updated += $affected;
            } else {
                $notFound++;
            }
        }

        $this->info("  Updated {$updated} products");
        if ($notFound > 0) {
            $this->info("  {$notFound} articles not found in products table");
        }
        $this->info("[DONE] orders_count updated");
    }

    protected function showStats(): int
    {
        $this->info("=================================================");
        $this->info("              ORDERS STATISTICS                  ");
        $this->info("=================================================");

        $total = Order::count();
        $this->info("Total orders in DB: {$total}");

        if ($total > 0) {
            $byStatus = Order::groupBy('status_code')
                ->select('status_code', DB::raw('count(*) as cnt'))
                ->pluck('cnt', 'status_code');

            foreach ($byStatus as $code => $cnt) {
                $label = $this->getStatusLabel($code);
                $this->info("  - {$label} ({$code}): {$cnt}");
            }

            $itemsCount = OrderItem::count();
            $this->info("\nTotal order items: {$itemsCount}");

            $uniqueArticles = OrderItem::distinct('article')->count('article');
            $this->info("Unique articles ordered: {$uniqueArticles}");

            $oldest = Order::orderBy('ordered_at')->first();
            $newest = Order::orderByDesc('ordered_at')->first();
            
            if ($oldest && $newest) {
                $this->info("\nDate range: {$oldest->ordered_at->format('Y-m-d')} to {$newest->ordered_at->format('Y-m-d')}");
            }
        }

        $this->newLine();
        $productsWithOrders = Product::where('orders_count', '>', 0)->count();
        $totalProducts = Product::count();
        $this->info("Products with orders_count > 0: {$productsWithOrders} / {$totalProducts}");

        $this->info("=================================================");

        return self::SUCCESS;
    }
}
