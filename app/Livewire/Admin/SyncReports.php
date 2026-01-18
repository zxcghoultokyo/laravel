<?php

namespace App\Livewire\Admin;

use App\Models\SyncLog;
use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Models\Order;
use App\Models\Category;
use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class SyncReports extends Component
{
    public array $stats = [];
    public array $syncHistory = [];
    public array $scheduleInfo = [];
    public ?string $selectedType = null;
    public int $historyDays = 7;

    public function mount()
    {
        $this->loadStats();
        $this->loadSyncHistory();
        $this->loadScheduleInfo();
    }

    public function loadStats()
    {
        // Products stats
        $totalProducts = Product::count();
        $inStock = Product::where('in_stock', true)->count();
        $outOfStock = $totalProducts - $inStock;
        $newToday = Product::whereDate('created_at', today())->count();
        $updatedToday = Product::whereDate('updated_at', today())
            ->where('created_at', '!=', DB::raw('updated_at'))
            ->count();

        // AI Index stats
        $withAiIndex = 0;
        $withoutAiIndex = 0;
        $withEmbeddings = 0;
        if (Schema::hasTable('product_ai_index')) {
            $withAiIndex = ProductAiIndex::count();
            $withoutAiIndex = $totalProducts - $withAiIndex;
            $withEmbeddings = ProductAiIndex::whereNotNull('embedding')->count();
        }

        // Orders stats
        $totalOrders = 0;
        $ordersToday = 0;
        $ordersWeek = 0;
        $ordersChatAttributed = 0;
        if (Schema::hasTable('orders')) {
            $totalOrders = Order::count();
            $ordersToday = Order::whereDate('created_at', today())->count();
            $ordersWeek = Order::where('created_at', '>=', now()->subDays(7))->count();
            if (Schema::hasColumn('orders', 'had_chat')) {
                $ordersChatAttributed = Order::where('had_chat', true)->count();
            }
        }

        // Categories stats
        $totalCategories = 0;
        if (Schema::hasTable('categories')) {
            $totalCategories = Category::count();
        }

        // Meilisearch stats
        $meiliStats = $this->getMeiliStats();

        $this->stats = [
            'products' => [
                'total' => $totalProducts,
                'in_stock' => $inStock,
                'out_of_stock' => $outOfStock,
                'in_stock_percent' => $totalProducts > 0 ? round(($inStock / $totalProducts) * 100, 1) : 0,
                'new_today' => $newToday,
                'updated_today' => $updatedToday,
            ],
            'ai_index' => [
                'with_ai' => $withAiIndex,
                'without_ai' => $withoutAiIndex,
                'coverage_percent' => $totalProducts > 0 ? round(($withAiIndex / $totalProducts) * 100, 1) : 0,
                'with_embeddings' => $withEmbeddings,
                'embeddings_percent' => $withAiIndex > 0 ? round(($withEmbeddings / $withAiIndex) * 100, 1) : 0,
            ],
            'orders' => [
                'total' => $totalOrders,
                'today' => $ordersToday,
                'week' => $ordersWeek,
                'chat_attributed' => $ordersChatAttributed,
            ],
            'categories' => [
                'total' => $totalCategories,
            ],
            'meilisearch' => $meiliStats,
        ];
    }

    private function getMeiliStats(): array
    {
        try {
            $host = config('meilisearch.host', 'http://127.0.0.1:7700');
            $key = config('meilisearch.key');
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
            ])->timeout(5)->get($host . '/indexes/products/stats');

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'connected' => true,
                    'documents' => $data['numberOfDocuments'] ?? 0,
                    'is_indexing' => $data['isIndexing'] ?? false,
                    'field_distribution' => $data['fieldDistribution'] ?? [],
                ];
            }
        } catch (\Exception $e) {
            // Meilisearch not available
        }

        return [
            'connected' => false,
            'documents' => 0,
            'is_indexing' => false,
            'field_distribution' => [],
        ];
    }

    public function loadSyncHistory()
    {
        if (!Schema::hasTable('sync_logs')) {
            $this->syncHistory = [];
            return;
        }

        $query = SyncLog::orderByDesc('started_at')
            ->where('started_at', '>=', now()->subDays($this->historyDays));

        if ($this->selectedType) {
            $query->where('sync_type', $this->selectedType);
        }

        $this->syncHistory = $query->limit(50)->get()->toArray();
    }

    public function loadScheduleInfo()
    {
        $this->scheduleInfo = [
            [
                'name' => '🛒 Horoshop Products',
                'command' => 'horoshop:sync',
                'schedule' => 'Щодня о 03:00',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_HOROSHOP_PRODUCTS),
                'next_run' => 'Завтра о 03:00',
            ],
            [
                'name' => '📦 Orders',
                'command' => 'orders:sync --days=3 --update-counts',
                'schedule' => 'Двічі на день о 08:00 та 20:00',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_ORDERS),
                'next_run' => 'Сьогодні/завтра',
            ],
            [
                'name' => '🤖 AI Enrichment',
                'command' => 'products:build-ai-index --limit=50',
                'schedule' => 'Щодня о 04:00',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_AI_ENRICHMENT),
                'next_run' => 'Завтра о 04:00',
            ],
            [
                'name' => '🔍 Meilisearch (async)',
                'command' => 'meili:reindex-products',
                'schedule' => 'Щодня о 05:00',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_MEILISEARCH),
                'next_run' => 'Завтра о 05:00',
            ],
            [
                'name' => '🔍 Meilisearch (sync)',
                'command' => 'meili:reindex-products-sync',
                'schedule' => 'Ручний запуск',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_MEILISEARCH),
                'next_run' => '-',
            ],
            [
                'name' => '📊 Orders Count',
                'command' => 'products:update-orders-count',
                'schedule' => 'Після синхронізації замовлень',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_STATS),
                'next_run' => '-',
            ],
            [
                'name' => '🏷️ Brands',
                'command' => 'brands:sync',
                'schedule' => 'Щодня о 03:30',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_CATEGORIES),
                'next_run' => 'Завтра о 03:30',
            ],
            [
                'name' => '🧬 Embeddings',
                'command' => 'products:generate-embeddings --limit=100',
                'schedule' => 'Щотижня',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_EMBEDDINGS),
                'next_run' => '-',
            ],
        ];
    }

    private function getLastSyncTime(string $type): string
    {
        if (!Schema::hasTable('sync_logs')) {
            return 'Немає даних';
        }

        $last = SyncLog::lastSuccessfulForType($type);
        if (!$last) {
            return 'Ніколи';
        }

        return $last->started_at->format('d.m.Y H:i');
    }

    public function filterByType(?string $type)
    {
        $this->selectedType = $type;
        $this->loadSyncHistory();
    }

    public function setHistoryDays(int $days)
    {
        $this->historyDays = $days;
        $this->loadSyncHistory();
    }

    /**
     * Map command to sync type for logging
     */
    private function commandToSyncType(string $command): ?string
    {
        $map = [
            'horoshop:sync' => SyncLog::TYPE_HOROSHOP_PRODUCTS,
            'orders:sync' => SyncLog::TYPE_ORDERS,
            'products:build-ai-index' => SyncLog::TYPE_AI_ENRICHMENT,
            'meili:reindex-products' => SyncLog::TYPE_MEILISEARCH,
            'meili:reindex-products-sync' => SyncLog::TYPE_MEILISEARCH,
            'brands:sync' => SyncLog::TYPE_CATEGORIES,
            'products:update-orders-count' => SyncLog::TYPE_STATS,
            'products:generate-embeddings' => SyncLog::TYPE_EMBEDDINGS,
        ];
        
        $cmdName = explode(' ', $command)[0];
        return $map[$cmdName] ?? null;
    }

    public function runSync(string $command)
    {
        // Determine sync type for logging
        $syncType = $this->commandToSyncType($command);
        $syncLog = null;
        
        // Create sync log entry if we know the type
        if ($syncType && Schema::hasTable('sync_logs')) {
            $syncLog = SyncLog::create([
                'sync_type' => $syncType,
                'status' => SyncLog::STATUS_RUNNING,
                'started_at' => now(),
                'notes' => "Manual run: {$command}",
            ]);
        }
        
        $startTime = microtime(true);
        
        try {
            // Parse command and arguments
            $parts = explode(' ', $command);
            $cmd = array_shift($parts);
            $args = [];
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) {
                    $kv = explode('=', ltrim($part, '-'), 2);
                    $args['--' . $kv[0]] = $kv[1] ?? true;
                }
            }
            
            // Run directly (synchronously) for immediate feedback
            $exitCode = \Artisan::call($cmd, $args);
            $output = \Artisan::output();
            
            $duration = round(microtime(true) - $startTime, 2);
            
            // Update sync log
            if ($syncLog) {
                $syncLog->update([
                    'status' => $exitCode === 0 ? SyncLog::STATUS_COMPLETED : SyncLog::STATUS_FAILED,
                    'finished_at' => now(),
                    'duration_seconds' => $duration,
                    'notes' => "Manual run: {$command}\nOutput: " . substr($output, 0, 500),
                ]);
            }
            
            if ($exitCode === 0) {
                session()->flash('message', "✅ Команду '{$command}' виконано успішно за {$duration}с.");
            } else {
                session()->flash('error', "⚠️ Команда завершилась з кодом {$exitCode}.");
            }
            
            // Log output for debugging
            if (!empty(trim($output))) {
                \Log::info("SyncReports runSync '{$command}'", ['output' => $output, 'duration' => $duration]);
            }
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);
            
            // Update sync log with error
            if ($syncLog) {
                $syncLog->update([
                    'status' => SyncLog::STATUS_FAILED,
                    'finished_at' => now(),
                    'duration_seconds' => $duration,
                    'error_message' => $e->getMessage(),
                ]);
            }
            
            session()->flash('error', "❌ Помилка: {$e->getMessage()}");
            \Log::error("SyncReports runSync error", ['command' => $command, 'error' => $e->getMessage()]);
        }

        $this->loadStats();
        $this->loadSyncHistory();
    }

    public function render()
    {
        return view('livewire.admin.sync-reports')
            ->layout('admin.layout', ['title' => 'Звіти синхронізації']);
    }
}
