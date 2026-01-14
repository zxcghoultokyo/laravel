<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\EnrichmentQualityService;

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
    
    // AI Index Quality
    public array $aiQuality = [];

    public function mount()
    {
        $this->checkTables();
        if ($this->tablesExist) {
            $this->loadStats();
        }
    }

    public function checkTables()
    {
        $this->tablesExist = Schema::hasTable('chat_events');
    }

    public function updatedDays()
    {
        $this->loadStats();
    }

    public function loadStats()
    {
        if (!$this->tablesExist) return;

        $startDate = now()->subDays($this->days)->startOfDay();

        // Basic stats
        $this->stats = $this->getBasicStats($startDate);
        
        // Outcomes distribution
        $this->outcomes = $this->getOutcomes($startDate);
        
        // Top clicked products
        $this->topProducts = $this->getTopProducts($startDate);
        
        // Top viewed products (for ranking)
        $this->topViewedProducts = $this->getTopViewedProducts($startDate);
        
        // Recent chat events only
        $this->recentChatEvents = $this->getRecentChatEvents();
        
        // Daily chart data
        $this->dailyChart = $this->getDailyChart($startDate);
        
        // AI Index Quality Score
        $this->loadAiQuality();
        
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

    private function getBasicStats($startDate): array
    {
        // Page views (visitors who saw the widget)
        $pageViews = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'page_view')
            ->count();
        
        // Unique page visitors
        $pageVisitors = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'page_view')
            ->whereNotNull('client_id')
            ->distinct('client_id')
            ->count('client_id');
        
        // Chat opened (users who opened the chat)
        $chatOpened = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'chat_opened')
            ->count();
        
        // Unique users who opened chat
        $chatOpenedUsers = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'chat_opened')
            ->whereNotNull('client_id')
            ->distinct('client_id')
            ->count('client_id');
        
        // Widget open rate (% of visitors who opened chat)
        $widgetOpenRate = $pageVisitors > 0 ? round(($chatOpenedUsers / $pageVisitors) * 100, 1) : 0;
        
        // Sessions (unique session_ids with session_start event)
        $sessions = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'session_start')
            ->count();

        // If no session_start events, count unique sessions from any event
        if ($sessions === 0) {
            $sessions = DB::table('chat_events')
                ->where('created_at', '>=', $startDate)
                ->distinct('session_id')
                ->count('session_id');
        }

        // Messages
        $messages = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'message')
            ->count();

        // Products shown
        $productsShown = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'product_shown')
            ->count();

        // Products clicked
        $productsClicked = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'product_click')
            ->count();

        // CTR
        $ctr = $productsShown > 0 ? round(($productsClicked / $productsShown) * 100, 1) : 0;

        // Conversions
        $conversions = [];
        if (Schema::hasTable('chat_conversions')) {
            $conversions = DB::table('chat_conversions')
                ->where('created_at', '>=', $startDate)
                ->selectRaw('conversion_type, COUNT(*) as count, SUM(order_total) as total')
                ->groupBy('conversion_type')
                ->get()
                ->keyBy('conversion_type')
                ->toArray();
        }

        // Unique users (client_id)
        $uniqueUsers = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('client_id')
            ->distinct('client_id')
            ->count('client_id');

        // Avg messages per session
        $avgMessages = $sessions > 0 ? round($messages / $sessions, 1) : 0;

        return [
            'page_views' => $pageViews,
            'page_visitors' => $pageVisitors,
            'chat_opened' => $chatOpened,
            'chat_opened_users' => $chatOpenedUsers,
            'widget_open_rate' => $widgetOpenRate,
            'sessions' => $sessions,
            'unique_users' => $uniqueUsers,
            'messages' => $messages,
            'avg_messages' => $avgMessages,
            'products_shown' => $productsShown,
            'products_clicked' => $productsClicked,
            'ctr' => $ctr,
            'add_to_cart' => $conversions['add_to_cart']->count ?? 0,
            'purchases' => $conversions['purchase']->count ?? 0,
            'revenue' => $conversions['purchase']->total ?? 0,
            'leads' => $conversions['lead']->count ?? 0,
        ];
    }

    private function getOutcomes($startDate): array
    {
        if (!Schema::hasTable('chat_session_outcomes')) {
            return [];
        }

        return DB::table('chat_session_outcomes')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('outcome, outcome_category, COUNT(*) as count')
            ->groupBy('outcome', 'outcome_category')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }

    private function getTopProducts($startDate): array
    {
        $productClicks = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'product_click')
            ->whereNotNull('product_id')
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
        $products = DB::table('products')
            ->whereIn('id', $productIds)
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
    }
    
    private function getTopViewedProducts($startDate): array
    {
        $productViews = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->where('event_type', 'product_shown')
            ->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as views')
            ->groupBy('product_id')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        if ($productViews->isEmpty()) {
            return [];
        }

        $productIds = $productViews->pluck('product_id')->toArray();
        $products = DB::table('products')
            ->whereIn('id', $productIds)
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
        return DB::table('chat_events')
            ->whereIn('event_type', ['message', 'chat_opened', 'chat_closed', 'session_start', 'quick_action_click'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['event_type', 'session_id', 'created_at', 'message_type'])
            ->toArray();
    }

    private function getDailyChart($startDate): array
    {
        $daily = DB::table('chat_events')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(DISTINCT session_id) as sessions, COUNT(*) as events')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return $daily->map(fn($row) => [
            'date' => $row->date,
            'sessions' => $row->sessions,
            'events' => $row->events,
        ])->toArray();
    }

    public function render()
    {
        return view('livewire.admin.analytics')->layout('admin.layout');
    }
}
