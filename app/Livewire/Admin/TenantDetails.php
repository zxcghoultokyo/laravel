<?php

namespace App\Livewire\Admin;

use App\Models\Tenant;
use App\Models\Product;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\SyncLog;
use App\Jobs\SyncHoroshopProductsJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    protected $queryString = ['activeTab'];

    public function mount(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * Get tenant statistics.
     */
    public function getStatsProperty(): array
    {
        $tenant = $this->tenant;
        
        return [
            'products_count' => Product::where('tenant_id', $tenant->id)->count(),
            'products_in_stock' => Product::where('tenant_id', $tenant->id)->where('in_stock', true)->count(),
            'categories_count' => Product::where('tenant_id', $tenant->id)->distinct('category_path')->count('category_path'),
            'sessions_count' => ChatSession::where('tenant_id', $tenant->id)->count(),
            'sessions_today' => ChatSession::where('tenant_id', $tenant->id)->whereDate('created_at', today())->count(),
            'messages_count' => ChatMessage::where('tenant_id', $tenant->id)->count(),
            'messages_today' => ChatMessage::where('tenant_id', $tenant->id)->whereDate('created_at', today())->count(),
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
        return SyncLog::where('context->tenant_id', $this->tenant->id)
            ->orWhere('context->tenant_name', $this->tenant->name)
            ->orWhere('details', 'like', "%Tenant sync: {$this->tenant->name}%")
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
            session()->flash('error', 'Помилка: ' . $e->getMessage());
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
        $count = Product::where('tenant_id', $this->tenant->id)->count();
        Product::where('tenant_id', $this->tenant->id)->delete();
        
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
            session()->flash('success', "Підключення успішне! Знайдено товарів в API: " . ($response['total'] ?? $productsCount));
        } catch (\Throwable $e) {
            session()->flash('error', 'Помилка підключення: ' . $e->getMessage());
        }
    }

    /**
     * Get recent chat sessions.
     */
    public function getRecentSessionsProperty()
    {
        return ChatSession::where('tenant_id', $this->tenant->id)
            ->with(['messages' => fn($q) => $q->orderBy('created_at', 'desc')->take(1)])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();
    }

    public function render()
    {
        return view('livewire.admin.tenant-details', [
            'stats' => $this->stats,
            'syncLogs' => $this->syncLogs,
            'recentSessions' => $this->recentSessions,
        ])->layout('admin.layout');
    }
}
