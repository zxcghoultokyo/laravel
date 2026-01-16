<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConversionAnalytics extends Component
{
    public int $days = 7;
    public array $funnel = [];
    public array $conversions = [];
    public array $checkouts = [];
    public array $chatAttributedConversions = [];
    public ?array $selectedSession = null;
    public ?string $selectedSessionId = null;
    public ?int $selectedCheckoutId = null;
    public array $selectedCheckoutProducts = [];
    public string $activeTab = 'funnel';
    
    public function mount()
    {
        $this->loadData();
    }
    
    public function updatedDays()
    {
        $this->loadData();
    }
    
    public function setTab(string $tab)
    {
        $this->activeTab = $tab;
    }
    
    public function loadData()
    {
        $startDate = now()->subDays($this->days)->startOfDay();
        $endDate = now()->endOfDay();
        
        // Load funnel data
        $this->loadFunnel($startDate, $endDate);
        
        // Load add_to_cart events
        $this->loadAddToCartEvents($startDate);
        
        // Load checkout events
        $this->loadCheckoutEvents($startDate);
    }
    
    private function loadFunnel($startDate, $endDate)
    {
        $stages = [
            'page_view' => ['label' => 'Відвідувачі', 'icon' => '👁️', 'hint' => 'Відкрили сторінку з віджетом'],
            'chat_opened' => ['label' => 'Відкрили чат', 'icon' => '💬', 'hint' => 'Натиснули на іконку чату'],
            'message' => ['label' => 'Написали', 'icon' => '✍️', 'hint' => 'Надіслали повідомлення'],
            'product_click' => ['label' => 'Клік на товар', 'icon' => '👆', 'hint' => 'Клікнули на картку товару'],
            'add_to_cart' => ['label' => 'До кошика', 'icon' => '🛒', 'hint' => 'Додали товар у кошик'],
            'checkout_success' => ['label' => 'Замовлення', 'icon' => '✅', 'hint' => 'Оформили замовлення'],
        ];
        
        $this->funnel = [];
        $prevCount = 0;
        
        foreach ($stages as $eventType => $stage) {
            try {
                $count = DB::table('chat_events')
                    ->where('event_type', $eventType)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->distinct('session_id')
                    ->count('session_id');
            } catch (\Throwable $e) {
                $count = 0;
            }
            
            $rate = $prevCount > 0 ? round(($count / $prevCount) * 100, 1) : 0;
            $dropoff = $prevCount > 0 ? round((($prevCount - $count) / $prevCount) * 100, 1) : 0;
            
            $this->funnel[] = [
                'stage' => $eventType,
                'label' => $stage['label'],
                'icon' => $stage['icon'],
                'hint' => $stage['hint'],
                'count' => $count,
                'rate' => $rate,
                'dropoff' => $dropoff,
            ];
            
            $prevCount = $count ?: $prevCount;
        }
    }
    
    private function loadAddToCartEvents($startDate)
    {
        $events = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'add_to_cart')
            ->orderByDesc('created_at')
            ->get();
        
        $articles = $events->pluck('product_article')->unique()->filter()->toArray();
        $productDataByArticle = [];
        if (!empty($articles)) {
            $products = DB::table('products')
                ->whereIn('article', $articles)
                ->select('article', 'title', 'raw')
                ->get();
            foreach ($products as $p) {
                $raw = json_decode($p->raw ?? '{}', true);
                $productDataByArticle[$p->article] = [
                    'title' => $p->title,
                    'url' => $raw['link'] ?? $raw['url'] ?? null,
                ];
            }
        }
        
        $this->conversions = $events->map(function ($event) use ($productDataByArticle) {
                $meta = json_decode($event->metadata ?? '{}', true);
                
                $title = $meta['product_title'] ?? null;
                $url = null;
                if ($event->product_article && isset($productDataByArticle[$event->product_article])) {
                    $title = $title ?: $productDataByArticle[$event->product_article]['title'];
                    $url = $productDataByArticle[$event->product_article]['url'];
                }
                
                return [
                    'id' => $event->id,
                    'session_id' => $event->session_id,
                    'product_id' => $event->product_id,
                    'product_article' => $event->product_article,
                    'product_title' => $title,
                    'product_url' => $url,
                    'product_price' => $event->product_price,
                    'had_chat' => $meta['had_chat_conversation'] ?? false,
                    'from_chat' => $meta['product_from_chat'] ?? false,
                    'chat_session_id' => $meta['chat_session_id'] ?? $event->session_id,
                    'utm_source' => $event->utm_source,
                    'utm_campaign' => $event->utm_campaign,
                    'utm_medium' => $event->utm_medium,
                    'referrer' => $event->referrer,
                    'page_url' => $event->page_url,
                    'created_at' => $event->created_at,
                ];
            })
            ->toArray();
        
        $this->chatAttributedConversions = array_filter($this->conversions, fn($c) => $c['had_chat'] || $c['from_chat']);
    }
    
    private function loadCheckoutEvents($startDate)
    {
        // First, try to load from orders table (with Horoshop data)
        $orders = collect();
        if (Schema::hasTable('orders')) {
            $orders = DB::table('orders')
                ->where('created_at', '>=', $startDate)
                ->orderByDesc('created_at')
                ->get();
        }
        
        // Also load from chat_events (only those with chat!)
        $events = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->whereIn('event_type', ['checkout_success', 'checkout_submit'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(function ($event) {
                $meta = json_decode($event->metadata ?? '{}', true);
                return $meta['had_chat_conversation'] ?? false;
            });
        
        // Map orders (preferred - has Horoshop data with products) - ONLY with chat
        $checkoutsFromOrders = $orders
            ->filter(fn($order) => (bool)($order->had_chat ?? false))
            ->map(function ($order) {
                $raw = json_decode($order->raw ?? '{}', true);
            
                // Extract products from raw Horoshop data
                $products = $raw['products'] ?? [];
            
                return [
                    'id' => $order->id,
                    'source' => 'horoshop',
                    'order_id' => $order->order_id ?? $order->id,
                    'session_id' => $order->session_id ?? null,
                    'event_type' => 'checkout_success',
                    'status' => $order->status_code ?? 'new',
                    'status_label' => $order->status_label ?? $this->getOrderStatusLabel($order->status_code ?? 'new'),
                    'order_total' => $order->total_sum ?? 0,
                    'items_count' => $order->total_quantity ?? count($products),
                    'had_chat' => true,
                    'products_from_chat' => $order->products_from_chat ?? 0,
                    'created_at' => $order->ordered_at ?? $order->created_at,
                    // Full customer info from Horoshop
                    'customer_name' => $order->customer_name,
                    'customer_phone' => $order->customer_phone,
                    'customer_email' => $order->customer_email,
                    'customer_city' => $order->customer_city,
                    'customer_address' => $order->customer_address,
                    // Delivery
                    'delivery_type' => $order->delivery_type_title,
                    'delivery_price' => $order->delivery_price,
                    'delivery_comment' => $order->delivery_comment,
                    // Payment
                    'payment_type' => $order->payment_type_title,
                    'payed' => (bool)$order->payed,
                    // Products count only, not full data (to reduce payload)
                    'has_products' => count($products) > 0,
                ];
            });
        
        // Map events (fallback for orders without Horoshop sync)
        $orderIds = $orders->pluck('order_id')->filter()->toArray();
        $eventsWithoutOrders = $events->filter(function ($event) use ($orderIds) {
            $meta = json_decode($event->metadata ?? '{}', true);
            $orderId = $meta['order_id'] ?? null;
            // Skip events that already have order in orders table
            return !$orderId || !in_array($orderId, $orderIds);
        });
        
        $checkoutsFromEvents = $eventsWithoutOrders->map(function ($event) {
            $meta = json_decode($event->metadata ?? '{}', true);
            
            return [
                'id' => $event->id,
                'source' => 'event',
                'order_id' => $meta['order_id'] ?? null,
                'session_id' => $event->session_id,
                'event_type' => $event->event_type,
                'status' => 'new',
                'status_label' => 'Новий',
                'order_total' => $meta['order_total'] ?? $event->product_price ?? 0,
                'items_count' => $meta['items_count'] ?? $meta['order_items_count'] ?? 0,
                'had_chat' => true,
                'products_from_chat' => $meta['products_from_chat'] ?? 0,
                'created_at' => $event->created_at,
                // Customer info (from event metadata)
                'customer_name' => $meta['customer_name'] ?? $meta['name'] ?? null,
                'customer_phone' => $meta['phone'] ?? null,
                'customer_email' => $meta['email'] ?? null,
                'customer_city' => null,
                'customer_address' => null,
                'delivery_type' => null,
                'delivery_price' => null,
                'delivery_comment' => null,
                'payment_type' => null,
                'payed' => false,
                'has_products' => false,
            ];
        });
        
        // Merge and sort by date
        $this->checkouts = $checkoutsFromOrders
            ->merge($checkoutsFromEvents)
            ->sortByDesc('created_at')
            ->values()
            ->toArray();
    }
    
    private function getOrderStatusLabel(string $status): string
    {
        $labels = [
            'new' => 'Новий',
            'processing' => 'В обробці',
            'delivered' => 'Доставлено',
            'not_delivered' => 'Не доставлено',
            'delivering' => 'Доставляється',
            'cancelled' => 'Скасовано',
        ];
        return $labels[$status] ?? $status;
    }
    
    /**
     * Load products for a specific order (lazy loading to reduce payload)
     */
    public function loadCheckoutProducts(int $orderId)
    {
        $this->selectedCheckoutId = $orderId;
        $this->selectedCheckoutProducts = [];
        
        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order && $order->raw) {
            $raw = json_decode($order->raw, true);
            $products = $raw['products'] ?? [];
            
            $this->selectedCheckoutProducts = collect($products)->map(fn($p) => [
                'title' => $p['title'] ?? $p['name'] ?? 'Товар',
                'article' => $p['article'] ?? '',
                'price' => $p['price'] ?? 0,
                'quantity' => $p['quantity'] ?? 1,
            ])->toArray();
        }
    }
    
    public function closeCheckoutProducts()
    {
        $this->selectedCheckoutId = null;
        $this->selectedCheckoutProducts = [];
    }
    
    public function viewSession($sessionId)
    {
        $this->selectedSessionId = $sessionId;
        
        // Get session info
        $session = DB::table('chat_sessions')
            ->where('session_id', $sessionId)
            ->first();
        
        // Get all messages in this session
        $messages = [];
        if ($session) {
            $messages = DB::table('chat_messages')
                ->where('chat_session_id', $session->id)
                ->orderBy('created_at')
                ->get()
                ->map(function ($msg) {
                    $content = $msg->content;
                    // Try to parse JSON content
                    if (str_starts_with(trim($content), '{')) {
                        $parsed = json_decode($content, true);
                        if ($parsed && isset($parsed['intro'])) {
                            $content = $parsed['intro'];
                            if (!empty($parsed['products'])) {
                                $content .= ' [' . count($parsed['products']) . ' товарів показано]';
                            }
                        }
                    }
                    return [
                        'role' => $msg->role,
                        'content' => $content,
                        'created_at' => $msg->created_at,
                    ];
                })
                ->toArray();
        }
        
        // Get products shown in this session
        $productsShown = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'product_shown')
            ->whereNotNull('product_id')
            ->select('product_id', 'product_article', 'product_price')
            ->distinct()
            ->get()
            ->toArray();
        
        // Get products clicked
        $productsClicked = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'product_click')
            ->whereNotNull('product_id')
            ->select('product_id', 'product_article', 'product_price')
            ->get()
            ->toArray();
        
        // Get add to cart events for this session (with metadata for title)
        $addedToCart = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'add_to_cart')
            ->select('product_id', 'product_article', 'product_price', 'created_at', 'metadata')
            ->get()
            ->toArray();
        
        // Get checkout/order events for this session
        $checkouts = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->whereIn('event_type', ['checkout_submit', 'checkout_success'])
            ->select('event_type', 'product_price', 'created_at', 'metadata')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($e) {
                $meta = json_decode($e->metadata ?? '{}', true);
                return [
                    'event_type' => $e->event_type,
                    'order_total' => $meta['order_total'] ?? $e->product_price ?? 0,
                    'items_count' => $meta['items_count'] ?? $meta['order_items_count'] ?? 0,
                    'order_id' => $meta['order_id'] ?? null,
                    'created_at' => $e->created_at,
                ];
            })
            ->toArray();
        
        // Get UTM data from first page_view in session
        $firstEvent = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->whereNotNull('utm_source')
            ->first();
        
        $utmData = null;
        if ($firstEvent) {
            $utmData = [
                'utm_source' => $firstEvent->utm_source,
                'utm_campaign' => $firstEvent->utm_campaign,
                'utm_medium' => $firstEvent->utm_medium,
                'referrer' => $firstEvent->referrer,
            ];
        }
        
        // Get product data - by article (primary) and by product_id (fallback)
        $productArticles = collect($productsShown)
            ->merge($productsClicked)
            ->merge($addedToCart)
            ->pluck('product_article')
            ->unique()
            ->filter()
            ->toArray();
        
        $productDataByArticle = [];
        if (!empty($productArticles)) {
            $products = DB::table('products')
                ->whereIn('article', $productArticles)
                ->select('article', 'title', 'raw')
                ->get();
            foreach ($products as $p) {
                $raw = json_decode($p->raw ?? '{}', true);
                $productDataByArticle[$p->article] = [
                    'title' => $p->title,
                    'url' => $raw['link'] ?? $raw['url'] ?? null,
                ];
            }
        }
        
        $productIds = collect($productsShown)
            ->merge($productsClicked)
            ->merge($addedToCart)
            ->pluck('product_id')
            ->unique()
            ->filter()
            ->toArray();
        
        $productTitlesById = [];
        if (!empty($productIds)) {
            $productTitlesById = DB::table('products')
                ->whereIn('id', $productIds)
                ->pluck('title', 'id')
                ->toArray();
        }
        
        // Helper to get title and URL from various sources
        $getProductData = function ($item) use ($productDataByArticle, $productTitlesById) {
            $title = null;
            $url = null;
            
            // 1. Try metadata (stored from widget)
            if (isset($item->metadata)) {
                $meta = json_decode($item->metadata, true);
                if (!empty($meta['product_title'])) {
                    $title = $meta['product_title'];
                }
            }
            // 2. Try by article (most reliable)
            if (!empty($item->product_article) && isset($productDataByArticle[$item->product_article])) {
                $title = $title ?: $productDataByArticle[$item->product_article]['title'];
                $url = $productDataByArticle[$item->product_article]['url'];
            }
            // 3. Try by product_id
            if (!$title && !empty($item->product_id) && isset($productTitlesById[$item->product_id])) {
                $title = $productTitlesById[$item->product_id];
            }
            
            return [
                'title' => $title ?: 'Unknown',
                'url' => $url,
            ];
        };
        
        $this->selectedSession = [
            'session_id' => $sessionId,
            'created_at' => $session->created_at ?? null,
            'messages' => $messages,
            'utm' => $utmData,
            'checkouts' => $checkouts,
            'products_shown' => array_map(function ($p) use ($getProductData) {
                $data = $getProductData($p);
                return [
                    ...(array)$p,
                    'title' => $data['title'],
                    'url' => $data['url'],
                ];
            }, $productsShown),
            'products_clicked' => array_map(function ($p) use ($getProductData) {
                $data = $getProductData($p);
                return [
                    ...(array)$p,
                    'title' => $data['title'],
                    'url' => $data['url'],
                ];
            }, $productsClicked),
            'added_to_cart' => array_map(function ($p) use ($getProductData) {
                $data = $getProductData($p);
                return [
                    ...(array)$p,
                    'title' => $data['title'],
                    'url' => $data['url'],
                ];
            }, $addedToCart),
        ];
    }
    
    public function closeSession()
    {
        $this->selectedSession = null;
        $this->selectedSessionId = null;
    }
    
    public function render()
    {
        return view('livewire.admin.conversion-analytics')->layout('admin.layout');
    }
}
