<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SyncLog;
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
        {--link-chat : Re-link existing orders to chat sessions without API sync}
        {--stats : Show statistics only}';

    protected $description = 'Sync orders from Horoshop API and optionally update product orders_count';

    protected const CACHE_KEY = 'sync_orders:offset';

    public function handle(HoroshopClient $client): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        if ($this->option('link-chat')) {
            return $this->linkExistingOrdersToChat();
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

        // Create SyncLog entry
        $syncLog = SyncLog::create([
            'sync_type' => SyncLog::TYPE_ORDERS,
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now(),
            'meta' => [
                'from' => $from,
                'to' => $to,
                'limit' => $limit,
                'statuses' => $statuses,
            ],
        ]);

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

        // Update SyncLog
        $syncLog->update([
            'status' => SyncLog::STATUS_COMPLETED,
            'completed_at' => now(),
            'items_synced' => $totalSynced,
            'meta' => array_merge($syncLog->meta ?? [], [
                'total_orders' => $totalSynced,
                'total_items' => $totalItems,
                'errors' => $errors,
                'elapsed_seconds' => $elapsed,
            ]),
        ]);

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

        // Try to find and link chat session
        $chatData = $this->findChatSession(
            $raw['delivery_phone'] ?? null,
            $raw['delivery_email'] ?? null,
            $raw['products'] ?? [],
            $orderedAt
        );

        $order = Order::updateOrCreate(
            ['order_id' => $raw['order_id']],
            [
                'session_id'            => $chatData['session_id'],
                'had_chat'              => $chatData['had_chat'],
                'products_from_chat'    => $chatData['products_from_chat'],
                'analytics'             => $raw['analytics'] ?? null,
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

        // Record checkout_success event in chat_events for funnel consistency
        // Only if we have a session_id and order is new (not cancelled)
        if ($chatData['session_id'] && ($raw['stat_status'] ?? 1) != 5) {
            $this->recordCheckoutSuccessEvent($order, $chatData, $raw);
        }

        return $order;
    }

    /**
     * Record checkout_success event in chat_events for funnel tracking
     */
    protected function recordCheckoutSuccessEvent(Order $order, array $chatData, array $raw): void
    {
        // Determine merchant_id from session
        $merchantId = null;
        $chatSession = DB::table('chat_sessions')
            ->where('session_id', $chatData['session_id'])
            ->first();
        
        if ($chatSession) {
            $tenant = \App\Models\Tenant::find($chatSession->tenant_id);
            $merchantId = $tenant?->slug ?? $tenant?->widgetSettings?->api_token;
        }

        // Check if checkout_success event already exists for this order
        $existingEvent = DB::table('chat_events')
            ->where('event_type', 'checkout_success')
            ->where('session_id', $chatData['session_id'])
            ->where('metadata', 'like', '%"order_id":' . $order->order_id . '%')
            ->exists();

        if (!$existingEvent) {
            DB::table('chat_events')->insert([
                'session_id' => $chatData['session_id'],
                'merchant_id' => $merchantId,
                'event_type' => 'checkout_success',
                'product_id' => null,
                'product_article' => null,
                'product_price' => $order->total_sum,
                'metadata' => json_encode([
                    'order_id' => $order->order_id,
                    'total_sum' => $order->total_sum,
                    'items_count' => $order->total_quantity,
                    'had_chat' => $chatData['had_chat'],
                    'products_from_chat' => $chatData['products_from_chat'],
                    'source' => 'sync_orders',
                ]),
                'created_at' => $order->ordered_at ?? now(),
                'updated_at' => now(),
            ]);
        }
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

    /**
     * Find chat session that can be linked to this order
     * Uses multiple strategies: checkout events, add_to_cart timing, phone matching
     */
    protected function findChatSession(?string $phone, ?string $email, array $products, ?\Carbon\Carbon $orderedAt): array
    {
        $result = [
            'session_id' => null,
            'had_chat' => false,
            'products_from_chat' => 0,
        ];

        // Normalize phone for comparison (last 10 digits)
        $phoneLast10 = $phone ? substr(preg_replace('/[^0-9]/', '', $phone), -10) : null;
        
        // Time window: look for chat sessions 24h before order
        $orderTime = $orderedAt ?? now();
        $windowStart = $orderTime->copy()->subHours(24);
        $windowEnd = $orderTime->copy()->addMinutes(30); // Some buffer for timezone issues

        // Strategy 1: Look for checkout_submit events around order time
        $checkoutEvent = DB::table('chat_events')
            ->where('event_type', 'checkout_submit')
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->orderByDesc('created_at')
            ->first();

        if ($checkoutEvent) {
            $sessionId = $checkoutEvent->session_id;
            if ($this->sessionHadChat($sessionId)) {
                $result['session_id'] = $sessionId;
                $result['had_chat'] = true;
                $result['products_from_chat'] = $this->countProductsFromChat($sessionId, $products);
                return $result;
            }
        }

        // Strategy 2: Look for add_to_cart events with matching products
        $orderArticles = array_filter(array_column($products, 'article'));
        if (!empty($orderArticles)) {
            $cartEvent = DB::table('chat_events')
                ->where('event_type', 'add_to_cart')
                ->whereIn('product_article', $orderArticles)
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->orderByDesc('created_at')
                ->first();

            if ($cartEvent) {
                $sessionId = $cartEvent->session_id;
                if ($this->sessionHadChat($sessionId)) {
                    $result['session_id'] = $sessionId;
                    $result['had_chat'] = true;
                    $result['products_from_chat'] = $this->countProductsFromChat($sessionId, $products);
                    return $result;
                }
            }
        }

        // Strategy 3: Look for chat messages containing the phone number
        if ($phoneLast10) {
            $messageWithPhone = DB::table('chat_messages')
                ->where('role', 'user')
                ->where('content', 'like', '%' . $phoneLast10 . '%')
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->orderByDesc('created_at')
                ->first();

            if ($messageWithPhone) {
                $session = DB::table('chat_sessions')
                    ->where('id', $messageWithPhone->chat_session_id)
                    ->first();

                if ($session) {
                    $result['session_id'] = $session->session_id;
                    $result['had_chat'] = true;
                    $result['products_from_chat'] = $this->countProductsFromChat($session->session_id, $products);
                    return $result;
                }
            }
        }

        // Strategy 4: Look for any chat session with product_shown events for ordered products
        if (!empty($orderArticles)) {
            $shownEvent = DB::table('chat_events')
                ->whereIn('event_type', ['product_shown', 'product_click'])
                ->whereIn('product_article', $orderArticles)
                ->whereBetween('created_at', [$windowStart, $windowEnd])
                ->orderByDesc('created_at')
                ->first();

            if ($shownEvent && $this->sessionHadChat($shownEvent->session_id)) {
                $result['session_id'] = $shownEvent->session_id;
                $result['had_chat'] = true;
                $result['products_from_chat'] = $this->countProductsFromChat($shownEvent->session_id, $products);
                return $result;
            }
        }

        return $result;
    }

    /**
     * Check if session had meaningful chat conversation
     */
    protected function sessionHadChat(string $sessionId): bool
    {
        // Check chat_messages via chat_sessions
        $session = DB::table('chat_sessions')
            ->where('session_id', $sessionId)
            ->first();

        if ($session) {
            $messageCount = DB::table('chat_messages')
                ->where('chat_session_id', $session->id)
                ->where('role', 'user')
                ->count();

            if ($messageCount > 0) {
                return true;
            }
        }

        // Also check chat_events for message events
        $eventMessages = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'message')
            ->where('message_type', 'user')
            ->count();

        return $eventMessages > 0;
    }

    /**
     * Count how many products in order were shown/clicked in chat
     */
    protected function countProductsFromChat(string $sessionId, array $products): int
    {
        $orderArticles = array_filter(array_column($products, 'article'));
        if (empty($orderArticles)) {
            return 0;
        }

        $shownArticles = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->whereIn('event_type', ['product_shown', 'product_click', 'add_to_cart'])
            ->whereIn('product_article', $orderArticles)
            ->distinct()
            ->pluck('product_article')
            ->toArray();

        return count(array_intersect($orderArticles, $shownArticles));
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
            
            // Chat linking stats
            $withChat = Order::where('had_chat', true)->count();
            $this->info("\nOrders with chat: {$withChat}");
        }

        $this->newLine();
        $productsWithOrders = Product::where('orders_count', '>', 0)->count();
        $totalProducts = Product::count();
        $this->info("Products with orders_count > 0: {$productsWithOrders} / {$totalProducts}");

        $this->info("=================================================");

        return self::SUCCESS;
    }

    /**
     * Re-link existing orders to chat sessions without API sync
     */
    protected function linkExistingOrdersToChat(): int
    {
        $this->info("=================================================");
        $this->info("[LINK] Linking existing orders to chat sessions...");
        $this->info("=================================================");

        $orders = Order::whereNull('session_id')
            ->orWhere('session_id', '')
            ->orderByDesc('ordered_at')
            ->get();

        $this->info("Found {$orders->count()} orders without session link");

        $linked = 0;
        $notLinked = 0;

        foreach ($orders as $order) {
            // raw is already cast to array by Eloquent
            $raw = $order->raw ?? [];
            $products = $raw['products'] ?? [];

            $chatData = $this->findChatSession(
                $order->customer_phone,
                $order->customer_email,
                $products,
                $order->ordered_at
            );

            if ($chatData['session_id']) {
                $order->update([
                    'session_id' => $chatData['session_id'],
                    'had_chat' => $chatData['had_chat'],
                    'products_from_chat' => $chatData['products_from_chat'],
                ]);
                $linked++;
                $this->line("[LINKED] Order #{$order->order_id} → session: {$chatData['session_id']}");
            } else {
                $notLinked++;
            }
        }

        $this->newLine();
        $this->info("=================================================");
        $this->info("[DONE] Linked: {$linked} | Not linked: {$notLinked}");
        $this->info("=================================================");

        return self::SUCCESS;
    }
}
