<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Horoshop\HoroshopClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchHoroshopOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    protected ?string $sessionId;
    protected ?string $fromDate;
    protected ?string $toDate;
    protected ?array $orderIds;
    protected bool $linkToChat;

    /**
     * Create a new job instance.
     *
     * @param string|null $sessionId  Link orders to this chat session
     * @param string|null $fromDate   Fetch orders from this date (YYYY-MM-DD)
     * @param string|null $toDate     Fetch orders to this date (YYYY-MM-DD)
     * @param array|null  $orderIds   Specific order IDs to fetch
     * @param bool        $linkToChat Whether to try linking orders to chat sessions
     */
    public function __construct(
        ?string $sessionId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?array $orderIds = null,
        bool $linkToChat = true
    ) {
        $this->sessionId = $sessionId;
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->orderIds = $orderIds;
        $this->linkToChat = $linkToChat;
    }

    /**
     * Execute the job.
     */
    public function handle(HoroshopClient $client): void
    {
        if (!$client->isConfigured()) {
            Log::warning('FetchHoroshopOrdersJob: Horoshop not configured, skipping');
            return;
        }

        try {
            $params = ['additionalData' => true];
            
            if ($this->orderIds) {
                $params['ids'] = $this->orderIds;
            } else {
                // Default: fetch last 24 hours if no dates specified
                $params['from'] = $this->fromDate ?? now()->subDay()->format('Y-m-d H:i:s');
                $params['to'] = $this->toDate ?? now()->format('Y-m-d H:i:s');
            }

            Log::info('FetchHoroshopOrdersJob: Fetching orders', $params);

            $response = $client->request('orders/get', $params);
            $orders = $response['orders'] ?? [];

            Log::info('FetchHoroshopOrdersJob: Received orders', ['count' => count($orders)]);

            foreach ($orders as $orderData) {
                $this->processOrder($orderData);
            }

        } catch (\Throwable $e) {
            Log::error('FetchHoroshopOrdersJob failed', [
                'error' => $e->getMessage(),
                'session_id' => $this->sessionId,
            ]);
            throw $e;
        }
    }

    /**
     * Process single order from API response
     */
    protected function processOrder(array $data): void
    {
        $orderId = $data['order_id'] ?? null;
        if (!$orderId) {
            return;
        }

        // Check if already exists
        $existing = Order::where('order_id', $orderId)->first();
        
        // Determine chat attribution
        $hadChat = false;
        $productsFromChat = 0;
        $sessionId = $this->sessionId;
        
        if ($this->linkToChat && !$sessionId) {
            // Try to find session by customer phone or email
            $sessionId = $this->findSessionByCustomer(
                $data['delivery_phone'] ?? null,
                $data['delivery_email'] ?? null
            );
        }
        
        if ($sessionId) {
            $hadChat = $this->checkIfHadChat($sessionId);
            $productsFromChat = $this->countProductsFromChat($sessionId, $data['products'] ?? []);
        }

        // Prepare order data
        $orderPayload = [
            'order_id' => $orderId,
            'session_id' => $sessionId,
            'status_code' => $data['stat_status'] ?? 1,
            'status_label' => Order::STATUS_LABELS[$data['stat_status'] ?? 1] ?? 'Новий',
            'currency' => $data['currency'] ?? 'UAH',
            'total_default' => $data['total_default'] ?? 0,
            'total_sum' => $data['total_sum'] ?? 0,
            'total_quantity' => $data['total_quantity'] ?? 0,
            'discount_value' => $data['discount_value'] ?? 0,
            'coupon_code' => $data['coupon_code'] ?? null,
            'coupon_discount_value' => $data['coupon_discount_value'] ?? 0,
            'customer_name' => $data['delivery_name'] ?? null,
            'customer_email' => $data['delivery_email'] ?? null,
            'customer_phone' => $data['delivery_phone'] ?? null,
            'customer_city' => $data['delivery_city_stable'] ?? $data['delivery_city'] ?? null,
            'customer_address' => $data['delivery_address'] ?? null,
            'delivery_type_id' => $data['delivery_type']['id'] ?? null,
            'delivery_type_title' => $data['delivery_type']['title'] ?? null,
            'delivery_price' => $data['delivery_price'] != -1 ? $data['delivery_price'] : null,
            'delivery_comment' => $data['comment'] ?? null,
            'payment_type_id' => $data['payment_type']['id'] ?? null,
            'payment_type_title' => $data['payment_type']['title'] ?? null,
            'payment_price' => $data['payment_price'] ?? 0,
            'payed' => (bool) ($data['payed'] ?? false),
            'raw' => $data,
            'had_chat' => $hadChat,
            'products_from_chat' => $productsFromChat,
            'analytics' => $data['analytics'] ?? null,
            'ordered_at' => isset($data['stat_created']) ? Carbon::parse($data['stat_created']) : now(),
        ];

        if ($existing) {
            // Update existing order
            $existing->update($orderPayload);
            $order = $existing;
            Log::info('FetchHoroshopOrdersJob: Updated order', ['order_id' => $orderId]);
        } else {
            // Create new order
            $order = Order::create($orderPayload);
            Log::info('FetchHoroshopOrdersJob: Created order', ['order_id' => $orderId, 'had_chat' => $hadChat]);
            
            // Record checkout_success event for new orders (for funnel consistency)
            if ($sessionId && ($data['stat_status'] ?? 1) != 5) {
                $this->recordCheckoutSuccessEvent($order, $sessionId, $hadChat, $productsFromChat);
            }
        }

        // Process order items
        $this->processOrderItems($order, $data['products'] ?? []);
    }

    /**
     * Process order items
     */
    protected function processOrderItems(Order $order, array $products): void
    {
        // Delete existing items and recreate
        OrderItem::where('order_id', $order->id)->delete();

        foreach ($products as $product) {
            OrderItem::create([
                'order_id' => $order->id,
                'article' => $product['article'] ?? '',
                'title' => $product['title'] ?? 'Unknown',
                'price' => $product['price'] ?? 0,
                'quantity' => $product['quantity'] ?? 1,
            ]);
        }
    }

    /**
     * Find chat session by customer phone or email
     * Improved matching: check checkout events, add_to_cart, and chat messages
     */
    protected function findSessionByCustomer(?string $phone, ?string $email): ?string
    {
        if (!$phone && !$email) {
            return null;
        }

        // Normalize phone for comparison
        $normalizedPhone = $phone ? preg_replace('/[^0-9]/', '', $phone) : null;
        $phoneLast10 = $normalizedPhone ? substr($normalizedPhone, -10) : null;

        // Strategy 1: Look for checkout_submit events with matching session in last 24h
        // These events are sent when customer submits order form
        $checkoutEvent = DB::table('chat_events')
            ->where('event_type', 'checkout_submit')
            ->where('created_at', '>=', now()->subHours(24))
            ->orderByDesc('created_at')
            ->first();
        
        if ($checkoutEvent) {
            // Check if this session had chat activity
            $hadChat = $this->checkIfHadChat($checkoutEvent->session_id);
            if ($hadChat) {
                Log::info('FetchHoroshopOrdersJob: Found session from checkout event', [
                    'session_id' => $checkoutEvent->session_id,
                    'phone' => $phone,
                ]);
                return $checkoutEvent->session_id;
            }
        }

        // Strategy 2: Look for sessions with add_to_cart that also had chat
        // Match by time proximity (order created within 2h of last add_to_cart)
        $recentCartSessions = DB::table('chat_events')
            ->where('event_type', 'add_to_cart')
            ->where('created_at', '>=', now()->subHours(2))
            ->select('session_id')
            ->distinct()
            ->pluck('session_id');

        foreach ($recentCartSessions as $sessionId) {
            if ($this->checkIfHadChat($sessionId)) {
                Log::info('FetchHoroshopOrdersJob: Found session from recent add_to_cart', [
                    'session_id' => $sessionId,
                    'phone' => $phone,
                ]);
                return $sessionId;
            }
        }

        // Strategy 3: Look for chat sessions where user might have mentioned their phone
        // This is a best-effort approach for when we don't have direct tracking
        if ($phoneLast10) {
            $messageWithPhone = DB::table('chat_messages')
                ->where('role', 'user')
                ->where('created_at', '>=', now()->subHours(24))
                ->where('content', 'like', '%' . $phoneLast10 . '%')
                ->orderByDesc('created_at')
                ->first();

            if ($messageWithPhone) {
                // Get session_id from chat_sessions
                $session = DB::table('chat_sessions')
                    ->where('id', $messageWithPhone->chat_session_id)
                    ->first();
                
                if ($session) {
                    Log::info('FetchHoroshopOrdersJob: Found session from phone in chat message', [
                        'session_id' => $session->session_id,
                        'phone' => $phone,
                    ]);
                    return $session->session_id;
                }
            }
        }

        return null;
    }

    /**
     * Check if session had meaningful chat conversation.
     * 
     * A "meaningful" conversation requires:
     * - At least 2 user messages (excludes single trigger response like "Так")
     * - OR products were shown in chat (via product_shown events)
     * - OR add_to_cart happened from chat (product_from_chat = true)
     */
    protected function checkIfHadChat(string $sessionId): bool
    {
        // Get chat_session_id if we have string session_id
        $chatSession = DB::table('chat_sessions')
            ->where('session_id', $sessionId)
            ->first();
        
        $chatSessionId = $chatSession?->id;
        
        // Count user messages
        $messageCount = DB::table('chat_messages')
            ->where(function ($q) use ($sessionId, $chatSessionId) {
                $q->where('session_id', $sessionId);
                if ($chatSessionId) {
                    $q->orWhere('chat_session_id', $chatSessionId);
                }
            })
            ->where('role', 'user')
            ->count();

        // At least 2 user messages means real conversation
        if ($messageCount >= 2) {
            return true;
        }
        
        // Check if products were shown in chat (real interaction)
        $productsShown = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'product_shown')
            ->exists();
        
        if ($productsShown) {
            return true;
        }
        
        // Check if add_to_cart was from chat recommendations
        $cartFromChat = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'add_to_cart')
            ->whereRaw("JSON_EXTRACT(metadata, '$.product_from_chat') = true")
            ->exists();
        
        if ($cartFromChat) {
            return true;
        }
        
        return false;
    }

    /**
     * Count how many products in order were shown in chat
     */
    protected function countProductsFromChat(string $sessionId, array $products): int
    {
        if (empty($products)) {
            return 0;
        }

        $articles = array_filter(array_column($products, 'article'));
        if (empty($articles)) {
            return 0;
        }

        // Get articles that were shown in chat
        $shownArticles = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->whereIn('event_type', ['product_shown', 'product_click'])
            ->whereIn('product_article', $articles)
            ->distinct()
            ->pluck('product_article')
            ->toArray();

        return count(array_intersect($articles, $shownArticles));
    }

    /**
     * Record checkout_success event in chat_events for funnel tracking
     */
    protected function recordCheckoutSuccessEvent(Order $order, string $sessionId, bool $hadChat, int $productsFromChat): void
    {
        // Determine merchant_id from session
        $merchantId = null;
        $chatSession = DB::table('chat_sessions')
            ->where('session_id', $sessionId)
            ->first();
        
        if ($chatSession) {
            $tenant = \App\Models\Tenant::find($chatSession->tenant_id);
            $merchantId = $tenant?->slug ?? $tenant?->widgetSettings?->api_token;
        }

        // Check if checkout_success event already exists for this order
        $existingEvent = DB::table('chat_events')
            ->where('event_type', 'checkout_success')
            ->where('session_id', $sessionId)
            ->where('metadata', 'like', '%"order_id":' . $order->order_id . '%')
            ->exists();

        if (!$existingEvent) {
            DB::table('chat_events')->insert([
                'session_id' => $sessionId,
                'merchant_id' => $merchantId,
                'event_type' => 'checkout_success',
                'product_id' => null,
                'product_article' => null,
                'product_price' => $order->total_sum,
                'metadata' => json_encode([
                    'order_id' => $order->order_id,
                    'total_sum' => $order->total_sum,
                    'items_count' => $order->total_quantity,
                    'had_chat' => $hadChat,
                    'products_from_chat' => $productsFromChat,
                    'source' => 'fetch_horoshop_orders_job',
                ]),
                'created_at' => $order->ordered_at ?? now(),
            ]);
            
            Log::info('FetchHoroshopOrdersJob: Recorded checkout_success event', [
                'order_id' => $order->order_id,
                'session_id' => $sessionId,
                'merchant_id' => $merchantId,
            ]);
        }
    }
}
