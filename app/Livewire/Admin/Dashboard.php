<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Services\Metrics\MetricsService;
use App\Services\Metrics\DashboardMetricsService;
use App\Services\Ai\CircuitBreaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Meilisearch\Client as MeiliClient;

class Dashboard extends Component
{
    public string $period = '7d';
    public array $kpis = [];
    public array $chartData = [];
    public array $topProducts = [];
    public array $recentChats = [];
    public array $liveStats = [];
    public array $health = [];
    public bool $loading = true;

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
        $this->topProducts = $metricsService->getTopProducts(5, $this->period);
        $this->recentChats = $metricsService->getRecentChats(8);
        $this->liveStats = $metricsService->getLiveStats();
        $this->health = $this->checkHealth();
        
        $this->loading = false;
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
