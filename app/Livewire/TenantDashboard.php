<?php

namespace App\Livewire;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Product;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantDashboard extends Component
{
    use WithPagination;

    public string $activeTab = 'overview';
    public array $stats = [];
    public array $chartData = [];
    public array $funnelData = [];

    // Chat filters
    public string $chatSearch = '';
    public string $chatStatus = '';
    
    // Selected chat for inline view
    public ?string $selectedChatId = null;
    
    // Settings form
    public string $settingsName = '';
    public string $settingsDomain = '';
    public string $settingsPlatform = '';
    public bool $editingSettings = false;
    
    // Conversions tab data
    public int $conversionsDays = 7;
    public string $conversionsActiveTab = 'funnel';
    // REMOVED: $conversionsData - use $funnelData instead (one source of truth)
    public array $cartEvents = [];
    public array $chatAttributedConversions = [];
    public array $checkoutOrders = [];
    public ?array $selectedConversionSession = null;
    public ?string $selectedConversionSessionId = null;
    public ?int $selectedCheckoutId = null;
    public array $selectedCheckoutProducts = [];
    
    protected $queryString = ['activeTab', 'selectedChatId'];

    public function mount()
    {
        $this->loadStats();
        $this->loadChartData();
        $this->loadFunnelData();
        
        // Load conversions data if on conversions tab
        if ($this->activeTab === 'conversions') {
            $this->loadConversionsData();
        }
    }
    
    /**
     * Called on every request - ensures data is always loaded
     */
    public function boot()
    {
        if (empty($this->stats) || empty($this->chartData) || empty($this->funnelData)) {
            $this->loadStats();
            $this->loadChartData();
            $this->loadFunnelData();
        }
        
        // Ensure conversions data on conversions tab
        if ($this->activeTab === 'conversions' && empty($this->chatAttributedConversions)) {
            $this->loadConversionsData();
        }
    }
    
    /**
     * Called after Livewire hydration (navigation, tab switch, etc.)
     */
    public function hydrate()
    {
        if (empty($this->chartData) || empty($this->funnelData)) {
            $this->loadStats();
            $this->loadChartData();
            $this->loadFunnelData();
        }
        
        // Ensure conversions data on conversions tab
        if ($this->activeTab === 'conversions' && empty($this->chatAttributedConversions)) {
            $this->loadConversionsData();
        }
    }
    
    // ==== CONVERSIONS TAB METHODS ====
    
    public function setConversionsTab(string $tab)
    {
        $this->conversionsActiveTab = $tab;
    }
    
    public function updatedConversionsDays()
    {
        // Reload funnel with new days filter
        $this->loadFunnelData();
        $this->loadConversionsData();
    }
    
    public function loadConversionsData()
    {
        $tenant = $this->tenant;
        $startDate = now()->subDays($this->conversionsDays)->startOfDay();
        
        // Load cart events and orders (funnel is already in $funnelData)
        $this->loadCartEventsForTenant($startDate);
        $this->loadCheckoutOrdersForTenant($startDate);
    }
    
    // REMOVED loadConversionsFunnel - use loadFunnelData() instead (single source of truth)
    
    private function loadCartEventsForTenant($startDate)
    {
        $tenantId = $this->tenant->id;
        
        $query = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'add_to_cart')
            ->where('tenant_id', $tenantId);
        
        $events = $query->orderByDesc('created_at')->get();
        
        // Get product data
        $articles = $events->pluck('product_article')->unique()->filter()->toArray();
        $productDataByArticle = [];
        if (!empty($articles)) {
            $products = DB::table('products')
                ->whereIn('article', $articles)
                ->where('tenant_id', $tenantId)
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
        
        $this->cartEvents = $events->map(function ($event) use ($productDataByArticle) {
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
                'created_at' => $event->created_at,
            ];
        })->toArray();
        
        $this->chatAttributedConversions = array_filter($this->cartEvents, fn($c) => $c['had_chat'] || $c['from_chat']);
    }
    
    private function loadCheckoutOrdersForTenant($startDate)
    {
        if (!Schema::hasTable('orders')) {
            $this->checkoutOrders = [];
            return;
        }
        
        $tenantId = $this->tenant->id;
        
        // Get tenant's session_ids from chat_sessions for filtering
        $tenantSessionIds = DB::table('chat_sessions')
            ->where('tenant_id', $tenantId)
            ->pluck('session_id')
            ->toArray();
        
        if (empty($tenantSessionIds)) {
            $this->checkoutOrders = [];
            return;
        }
        
        // Filter orders by session_id belonging to this tenant
        $query = DB::table('orders')
            ->where('created_at', '>=', $startDate)
            ->where('had_chat', true)
            ->whereIn('session_id', $tenantSessionIds);
        
        $orders = $query->orderByDesc('ordered_at')->get();
        
        $this->checkoutOrders = $orders->map(function ($order) {
            $raw = json_decode($order->raw ?? '{}', true);
            $products = $raw['products'] ?? [];
            
            $statusLabels = [
                'new' => 'Новий',
                'processing' => 'В обробці',
                'delivered' => 'Доставлено',
                'not_delivered' => 'Не доставлено',
                'delivering' => 'Доставляється',
                'cancelled' => 'Скасовано',
            ];
            
            return [
                'id' => $order->id,
                'source' => 'horoshop',
                'order_id' => $order->order_id ?? $order->id,
                'session_id' => $order->session_id ?? null,
                'status' => $order->status_code ?? 'new',
                'status_label' => $statusLabels[$order->status_code ?? 'new'] ?? ($order->status_code ?? 'new'),
                'order_total' => $order->total_sum ?? 0,
                'items_count' => $order->total_quantity ?? count($products),
                'had_chat' => true,
                'created_at' => $order->ordered_at ?? $order->created_at,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_email' => $order->customer_email,
                'customer_city' => $order->customer_city,
                'customer_address' => $order->customer_address,
                'delivery_type' => $order->delivery_type_title,
                'delivery_price' => $order->delivery_price,
                'delivery_comment' => $order->delivery_comment,
                'payment_type' => $order->payment_type_title,
                'payed' => (bool)($order->payed ?? false),
                'has_products' => count($products) > 0,
            ];
        })->sortByDesc('created_at')->values()->toArray();
    }
    
    public function viewConversionSession($sessionId)
    {
        $this->selectedConversionSessionId = $sessionId;
        $tenant = $this->tenant;
        $slug = $tenant->slug;
        $apiToken = $tenant->widgetSettings?->api_token;
        
        // Get session info
        $session = DB::table('chat_sessions')
            ->where('session_id', $sessionId)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        // Get messages
        $messages = [];
        if ($session) {
            $messages = DB::table('chat_messages')
                ->where('chat_session_id', $session->id)
                ->orderBy('created_at')
                ->get()
                ->map(function ($msg) {
                    $content = $msg->content;
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
        
        // Products shown
        $productsShownQuery = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'product_shown')
            ->whereNotNull('product_id')
            ->where(function($q) use ($slug, $apiToken) {
                $q->where('merchant_id', $slug);
                if ($apiToken) {
                    $q->orWhere('merchant_id', $apiToken);
                }
            });
        $productsShown = $productsShownQuery
            ->select('product_id', 'product_article', 'product_price')
            ->distinct()
            ->get()
            ->toArray();
        
        // Products clicked
        $productsClickedQuery = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'product_click')
            ->whereNotNull('product_id')
            ->where(function($q) use ($slug, $apiToken) {
                $q->where('merchant_id', $slug);
                if ($apiToken) {
                    $q->orWhere('merchant_id', $apiToken);
                }
            });
        $productsClicked = $productsClickedQuery
            ->select('product_id', 'product_article', 'product_price')
            ->get()
            ->toArray();
        
        // Add to cart
        $addedToCartQuery = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->where('event_type', 'add_to_cart')
            ->where(function($q) use ($slug, $apiToken) {
                $q->where('merchant_id', $slug);
                if ($apiToken) {
                    $q->orWhere('merchant_id', $apiToken);
                }
            });
        $addedToCart = $addedToCartQuery
            ->select('product_id', 'product_article', 'product_price', 'created_at', 'metadata')
            ->get()
            ->toArray();
        
        // Checkout events
        $rawCheckoutsQuery = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->whereIn('event_type', ['checkout_submit', 'checkout_success'])
            ->where(function($q) use ($slug, $apiToken) {
                $q->where('merchant_id', $slug);
                if ($apiToken) {
                    $q->orWhere('merchant_id', $apiToken);
                }
            });
        $rawCheckouts = $rawCheckoutsQuery
            ->select('event_type', 'product_price', 'created_at', 'metadata')
            ->orderBy('created_at')
            ->get();
        
        // Deduplicate checkouts
        $checkouts = [];
        $lastCheckoutTime = null;
        foreach ($rawCheckouts as $e) {
            $meta = json_decode($e->metadata ?? '{}', true);
            $currentTime = strtotime($e->created_at);
            
            if ($lastCheckoutTime && ($currentTime - $lastCheckoutTime) < 300) {
                continue;
            }
            
            $lastCheckoutTime = $currentTime;
            $checkouts[] = [
                'event_type' => $e->event_type,
                'order_total' => $meta['order_total'] ?? $e->product_price ?? 0,
                'items_count' => $meta['items_count'] ?? $meta['order_items_count'] ?? 0,
                'order_id' => $meta['order_id'] ?? null,
                'customer_name' => $meta['customer_name'] ?? $meta['name'] ?? null,
                'customer_phone' => $meta['phone'] ?? null,
                'customer_email' => $meta['email'] ?? null,
                'delivery_type' => $meta['delivery_type'] ?? null,
                'payment_type' => $meta['payment_type'] ?? null,
                'created_at' => $e->created_at,
            ];
        }
        $checkouts = array_reverse($checkouts);
        
        // UTM data
        $firstEvent = DB::table('chat_events')
            ->where('session_id', $sessionId)
            ->whereNotNull('utm_source')
            ->where(function($q) use ($slug, $apiToken) {
                $q->where('merchant_id', $slug);
                if ($apiToken) {
                    $q->orWhere('merchant_id', $apiToken);
                }
            })
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
        
        // Get product titles
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
                ->where('tenant_id', $tenant->id)
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
                ->where('tenant_id', $tenant->id)
                ->pluck('title', 'id')
                ->toArray();
        }
        
        $getProductData = function ($item) use ($productDataByArticle, $productTitlesById) {
            $title = null;
            $url = null;
            
            if (isset($item->metadata)) {
                $meta = json_decode($item->metadata, true);
                if (!empty($meta['product_title'])) {
                    $title = $meta['product_title'];
                }
            }
            if (!empty($item->product_article) && isset($productDataByArticle[$item->product_article])) {
                $title = $title ?: $productDataByArticle[$item->product_article]['title'];
                $url = $productDataByArticle[$item->product_article]['url'];
            }
            if (!$title && !empty($item->product_id) && isset($productTitlesById[$item->product_id])) {
                $title = $productTitlesById[$item->product_id];
            }
            
            return [
                'title' => $title ?: 'Unknown',
                'url' => $url,
            ];
        };
        
        $this->selectedConversionSession = [
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
    
    public function closeConversionSession()
    {
        $this->selectedConversionSession = null;
        $this->selectedConversionSessionId = null;
    }
    
    public function loadConversionCheckoutProducts(int $orderId)
    {
        $this->selectedCheckoutId = $orderId;
        $this->selectedCheckoutProducts = [];
        
        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order && $order->raw) {
            $raw = json_decode($order->raw, true);
            $products = $raw['products'] ?? [];
            $shopDomain = config('services.horoshop.domain', '');
            
            $this->selectedCheckoutProducts = collect($products)->map(function($p) use ($shopDomain) {
                $article = $p['article'] ?? '';
                $url = null;
                
                if (!empty($p['url'])) {
                    $url = $p['url'];
                } elseif (!empty($p['href'])) {
                    $url = $p['href'];
                } elseif ($article && $shopDomain) {
                    $dbProduct = Product::where('article', $article)->where('tenant_id', $this->tenant->id)->first();
                    if ($dbProduct && !empty($dbProduct->raw['url'])) {
                        $url = $dbProduct->raw['url'];
                    }
                }
                
                return [
                    'title' => $p['title'] ?? $p['name'] ?? 'Товар',
                    'article' => $article,
                    'price' => $p['price'] ?? 0,
                    'quantity' => $p['quantity'] ?? 1,
                    'url' => $url,
                ];
            })->toArray();
        }
    }
    
    public function closeConversionCheckoutProducts()
    {
        $this->selectedCheckoutId = null;
        $this->selectedCheckoutProducts = [];
    }

    // ==== END CONVERSIONS TAB METHODS ====

    public function getTenantProperty(): Tenant
    {
        return Auth::user()->tenant;
    }

    public function loadStats()
    {
        $tenant = $this->tenant;
        $startDate = now()->subDays(30);

        $this->stats = [
            // Usage
            'messages_used' => $tenant->messages_used,
            'messages_limit' => $tenant->messages_limit,
            'usage_percentage' => $tenant->getUsagePercentage(),
            
            // Sessions
            'total_sessions' => ChatSession::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)->count(),
            'sessions_30d' => ChatSession::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $startDate)
                ->count(),
            
            // Messages
            'total_messages' => ChatMessage::where('tenant_id', $tenant->id)->count(),
            'messages_30d' => ChatMessage::where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $startDate)
                ->count(),
            
            // Products
            'products_count' => $tenant->products()->withoutGlobalScope(TenantScope::class)->count(),
            'products_in_stock' => $tenant->products()->withoutGlobalScope(TenantScope::class)->where('in_stock', true)->count(),
            
            // Categories - count unique category paths
            'categories_count' => $tenant->products()
                ->withoutGlobalScope(TenantScope::class)
                ->whereNotNull('category_path')
                ->where('category_path', '!=', '')
                ->selectRaw('COUNT(DISTINCT category_path) as cnt')
                ->value('cnt') ?? 0,
            
            // Plan
            'plan' => $tenant->plan,
            'plan_label' => $tenant->getPlanLabel(),
            'trial_ends_at' => $tenant->trial_ends_at,
            'is_trial' => $tenant->isOnTrial(),
            'is_trial_expired' => $tenant->isTrialExpired(),
            'days_left' => $tenant->trial_ends_at 
                ? max(0, (int) floor(now()->diffInDays($tenant->trial_ends_at, false))) 
                : null,
            
            // Last sync
            'last_sync_at' => $tenant->last_sync_at,
        ];
    }

    public function loadChartData()
    {
        $tenant = $this->tenant;
        
        $dailyMessages = ChatMessage::where('tenant_id', $tenant->id)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Fill missing dates with 0
        $this->chartData = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $this->chartData[$date] = $dailyMessages[$date] ?? 0;
        }
    }

    public function loadFunnelData()
    {
        $tenant = $this->tenant;
        $startDate = now()->subDays(30);
        $tenantId = $tenant->id;
        
        // Get ALL merchant identifiers for fallback filtering (old records)
        $slug = $tenant->slug;
        $apiTokens = \App\Models\WidgetSettings::where('tenant_id', $tenantId)
            ->pluck('api_token')
            ->filter()
            ->toArray();
        $merchantIds = array_unique(array_filter(array_merge([$slug], $apiTokens)));
        
        // Check if tenant_id column exists
        $hasTenantIdColumn = Schema::hasColumn('chat_events', 'tenant_id');
        
        // Define funnel stages
        $stages = [
            'page_view' => ['label' => 'Відвідувачі', 'icon' => '👁️'],
            'chat_opened' => ['label' => 'Відкрили чат', 'icon' => '💬'],
            'message' => ['label' => 'Написали', 'icon' => '✍️'],
            'product_click' => ['label' => 'Клік на товар', 'icon' => '👆'],
            'add_to_cart' => ['label' => 'До кошика', 'icon' => '🛒'],
            'checkout_success' => ['label' => 'Замовлення', 'icon' => '✅'],
        ];
        
        $funnel = [];
        $prevCount = 0;
        
        foreach ($stages as $eventType => $stage) {
            try {
                $query = DB::table('chat_events')
                    ->where('event_type', $eventType)
                    ->where('created_at', '>=', $startDate);
                
                // Filter by tenant_id (new records) OR merchant_id (old records)
                if ($hasTenantIdColumn) {
                    $query->where(function($q) use ($tenantId, $merchantIds) {
                        $q->where('tenant_id', $tenantId);
                        if (!empty($merchantIds)) {
                            $q->orWhere(function($q2) use ($merchantIds) {
                                $q2->whereNull('tenant_id')
                                   ->whereIn('merchant_id', $merchantIds);
                            });
                        }
                    });
                } else {
                    // Fallback: only merchant_id
                    $query->whereIn('merchant_id', $merchantIds);
                }
                
                // For add_to_cart: only count chat-attributed (had_chat or from_chat)
                if ($eventType === 'add_to_cart') {
                    $query->where(function($q) {
                        $q->whereRaw("JSON_EXTRACT(metadata, '$.had_chat_conversation') = true")
                          ->orWhereRaw("JSON_EXTRACT(metadata, '$.product_from_chat') = true");
                    });
                }
                
                $count = $query->distinct('session_id')->count('session_id');
            } catch (\Throwable $e) {
                \Log::error('TenantDashboard loadFunnelData error', [
                    'stage' => $eventType,
                    'error' => $e->getMessage()
                ]);
                $count = 0;
            }
            
            $rate = $prevCount > 0 ? round(($count / $prevCount) * 100, 1) : 0;
            $dropoff = $prevCount > 0 ? round((($prevCount - $count) / $prevCount) * 100, 1) : 0;
            
            $funnel[] = [
                'stage' => $eventType,
                'label' => $stage['label'],
                'icon' => $stage['icon'],
                'count' => $count,
                'rate' => $rate,
                'dropoff' => $dropoff,
            ];
            
            $prevCount = $count ?: $prevCount;
        }
        
        $firstStage = $funnel[0]['count'] ?? 0;
        $lastStage = $funnel[count($funnel) - 1]['count'] ?? 0;
        $overallRate = $firstStage > 0 ? round(($lastStage / $firstStage) * 100, 2) : 0;
        
        $this->funnelData = [
            'stages' => $funnel,
            'overall_rate' => $overallRate,
        ];
    }

    public function setTab(string $tab)
    {
        $this->activeTab = $tab;
        $this->selectedChatId = null; // Reset selected chat when changing tabs
        $this->resetPage();
        
        // Load conversions data when switching to conversions tab
        if ($tab === 'conversions' && empty($this->chatAttributedConversions)) {
            $this->loadConversionsData();
        }
    }
    
    public function selectChat(string $sessionId)
    {
        $this->selectedChatId = $sessionId;
    }
    
    /**
     * Handle openChat event from embedded Analytics component
     */
    #[On('openChat')]
    public function openChatFromEvent(string $sessionId)
    {
        $this->activeTab = 'chats';
        $this->selectChat($sessionId);
    }
    
    public function closeChat()
    {
        $this->selectedChatId = null;
    }

    // Settings methods
    public function startEditingSettings()
    {
        $this->settingsName = $this->tenant->name;
        $this->settingsDomain = $this->tenant->domain ?? '';
        $this->settingsPlatform = $this->tenant->platform ?? '';
        $this->editingSettings = true;
    }

    public function saveSettings()
    {
        $this->validate([
            'settingsName' => 'required|string|max:255',
            'settingsDomain' => 'nullable|string|max:255',
            'settingsPlatform' => 'nullable|string|max:50',
        ], [
            'settingsName.required' => 'Назва магазину обовʼязкова',
        ]);

        $this->tenant->update([
            'name' => $this->settingsName,
            'domain' => $this->settingsDomain ?: null,
            'platform' => $this->settingsPlatform ?: null,
        ]);

        $this->editingSettings = false;
        session()->flash('settings-saved', 'Налаштування збережено');
    }

    public function cancelEditingSettings()
    {
        $this->editingSettings = false;
    }

    public function regenerateApiToken()
    {
        $this->tenant->update([
            'api_token' => \Illuminate\Support\Str::random(32),
        ]);
        
        session()->flash('token-regenerated', 'API токен оновлено');
    }

    public function getChatsProperty()
    {
        $query = ChatSession::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->withCount('messages')
            ->with(['messages' => fn($q) => $q->latest()->limit(1)])
            ->latest();

        if ($this->chatSearch) {
            $query->where(function($q) {
                $q->where('session_id', 'like', "%{$this->chatSearch}%")
                  ->orWhereHas('messages', fn($mq) => 
                      $mq->where('content', 'like', "%{$this->chatSearch}%")
                  );
            });
        }

        if ($this->chatStatus) {
            $query->where('status', $this->chatStatus);
        }

        return $query->paginate(15);
    }

    public function getProductsProperty()
    {
        return Product::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->where('in_stock', true)
            ->latest()
            ->limit(10)
            ->get();
    }

    public function getFeaturesProperty()
    {
        return $this->tenant->getFeaturesStatus();
    }

    public function getEmbedCodeProperty()
    {
        return $this->tenant->getEmbedCode();
    }

    public function copyEmbedCode()
    {
        $this->dispatch('copy-to-clipboard', code: $this->embedCode);
    }

    public function render()
    {
        return view('livewire.tenant-dashboard', [
            'tenant' => $this->tenant,
            'user' => Auth::user(),
            'chats' => $this->chats,
            'products' => $this->products,
            'features' => $this->features,
            'embedCode' => $this->embedCode,
            'funnelData' => $this->funnelData,
        ])->layout('layouts.app');
    }
}
