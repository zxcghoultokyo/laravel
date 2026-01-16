<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Services\Metrics\MetricsService;
use App\Services\Metrics\DashboardMetricsService;
use App\Services\Ai\CircuitBreaker;
use App\Services\Ai\EnrichmentQualityService;
use App\Services\Analytics\ABTestingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Meilisearch\Client as MeiliClient;

class Dashboard extends Component
{
    public string $period = '7d';
    public array $kpis = [];
    public array $chartData = [];
    public array $funnelData = [];
    public array $topProducts = [];
    public array $recentChats = [];
    public array $liveStats = [];
    public array $health = [];
    public array $aiQuality = [];
    public array $abTestStats = [];
    public bool $loading = true;
    public bool $showAdvanced = false;

    protected $queryString = ['period'];

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $this->loading = true;
        
        $metricsService = app(DashboardMetricsService::class);
        
        $this->kpis = $metricsService->getKPIs($this->period);
        $this->chartData = $metricsService->getChartData($this->period);
        $this->funnelData = $metricsService->getFunnelData($this->period);
        $this->topProducts = $metricsService->getTopProducts(5, $this->period);
        $this->recentChats = $metricsService->getRecentChats(8);
        $this->liveStats = $metricsService->getLiveStats();
        $this->health = $this->checkHealth();
        
        // Load AI Quality and A/B Testing stats
        $this->loadAiQuality();
        $this->loadABTestStats();
        
        $this->loading = false;
    }
    
    public function toggleAdvanced()
    {
        $this->showAdvanced = !$this->showAdvanced;
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

    public function setPeriod(string $period)
    {
        $this->period = $period;
        $this->clearCaches();
        $this->loadData();
    }

    public function refreshData()
    {
        $this->clearCaches();
        $this->loadData();
        $this->dispatch('data-refreshed');
    }

    private function clearCaches()
    {
        Cache::forget("dashboard_kpis:{$this->period}");
        Cache::forget("dashboard_chart:{$this->period}");
        Cache::forget("dashboard_funnel:{$this->period}");
        Cache::forget("dashboard_top_products:{$this->period}:5");
        Cache::forget("dashboard_recent_chats:8");
    }

    private function checkHealth(): array
    {
        $health = [
            'database' => $this->checkDatabase(),
            'meilisearch' => $this->checkMeilisearch(),
            'openai' => $this->checkOpenAI(),
        ];

        $health['overall'] = collect($health)->every(fn($s) => in_array($s['status'], ['ok', 'disabled'])) ? 'healthy' : 'degraded';

        return $health;
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);
            return ['status' => 'ok', 'latency_ms' => $latency];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function checkMeilisearch(): array
    {
        if (!config('meilisearch.enabled', true)) {
            return ['status' => 'disabled'];
        }

        try {
            $start = microtime(true);
            $client = new MeiliClient(
                config('meilisearch.host'),
                config('meilisearch.key')
            );
            $health = $client->health();
            $latency = round((microtime(true) - $start) * 1000, 2);

            $index = $client->index(config('meilisearch.index', 'products'));
            $stats = $index->stats();

            return [
                'status' => 'ok',
                'latency_ms' => $latency,
                'documents' => $stats['numberOfDocuments'] ?? 0,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function checkOpenAI(): array
    {
        $circuitBreaker = app(CircuitBreaker::class);
        
        if ($circuitBreaker->isOpen('openai')) {
            return [
                'status' => 'circuit_open',
                'circuit' => $circuitBreaker->getState('openai'),
            ];
        }

        return [
            'status' => 'ok',
            'circuit' => $circuitBreaker->getState('openai'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.dashboard')->layout('admin.layout');
    }
}
