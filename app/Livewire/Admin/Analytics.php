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
    
    // AI Index Quality
    public array $aiQuality = [];
    
    // A/B Testing
    public array $abTestStats = [];
    
    // Embedded mode for tenant dashboard
    public bool $embedded = false;
    
    // Tenant ID for filtering (when embedded)
    public ?int $tenantId = null;

    public function mount()
    {
        // If embedded, get tenant from authenticated user
        if ($this->embedded) {
            $this->tenantId = auth()->user()?->tenant_id;
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
        // Try chat_events first
        try {
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
        } catch (\Throwable $e) {
            return [];
        }
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
        $events = DB::table('chat_events')
            ->whereIn('event_type', ['message', 'chat_opened', 'chat_closed', 'session_start', 'quick_action_click'])
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
        // Use chat_messages as fallback
        return DB::table('chat_messages')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['role as event_type', 'session_id', 'created_at'])
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
