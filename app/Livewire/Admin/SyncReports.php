<?php

namespace App\Livewire\Admin;

use App\Models\SyncLog;
use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Models\Order;
use App\Models\Category;
use App\Scopes\TenantScope;
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

        // Color stats (products with/without color)
        $withColor = Product::where('in_stock', true)
            ->whereNotNull('color')
            ->where('color', '!=', '')
            ->where('color', '!=', 'null')
            ->count();
        $withoutColor = $inStock - $withColor;

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
            'colors' => [
                'with_color' => $withColor,
                'without_color' => $withoutColor,
                'coverage_percent' => $inStock > 0 ? round(($withColor / $inStock) * 100, 1) : 0,
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

        // Bypass TenantScope for superadmin - show all sync logs
        $query = SyncLog::withoutGlobalScope(TenantScope::class)
            ->orderByDesc('started_at')
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
                'command' => 'horoshop:sync (all tenants)',
                'schedule' => 'Щодня о 03:00',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_HOROSHOP_PRODUCTS),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_HOROSHOP_PRODUCTS),
                'next_run' => 'Завтра о 03:00',
                'is_queue' => true,
            ],
            [
                'name' => '📦 Orders',
                'command' => 'orders:sync --days=3 --update-counts',
                'schedule' => 'Двічі на день о 08:00 та 20:00',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_ORDERS),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_ORDERS),
                'next_run' => 'Сьогодні/завтра',
                'is_queue' => false,
            ],
            [
                'name' => '🤖 AI Enrichment',
                'command' => 'products:build-ai-index --limit=50',
                'schedule' => 'Щодня о 04:00',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_AI_ENRICHMENT),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_AI_ENRICHMENT),
                'next_run' => 'Завтра о 04:00',
                'is_queue' => true,
            ],
            [
                'name' => '🔍 Meilisearch (async)',
                'command' => 'meili:reindex-products',
                'schedule' => 'Щодня о 05:00',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_MEILISEARCH),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_MEILISEARCH),
                'next_run' => 'Завтра о 05:00',
                'is_queue' => false,
            ],
            [
                'name' => '🔍 Meilisearch (sync)',
                'command' => 'meili:reindex-products-sync',
                'schedule' => 'Ручний запуск',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_MEILISEARCH),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_MEILISEARCH),
                'next_run' => '-',
                'is_queue' => false,
            ],
            [
                'name' => '📊 Orders Count',
                'command' => 'products:update-orders-count',
                'schedule' => 'Після синхронізації замовлень',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_STATS),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_STATS),
                'next_run' => '-',
                'is_queue' => false,
            ],
            [
                'name' => '🏷️ Brands',
                'command' => 'brands:sync',
                'schedule' => 'Щодня о 03:30',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_BRANDS),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_BRANDS),
                'next_run' => 'Завтра о 03:30',
                'is_queue' => false,
            ],
            [
                'name' => '🧬 Embeddings',
                'command' => 'products:generate-embeddings --limit=100',
                'schedule' => 'Щотижня',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_EMBEDDINGS),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_EMBEDDINGS),
                'next_run' => '-',
                'is_queue' => true,
            ],
            [
                'name' => '🎨 Визначення кольорів',
                'command' => 'colors:detect --limit=100',
                'schedule' => 'Щодня о 05:30',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_COLOR_DETECTION),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_COLOR_DETECTION),
                'next_run' => 'Завтра о 05:30',
                'is_queue' => true,
                'description' => 'Автоматично визначає кольори товарів з фото та опису',
            ],
            [
                'name' => '🎨 Синоніми кольорів',
                'command' => 'synonyms:colors',
                'schedule' => 'Ручний запуск',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_COLOR_SYNONYMS),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_COLOR_SYNONYMS),
                'next_run' => '-',
                'is_queue' => false,
                'description' => 'AI генерує синоніми для кольорів (чорний → black, blk, черный)',
            ],
            [
                'name' => '📂 Категорії (rebuild)',
                'command' => 'category:rebuild',
                'schedule' => 'Після імпорту товарів',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_CATEGORIES),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_CATEGORIES),
                'next_run' => '-',
                'is_queue' => false,
                'description' => 'Перебудовує дерево категорій з товарів',
            ],
            [
                'name' => '🏷️ Аліаси категорій (AI)',
                'command' => 'category:aliases',
                'schedule' => 'Ручний запуск',
                'last_run' => $this->getLastSyncTime(SyncLog::TYPE_CATEGORY_ALIASES),
                'last_info' => $this->getLastSyncInfo(SyncLog::TYPE_CATEGORY_ALIASES),
                'next_run' => '-',
                'is_queue' => true,
                'description' => 'AI генерує аліаси категорій (плитоноски → броник, plate carrier)',
            ],
        ];
    }

    private function getLastSyncTime(string $type): string
    {
        if (!Schema::hasTable('sync_logs')) {
            return 'Немає даних';
        }

        // Bypass TenantScope for sync logs - superadmin should see all syncs
        $last = SyncLog::withoutGlobalScope(TenantScope::class)
            ->where('sync_type', $type)
            ->where('status', SyncLog::STATUS_COMPLETED)
            ->orderByDesc('started_at')
            ->first();
            
        if (!$last) {
            return 'Ніколи';
        }

        return $last->started_at->format('d.m.Y H:i');
    }
    
    /**
     * Get last sync info with stats for a type.
     */
    private function getLastSyncInfo(string $type): array
    {
        if (!Schema::hasTable('sync_logs')) {
            return ['time' => 'Немає даних', 'stats' => null];
        }

        $last = SyncLog::withoutGlobalScope(TenantScope::class)
            ->where('sync_type', $type)
            ->orderByDesc('started_at')
            ->first();
            
        if (!$last) {
            return ['time' => 'Ніколи', 'stats' => null];
        }
        
        $stats = null;
        if ($last->status === SyncLog::STATUS_COMPLETED) {
            $stats = [
                'total' => $last->total_processed ?? 0,
                'created' => $last->created ?? 0,
                'updated' => $last->updated ?? 0,
                'failed' => $last->failed ?? 0,
                'duration' => $last->duration_seconds ? "{$last->duration_seconds}с" : null,
            ];
        }

        return [
            'time' => $last->started_at->format('d.m.Y H:i'),
            'status' => $last->status,
            'stats' => $stats,
            'error' => $last->status === SyncLog::STATUS_FAILED ? $last->error_message : null,
        ];
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
            'brands:sync' => SyncLog::TYPE_BRANDS,
            'products:update-orders-count' => SyncLog::TYPE_STATS,
            'products:generate-embeddings' => SyncLog::TYPE_EMBEDDINGS,
            'colors:detect' => SyncLog::TYPE_COLOR_DETECTION,
            'synonyms:colors' => SyncLog::TYPE_COLOR_SYNONYMS,
            'category:rebuild' => SyncLog::TYPE_CATEGORIES,
            'category:aliases' => SyncLog::TYPE_CATEGORY_ALIASES,
        ];
        
        $cmdName = explode(' ', $command)[0];
        return $map[$cmdName] ?? null;
    }

    /**
     * Commands that take too long for HTTP request - must run via queue
     */
    private function isLongRunningCommand(string $command): bool
    {
        $longRunning = [
            'horoshop:sync',
            'products:build-ai-index',
            'products:generate-embeddings',
            'colors:detect',
            'category:aliases', // AI-generated, can be slow
        ];
        
        $cmdName = explode(' ', $command)[0];
        return in_array($cmdName, $longRunning);
    }

    /**
     * Clean up stuck "running" syncs older than 1 hour
     */
    public function cleanupStuckSyncs()
    {
        if (!Schema::hasTable('sync_logs')) {
            return;
        }
        
        $stuck = SyncLog::where('status', SyncLog::STATUS_RUNNING)
            ->where('started_at', '<', now()->subHour())
            ->get();
        
        foreach ($stuck as $log) {
            $log->update([
                'status' => SyncLog::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => 'Timeout - marked as failed automatically',
            ]);
        }
        
        if ($stuck->count() > 0) {
            session()->flash('message', "🧹 Очищено {$stuck->count()} завислих синхронізацій.");
        } else {
            session()->flash('message', "✅ Немає завислих синхронізацій.");
        }
        
        $this->loadSyncHistory();
    }

    public function runSync(string $command)
    {
        // Determine sync type for logging
        $syncType = $this->commandToSyncType($command);
        
        // For long-running commands, dispatch to queue instead of running directly
        if ($this->isLongRunningCommand($command)) {
            return $this->dispatchToQueue($command, $syncType);
        }
        
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

    /**
     * Dispatch long-running command to queue
     */
    private function dispatchToQueue(string $command, ?string $syncType)
    {
        $cmdName = explode(' ', $command)[0];
        
        // Map commands to job classes with optional constructor arguments
        $jobMap = [
            'horoshop:sync' => ['class' => \App\Jobs\SyncHoroshopProductsJob::class, 'args' => [null, 200], 'all_tenants' => true], // tenantId=null, limit=200, sync all
            'products:build-ai-index' => ['class' => \App\Jobs\AnalyzeProductsWithAiJob::class, 'args' => [50]], // batchSize=50
            'products:generate-embeddings' => ['class' => \App\Jobs\GenerateProductEmbeddingsJob::class, 'args' => [50, 100]], // batchSize=50, limit=100
            'colors:detect' => ['class' => \App\Jobs\DetectProductColorsJob::class, 'args' => [100, null, true, false]], // batchSize=100, allTenants, analyzeImages, notDryRun
        ];
        
        $jobConfig = $jobMap[$cmdName] ?? null;
        
        if (!$jobConfig) {
            session()->flash('error', "❌ Команда '{$cmdName}' не підтримує фонове виконання.");
            return;
        }
        
        $jobClass = $jobConfig['class'];
        $args = $jobConfig['args'] ?? [];
        $allTenants = $jobConfig['all_tenants'] ?? false;
        
        try {
            // For commands that should run for all tenants, dispatch multiple jobs
            if ($allTenants) {
                $tenants = \App\Models\Tenant::whereNotNull('platform_credentials')
                    ->where('platform', 'horoshop')
                    ->get();
                
                $dispatched = 0;
                foreach ($tenants as $tenant) {
                    // Check if tenant has valid credentials
                    $creds = $tenant->platform_credentials;
                    if (empty($creds['domain']) || empty($creds['login'])) {
                        continue;
                    }
                    
                    $job = new $jobClass($tenant->id, $args[1] ?? 200);
                    dispatch($job)->onQueue('default');
                    $dispatched++;
                }
                
                if ($dispatched > 0) {
                    session()->flash('message', "🚀 Запущено синхронізацію для {$dispatched} магазинів. Перевірте статус через 1-5 хвилин.");
                } else {
                    session()->flash('error', "⚠️ Немає магазинів з налаштованими Horoshop credentials.");
                }
                
                \Log::info("SyncReports dispatched to queue for all tenants", ['command' => $command, 'tenants' => $dispatched]);
            } else {
                // Create job instance with arguments if any
                $job = empty($args) ? new $jobClass() : new $jobClass(...$args);
                
                // Dispatch to queue
                dispatch($job)->onQueue('default');
                
                session()->flash('message', "🚀 Команду '{$command}' запущено у фоновому режимі. Перевірте статус через 1-2 хвилини.");
                \Log::info("SyncReports dispatched to queue", ['command' => $command, 'job' => $jobClass]);
            }
        } catch (\Exception $e) {
            session()->flash('error', "❌ Помилка запуску: {$e->getMessage()}");
            \Log::error("SyncReports dispatch error", ['command' => $command, 'error' => $e->getMessage()]);
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
