<?php

namespace App\Livewire\Admin;

use App\Jobs\SyncHoroshopProductsJob;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tenant Details - SuperAdmin view for individual tenant management.
 */
class TenantDetails extends Component
{
    use WithPagination;

    public Tenant $tenant;

    public string $activeTab = 'overview';

    public string $syncLogFilter = '';

    public string $chatSearch = '';

    public string $chatStatus = '';

    public int $chatPerPage = 20;

    // Analytics
    public int $analyticsDays = 30;

    public array $funnelData = [];

    public array $usageChartData = [];

    protected $queryString = ['activeTab', 'chatSearch', 'chatStatus'];

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
        $this->loadAnalyticsData();
    }

    /**
     * Load analytics data (funnel + usage chart).
     */
    public function loadAnalyticsData()
    {
        $this->loadFunnelData();
        $this->loadUsageChartData();
    }

    /**
     * Set analytics period and reload data.
     */
    public function setAnalyticsDays(int $days)
    {
        $this->analyticsDays = $days;
        $this->loadAnalyticsData();
    }

    /**
     * Load funnel data for this tenant.
     */
    public function loadFunnelData()
    {
        $tenant = $this->tenant;
        $startDate = now()->subDays($this->analyticsDays)->startOfDay();
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

        // Pre-calculate checkout_success: only count orders from sessions
        // that had chat-attributed add_to_cart events (ensures checkout <= add_to_cart in funnel)
        $checkoutCountFromOrders = 0;
        if (Schema::hasTable('orders')) {
            try {
                // Get sessions with chat-attributed add_to_cart events
                $cartQuery = DB::table('chat_events')
                    ->where('event_type', 'add_to_cart')
                    ->where('created_at', '>=', $startDate);

                if ($hasTenantIdColumn) {
                    $cartQuery->where(function ($q) use ($tenantId, $merchantIds) {
                        $q->where('tenant_id', $tenantId);
                        if (! empty($merchantIds)) {
                            $q->orWhere(function ($q2) use ($merchantIds) {
                                $q2->whereNull('tenant_id')
                                    ->whereIn('merchant_id', $merchantIds);
                            });
                        }
                    });
                } else {
                    $cartQuery->whereIn('merchant_id', $merchantIds);
                }

                $cartSessionIds = $cartQuery->distinct()->pluck('session_id')->toArray();

                if (! empty($cartSessionIds)) {
                    $checkoutCountFromOrders = DB::table('orders')
                        ->where('created_at', '>=', $startDate)
                        ->where('had_chat', true)
                        ->whereIn('session_id', $cartSessionIds)
                        ->count();
                }
            } catch (\Throwable $e) {
                // Ignore - will fall back to chat_events
            }
        }

        // Define funnel stages
        $stages = [
            'page_view' => ['label' => 'Відвідувачі', 'icon' => '👁️', 'hint' => 'Унікальні сесії на сайті'],
            'chat_opened' => ['label' => 'Відкрили чат', 'icon' => '💬', 'hint' => 'Клікнули на віджет'],
            'message' => ['label' => 'Написали', 'icon' => '✍️', 'hint' => 'Надіслали повідомлення'],
            'product_click' => ['label' => 'Клік на товар', 'icon' => '👆', 'hint' => 'Клікнули на картку товару'],
            'add_to_cart' => ['label' => 'До кошика', 'icon' => '🛒', 'hint' => 'Додали товар в кошик'],
            'checkout_success' => ['label' => 'Замовлення', 'icon' => '✅', 'hint' => 'Замовлення з товарами з кошика чату'],
        ];

        $funnel = [];
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

                    // Filter by tenant_id (new records) OR merchant_id (old records)
                    if ($hasTenantIdColumn) {
                        $query->where(function ($q) use ($tenantId, $merchantIds) {
                            $q->where('tenant_id', $tenantId);
                            if (! empty($merchantIds)) {
                                $q->orWhere(function ($q2) use ($merchantIds) {
                                    $q2->whereNull('tenant_id')
                                        ->whereIn('merchant_id', $merchantIds);
                                });
                            }
                        });
                    } else {
                        $query->whereIn('merchant_id', $merchantIds);
                    }

                    // For add_to_cart: only count chat-attributed
                    if ($eventType === 'add_to_cart') {
                        $query->where(function ($q) {
                            $q->whereRaw("JSON_EXTRACT(metadata, '$.had_chat_conversation') = true")
                                ->orWhereRaw("JSON_EXTRACT(metadata, '$.product_from_chat') = true");
                        });
                        $count = $query->count();
                    } else {
                        $count = $query->distinct('session_id')->count('session_id');
                    }

                    // For checkout_success, also check orders if chat_events is 0
                    if ($eventType === 'checkout_success') {
                        $count = max($count, $checkoutCountFromOrders);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('TenantDetails loadFunnelData error', [
                    'tenant_id' => $tenantId,
                    'stage' => $eventType,
                    'error' => $e->getMessage(),
                ]);
                $count = 0;
            }

            $rate = $prevCount > 0 ? round(($count / $prevCount) * 100, 1) : 0;
            $dropoff = $prevCount > 0 ? round((($prevCount - $count) / $prevCount) * 100, 1) : 0;

            $funnel[] = [
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

        $firstStage = $funnel[0]['count'] ?? 0;
        $lastStage = $funnel[count($funnel) - 1]['count'] ?? 0;
        $overallRate = $firstStage > 0 ? round(($lastStage / $firstStage) * 100, 2) : 0;

        $this->funnelData = [
            'stages' => $funnel,
            'overall_rate' => $overallRate,
        ];
    }

    /**
     * Load usage chart data (messages per day).
     */
    public function loadUsageChartData()
    {
        $tenantId = $this->tenant->id;
        $startDate = now()->subDays($this->analyticsDays)->startOfDay();

        // Get daily message counts (bypass TenantScope)
        $dailyMessages = ChatMessage::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Get daily session counts (bypass TenantScope)
        $dailySessions = ChatSession::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Get daily AI responses (assistant messages only, bypass TenantScope)
        $dailyAiResponses = ChatMessage::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Build chart data for each day
        $chartData = [];
        for ($i = $this->analyticsDays - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $chartData[] = [
                'date' => now()->subDays($i)->format('d.m'),
                'messages' => $dailyMessages[$date] ?? 0,
                'sessions' => $dailySessions[$date] ?? 0,
                'ai_responses' => $dailyAiResponses[$date] ?? 0,
            ];
        }

        $this->usageChartData = $chartData;
    }

    /**
     * Get tenant statistics.
     * Note: Must bypass TenantScope since admin might have different tenant selected in switcher.
     */
    public function getStatsProperty(): array
    {
        $tenant = $this->tenant;

        return [
            'products_count' => Product::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'products_in_stock' => Product::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('in_stock', true)->count(),
            'categories_count' => Product::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->distinct('category_path')->count('category_path'),
            'sessions_count' => ChatSession::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'sessions_today' => ChatSession::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->whereDate('created_at', today())->count(),
            'messages_count' => ChatMessage::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->count(),
            'messages_today' => ChatMessage::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->whereDate('created_at', today())->count(),
            'last_sync' => $tenant->last_sync_at?->diffForHumans() ?? 'Ніколи',
            'sync_running' => $this->isSyncRunning(),
        ];
    }

    /**
     * Check if sync is currently running.
     */
    public function isSyncRunning(): bool
    {
        return Cache::get("sync_running_{$this->tenant->id}", false);
    }

    /**
     * Get sync logs for this tenant.
     */
    public function getSyncLogsProperty()
    {
        // Sync logs don't have tenant_id column, so we search by notes field
        // which contains tenant info when sync is run for specific tenant
        return SyncLog::where('notes', 'like', "%Tenant sync: {$this->tenant->name}%")
            ->orWhere('notes', 'like', "%tenant_id\":{$this->tenant->id}%")
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();
    }

    /**
     * Start sync for this tenant.
     */
    public function startSync()
    {
        if ($this->tenant->platform !== 'horoshop') {
            session()->flash('error', 'Синхронізація доступна тільки для Horoshop');

            return;
        }

        if (empty($this->tenant->platform_credentials)) {
            session()->flash('error', 'API credentials не налаштовані');

            return;
        }

        if ($this->isSyncRunning()) {
            session()->flash('warning', 'Синхронізація вже запущена');

            return;
        }

        // Set sync running flag
        Cache::put("sync_running_{$this->tenant->id}", true, 3600);

        // Dispatch sync job (use dispatchSync for immediate execution in onboarding)
        SyncHoroshopProductsJob::dispatch($this->tenant->id);

        session()->flash('success', 'Синхронізацію запущено');
        Log::info('Admin started sync for tenant', ['tenant_id' => $this->tenant->id]);
    }

    /**
     * Start sync synchronously (immediate, blocking).
     */
    public function startSyncNow()
    {
        if ($this->tenant->platform !== 'horoshop') {
            session()->flash('error', 'Синхронізація доступна тільки для Horoshop');

            return;
        }

        if (empty($this->tenant->platform_credentials)) {
            session()->flash('error', 'API credentials не налаштовані');

            return;
        }

        // Set sync running flag
        Cache::put("sync_running_{$this->tenant->id}", true, 3600);

        try {
            // Run sync immediately (blocking)
            SyncHoroshopProductsJob::dispatchSync($this->tenant->id);

            $this->tenant->refresh();
            session()->flash('success', 'Синхронізацію завершено!');
        } catch (\Throwable $e) {
            Cache::forget("sync_running_{$this->tenant->id}");
            session()->flash('error', 'Помилка: '.$e->getMessage());
            Log::error('Sync failed for tenant', [
                'tenant_id' => $this->tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel running sync.
     */
    public function cancelSync()
    {
        Cache::forget("sync_running_{$this->tenant->id}");
        session()->flash('success', 'Синхронізацію скасовано');
    }

    /**
     * Clear all products for this tenant.
     */
    public function clearProducts()
    {
        $count = Product::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenant->id)->count();
        Product::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenant->id)->delete();

        session()->flash('success', "Видалено {$count} товарів");
    }

    /**
     * Test API connection.
     */
    public function testConnection()
    {
        if (empty($this->tenant->platform_credentials)) {
            session()->flash('error', 'API credentials не налаштовані');

            return;
        }

        try {
            $credentials = $this->tenant->platform_credentials;
            $client = new \App\Services\Horoshop\HoroshopClient(
                $credentials['domain'],
                $credentials['login'],
                $credentials['password']
            );

            // Try to get first product
            $response = $client->request('catalog/export', [
                'limit' => 1,
            ]);

            $productsCount = count($response['products'] ?? []);
            session()->flash('success', 'Підключення успішне! Знайдено товарів в API: '.($response['total'] ?? $productsCount));
        } catch (\Throwable $e) {
            session()->flash('error', 'Помилка підключення: '.$e->getMessage());
        }
    }

    /**
     * Get recent chat sessions.
     */
    public function getRecentSessionsProperty()
    {
        return ChatSession::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->with(['messages' => fn ($q) => $q->orderBy('created_at', 'desc')->take(1)])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();
    }

    /**
     * Get paginated chat sessions for Chats tab.
     */
    public function getChatSessionsProperty()
    {
        $query = ChatSession::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->withCount('messages');

        // Search filter
        if ($this->chatSearch) {
            $query->where(function ($q) {
                $q->where('session_id', 'like', "%{$this->chatSearch}%")
                    ->orWhereHas('messages', function ($mq) {
                        $mq->where('content', 'like', "%{$this->chatSearch}%");
                    });
            });
        }

        // Status filter
        if ($this->chatStatus) {
            $query->where('status', $this->chatStatus);
        }

        return $query->orderBy('updated_at', 'desc')
            ->paginate($this->chatPerPage);
    }

    /**
     * Get chat events for a session.
     */
    public function getChatEventsCount(int $sessionId): int
    {
        $session = ChatSession::find($sessionId);
        if (! $session) {
            return 0;
        }

        return \Illuminate\Support\Facades\DB::table('chat_events')
            ->where('session_id', $session->session_id)
            ->count();
    }

    /**
     * Reset chat filters.
     */
    public function resetChatFilters()
    {
        $this->chatSearch = '';
        $this->chatStatus = '';
        $this->resetPage();
    }

    /**
     * Updated hook for search to reset pagination.
     */
    public function updatedChatSearch()
    {
        $this->resetPage();
    }

    public function updatedChatStatus()
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.admin.tenant-details', [
            'stats' => $this->stats,
            'syncLogs' => $this->syncLogs,
            'recentSessions' => $this->recentSessions,
            'chatSessions' => $this->chatSessions,
            'funnelData' => $this->funnelData,
            'usageChartData' => $this->usageChartData,
        ])->layout('admin.layout');
    }
}
