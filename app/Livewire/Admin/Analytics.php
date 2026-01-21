<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\EnrichmentQualityService;
use App\Services\Analytics\ABTestingService;
use App\Services\Metrics\ChatStatsService;

class Analytics extends Component
{
    public int $days = 7;
    public array $stats = [];
    public array $outcomes = [];
    public array $topProducts = [];
    public array $topViewedProducts = [];
    public array $dailyChart = [];
    public array $recentChatEvents = [];
    public bool $tablesExist = false;
    public ?string $lastUpdated = null;
    
    // Funnel data
    public array $funnel = [];
    
    // AI Index Quality
    public array $aiQuality = [];
    
    // A/B Testing
    public array $abTestStats = [];
    
    // Embedded mode for tenant dashboard
    public bool $embedded = false;
    
    // Tenant context
    public ?int $tenantId = null;
    public ?string $merchantId = null;

    public function mount()
    {
        // For embedded mode, get tenant directly from authenticated user
        // This is more reliable than TenantContext for Livewire components
        if ($this->embedded) {
            $user = auth()->user();
            if ($user && $user->tenant_id) {
                $this->tenantId = $user->tenant_id;
                // Get merchant_id (slug) from tenant
                $tenant = $user->tenant;
                $this->merchantId = $tenant?->slug;
            }
        }
        
        $this->checkTables();
        // Always try to load stats - ChatStatsService handles missing tables
        $this->loadStats();
    }

    public function checkTables()
    {
        // Check for chat_messages as primary source (not chat_events)
        $this->tablesExist = Schema::hasTable('chat_messages');
    }

    public function updatedDays()
    {
        $this->loadStats();
    }

    public function loadStats()
    {
        $startDate = now()->subDays($this->days)->startOfDay();

        // Use unified ChatStatsService for basic stats
        $chatStatsService = app(ChatStatsService::class);
        $this->stats = $chatStatsService->getBasicStats($startDate, null, $this->tenantId);
        
        // Outcomes distribution
        $this->outcomes = $this->getOutcomes($startDate);
        
        // Top clicked products
        $this->topProducts = $this->getTopProducts($startDate);
        
        // Top viewed products (for ranking)
        $this->topViewedProducts = $this->getTopViewedProducts($startDate);
        
        // Funnel data
        $this->loadFunnel($startDate);
        
        // Recent chat events only
        $this->recentChatEvents = $this->getRecentChatEvents();
        
        // Daily chart data - from ChatStatsService
        $this->dailyChart = $chatStatsService->getDailyChart($startDate, null, $this->tenantId);
        
        // AI Index Quality Score (only for superadmin, not embedded)
        if (!$this->embedded) {
            $this->loadAiQuality();
            $this->loadABTestStats();
        }
        
        // Update timestamp
        $this->lastUpdated = now()->format('H:i:s');
    }
    
    private function loadAiQuality(): void
    {
        try {
            $service = app(EnrichmentQualityService::class);
            $quality = $service->getOverallScore();
            $recommendations = $service->getRecommendations();
            
            $this->aiQuality = [
                'score' => $quality['score'],
                'grade' => $quality['grade'],
                'coverage' => $quality['stats']['coverage_percent'] ?? 0,
                'slang_coverage' => $quality['stats']['slang_coverage_percent'] ?? 0,
                'type_coverage' => $quality['stats']['type_coverage_percent'] ?? 0,
                'avg_slang' => $quality['stats']['avg_slang_count'] ?? 0,
                'total_products' => $quality['stats']['total_products'] ?? 0,
                'total_indexed' => $quality['stats']['total_ai_index'] ?? 0,
                'recommendations_count' => count($recommendations),
                'high_priority_issues' => count(array_filter($recommendations, fn($r) => $r['priority'] === 'high')),
            ];
        } catch (\Throwable $e) {
            $this->aiQuality = [
                'score' => 0,
                'grade' => 'N/A',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    private function loadABTestStats(): void
    {
        try {
            $service = app(ABTestingService::class);
            $stats = $service->getStats();
            
            $this->abTestStats = [
                'experiment' => $stats['experiment'] ?? 'search_ai_features',
                'name' => $stats['name'] ?? 'AI Search Features',
                'enabled' => $stats['enabled'] ?? false,
                'control' => $stats['variants']['control'] ?? [],
                'treatment' => $stats['variants']['treatment'] ?? [],
                'comparison' => $stats['comparison'] ?? [],
                'has_data' => ($stats['variants']['control']['total_searches'] ?? 0) > 0 ||
                              ($stats['variants']['treatment']['total_searches'] ?? 0) > 0,
            ];
        } catch (\Throwable $e) {
            $this->abTestStats = [
                'error' => $e->getMessage(),
                'has_data' => false,
            ];
        }
    }

    private function getOutcomes($startDate): array
    {
        if (!Schema::hasTable('chat_session_outcomes')) {
            return [];
        }

        $query = DB::table('chat_session_outcomes')
            ->where('created_at', '>=', $startDate);
        
        // Filter by merchant_id (tenant slug) for legacy analytics tables
        if ($this->merchantId && Schema::hasColumn('chat_session_outcomes', 'merchant_id')) {
            $query->where('merchant_id', $this->merchantId);
        }
        
        return $query
            ->selectRaw('outcome, outcome_category, COUNT(*) as count')
            ->groupBy('outcome', 'outcome_category')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }
    
    private function loadFunnel($startDate): void
    {
        $stages = [
            'page_view' => ['label' => 'Відвідувачі', 'icon' => '👁️', 'hint' => 'Відкрили сторінку з віджетом'],
            'chat_opened' => ['label' => 'Відкрили чат', 'icon' => '💬', 'hint' => 'Натиснули на іконку чату'],
            'message' => ['label' => 'Написали', 'icon' => '✍️', 'hint' => 'Надіслали повідомлення'],
            'product_click' => ['label' => 'Клік на товар', 'icon' => '👆', 'hint' => 'Клікнули на картку товару'],
            'add_to_cart' => ['label' => 'До кошика', 'icon' => '🛒', 'hint' => 'Додали товар у кошик'],
            'checkout_success' => ['label' => 'Замовлення', 'icon' => '✅', 'hint' => 'Оформили замовлення'],
        ];
        
        // Pre-calculate checkout_success from orders table (more reliable)
        $checkoutCountFromOrders = 0;
        if (Schema::hasTable('orders') && $this->tenantId) {
            try {
                $tenantSessionIds = DB::table('chat_sessions')
                    ->where('tenant_id', $this->tenantId)
                    ->pluck('session_id')
                    ->toArray();
                
                if (!empty($tenantSessionIds)) {
                    $checkoutCountFromOrders = DB::table('orders')
                        ->where('created_at', '>=', $startDate)
                        ->where('had_chat', true)
                        ->whereIn('session_id', $tenantSessionIds)
                        ->count();
                }
            } catch (\Throwable $e) {
                // Ignore
            }
        }
        
        $this->funnel = [];
        $prevCount = 0;
        
        foreach ($stages as $eventType => $stage) {
            try {
                // For checkout_success, prefer orders table count
                if ($eventType === 'checkout_success' && $checkoutCountFromOrders > 0) {
                    $count = $checkoutCountFromOrders;
                } else {
                    $query = DB::table('chat_events')
                        ->where('event_type', $eventType)
                        ->where('created_at', '>=', $startDate);
                    
                    // Filter by merchant_id for tenant isolation
                    if ($this->merchantId && Schema::hasColumn('chat_events', 'merchant_id')) {
                        $query->where('merchant_id', $this->merchantId);
                    }
                    
                    $count = $query->distinct('session_id')->count('session_id');
                    
                    // For checkout_success, also check orders table
                    if ($eventType === 'checkout_success') {
                        $count = max($count, $checkoutCountFromOrders);
                    }
                }
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

    private function getTopProducts($startDate): array
    {
        // Try chat_events first
        try {
            $query = DB::table('chat_events')
                ->where('created_at', '>=', $startDate)
                ->where('event_type', 'product_click')
                ->whereNotNull('product_id');
            
            // Filter by merchant_id (tenant slug) for legacy analytics tables
            if ($this->merchantId && Schema::hasColumn('chat_events', 'merchant_id')) {
                $query->where('merchant_id', $this->merchantId);
            }
            
            $productClicks = $query
                ->selectRaw('product_id, COUNT(*) as clicks')
                ->groupBy('product_id')
                ->orderByDesc('clicks')
                ->limit(10)
                ->get();

            if ($productClicks->isEmpty()) {
                return [];
            }

            // Get product titles
            $productIds = $productClicks->pluck('product_id')->toArray();
            $productsQuery = DB::table('products')
                ->whereIn('id', $productIds);
            
            // Filter products by tenant_id (new tables use integer ID)
            if ($this->tenantId) {
                $productsQuery->where('tenant_id', $this->tenantId);
            }
            
            $products = $productsQuery
                ->get(['id', 'title', 'price', 'article'])
                ->keyBy('id');

            return $productClicks->map(function ($row) use ($products) {
                $product = $products->get($row->product_id);
                return [
                    'id' => $row->product_id,
                    'title' => $product?->title ?? 'Unknown',
                    'price' => $product?->price ?? 0,
                    'article' => $product?->article ?? '',
                    'clicks' => $row->clicks,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    private function getTopViewedProducts($startDate): array
    {
        $query = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'product_shown')
            ->whereNotNull('product_id');
        
        // Filter by merchant_id (tenant slug) for legacy analytics tables
        if ($this->merchantId && Schema::hasColumn('chat_events', 'merchant_id')) {
            $query->where('merchant_id', $this->merchantId);
        }
        
        $productViews = $query
            ->selectRaw('product_id, COUNT(*) as views')
            ->groupBy('product_id')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        if ($productViews->isEmpty()) {
            return [];
        }

        $productIds = $productViews->pluck('product_id')->toArray();
        $productsQuery = DB::table('products')
            ->whereIn('id', $productIds);
        
        // Filter products by tenant_id (new tables use integer ID)
        if ($this->tenantId) {
            $productsQuery->where('tenant_id', $this->tenantId);
        }
        
        $products = $productsQuery
            ->get(['id', 'title', 'price', 'article'])
            ->keyBy('id');

        return $productViews->map(function ($row) use ($products) {
            $product = $products->get($row->product_id);
            return [
                'id' => $row->product_id,
                'title' => $product?->title ?? 'Unknown',
                'price' => $product?->price ?? 0,
                'article' => $product?->article ?? '',
                'views' => $row->views,
            ];
        })->toArray();
    }
    
    private function getRecentChatEvents(): array
    {
        $query = DB::table('chat_events')
            ->whereIn('event_type', ['message', 'chat_opened', 'chat_closed', 'session_start', 'quick_action_click']);
        
        // Filter by merchant_id (tenant slug) for legacy analytics tables
        if ($this->merchantId && Schema::hasColumn('chat_events', 'merchant_id')) {
            $query->where('merchant_id', $this->merchantId);
        }
        
        $events = $query
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['event_type', 'session_id', 'created_at', 'message_type'])
            ->toArray();
        
        // Fallback to chat_messages if no events
        if (empty($events)) {
            return $this->getRecentChatEventsFallback();
        }
        
        return $events;
    }
    
    private function getRecentChatEventsFallback(): array
    {
        // Use chat_messages as fallback (uses tenant_id, not merchant_id)
        // Join with chat_sessions to get the public session_id string
        $query = DB::table('chat_messages')
            ->join('chat_sessions', 'chat_messages.chat_session_id', '=', 'chat_sessions.id')
            ->select([
                'chat_messages.role as event_type',
                'chat_sessions.session_id as session_id',  // Use public session_id, not internal chat_session_id
                'chat_messages.created_at'
            ]);
        
        // Filter by tenant_id (new tables use integer ID)
        if ($this->tenantId) {
            $query->where('chat_messages.tenant_id', $this->tenantId);
        }
        
        return $query
            ->orderByDesc('chat_messages.created_at')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                $row->event_type = $row->event_type === 'user' ? 'message' : 'assistant_reply';
                return $row;
            })
            ->toArray();
    }

    public function render()
    {
        $view = view('livewire.admin.analytics');
        
        if ($this->embedded) {
            return $view;
        }
        
        return $view->layout('admin.layout');
    }
}
