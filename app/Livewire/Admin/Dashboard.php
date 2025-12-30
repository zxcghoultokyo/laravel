<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Services\Metrics\MetricsService;
use App\Services\Ai\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Meilisearch\Client as MeiliClient;

class Dashboard extends Component
{
    public array $metrics = [];
    public array $health = [];
    public array $activeSessions = [];
    public bool $loading = true;

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $this->loading = true;
        $this->metrics = app(MetricsService::class)->getDashboardMetrics();
        $this->health = $this->checkHealth();
        $this->activeSessions = app(MetricsService::class)->getActiveSessions(10);
        $this->loading = false;
    }

    public function refreshData()
    {
        Cache::forget('dashboard_metrics');
        $this->loadData();
        $this->dispatch('data-refreshed');
    }

    public function resetCircuitBreaker(string $service)
    {
        app(CircuitBreaker::class)->reset($service);
        Cache::forget('dashboard_metrics');
        $this->loadData();
        $this->dispatch('circuit-reset', service: $service);
    }

    private function checkHealth(): array
    {
        $health = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'meilisearch' => $this->checkMeilisearch(),
            'openai' => $this->checkOpenAI(),
        ];

        $health['overall'] = collect($health)->every(fn($s) => $s['status'] === 'ok') ? 'healthy' : 'degraded';

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

    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . time();
            Cache::put($key, 'ok', 5);
            $value = Cache::get($key);
            Cache::forget($key);
            return ['status' => $value === 'ok' ? 'ok' : 'error'];
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
