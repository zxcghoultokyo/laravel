<?php

namespace App\Livewire;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Product;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TenantDashboard extends Component
{
    use WithPagination;

    public string $activeTab = 'overview';
    public array $stats = [];
    public array $chartData = [];
    public array $funnelData = [];

    // Chat filters
    public string $chatSearch = '';
    public string $chatStatus = '';
    
    // Selected chat for inline view
    public ?string $selectedChatId = null;
    
    // Settings form
    public string $settingsName = '';
    public string $settingsDomain = '';
    public string $settingsPlatform = '';
    public bool $editingSettings = false;
    
    protected $queryString = ['activeTab', 'selectedChatId'];

    public function mount()
    {
        $this->loadStats();
        $this->loadChartData();
        $this->loadFunnelData();
    }

    public function getTenantProperty(): Tenant
    {
        return Auth::user()->tenant;
    }

    public function loadStats()
    {
        $tenant = $this->tenant;
        $startDate = now()->subDays(30);

        $this->stats = [
            // Usage
            'messages_used' => $tenant->messages_used,
            'messages_limit' => $tenant->messages_limit,
            'usage_percentage' => $tenant->getUsagePercentage(),
            
            // Sessions
            'total_sessions' => ChatSession::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)->count(),
            'sessions_30d' => ChatSession::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $startDate)
                ->count(),
            
            // Messages
            'total_messages' => ChatMessage::where('tenant_id', $tenant->id)->count(),
            'messages_30d' => ChatMessage::where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $startDate)
                ->count(),
            
            // Products
            'products_count' => $tenant->products()->withoutGlobalScope(TenantScope::class)->count(),
            'products_in_stock' => $tenant->products()->withoutGlobalScope(TenantScope::class)->where('in_stock', true)->count(),
            
            // Categories - count unique category paths
            'categories_count' => $tenant->products()
                ->withoutGlobalScope(TenantScope::class)
                ->whereNotNull('category_path')
                ->where('category_path', '!=', '')
                ->selectRaw('COUNT(DISTINCT category_path) as cnt')
                ->value('cnt') ?? 0,
            
            // Plan
            'plan' => $tenant->plan,
            'plan_label' => $tenant->getPlanLabel(),
            'trial_ends_at' => $tenant->trial_ends_at,
            'is_trial' => $tenant->isOnTrial(),
            'is_trial_expired' => $tenant->isTrialExpired(),
            'days_left' => $tenant->trial_ends_at 
                ? max(0, (int) floor(now()->diffInDays($tenant->trial_ends_at, false))) 
                : null,
            
            // Last sync
            'last_sync_at' => $tenant->last_sync_at,
        ];
    }

    public function loadChartData()
    {
        $tenant = $this->tenant;
        
        $dailyMessages = ChatMessage::where('tenant_id', $tenant->id)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Fill missing dates with 0
        $this->chartData = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $this->chartData[$date] = $dailyMessages[$date] ?? 0;
        }
    }

    public function loadFunnelData()
    {
        $tenant = $this->tenant;
        $startDate = now()->subDays(30);
        
        // Get merchant identifiers for filtering
        // Old widget used token as merchant_id, new uses slug
        $apiToken = $tenant->widgetSettings?->api_token;
        $slug = $tenant->slug;
        
        // Define funnel stages
        $stages = [
            'page_view' => ['label' => 'Відвідувачі', 'icon' => '👁️'],
            'chat_opened' => ['label' => 'Відкрили чат', 'icon' => '💬'],
            'message' => ['label' => 'Написали', 'icon' => '✍️'],
            'product_click' => ['label' => 'Клік на товар', 'icon' => '👆'],
            'add_to_cart' => ['label' => 'До кошика', 'icon' => '🛒'],
            'checkout_success' => ['label' => 'Замовлення', 'icon' => '✅'],
        ];
        
        $funnel = [];
        $prevCount = 0;
        
        foreach ($stages as $eventType => $stage) {
            try {
                // Count events - filter by merchant_id (slug OR token for backwards compatibility)
                $query = DB::table('chat_events')
                    ->where('event_type', $eventType)
                    ->where('created_at', '>=', $startDate);
                
                // Filter by merchant_id - check both slug and token for backward compatibility
                $query->where(function($q) use ($slug, $apiToken) {
                    $q->where('merchant_id', $slug);
                    if ($apiToken) {
                        $q->orWhere('merchant_id', $apiToken);
                    }
                });
                
                $count = $query->distinct('session_id')->count('session_id');
            } catch (\Throwable $e) {
                $count = 0;
            }
            
            $rate = $prevCount > 0 ? round(($count / $prevCount) * 100, 1) : 0;
            $dropoff = $prevCount > 0 ? round((($prevCount - $count) / $prevCount) * 100, 1) : 0;
            
            $funnel[] = [
                'stage' => $eventType,
                'label' => $stage['label'],
                'icon' => $stage['icon'],
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

    public function setTab(string $tab)
    {
        $this->activeTab = $tab;
        $this->selectedChatId = null; // Reset selected chat when changing tabs
        $this->resetPage();
    }
    
    public function selectChat(string $sessionId)
    {
        $this->selectedChatId = $sessionId;
    }
    
    public function closeChat()
    {
        $this->selectedChatId = null;
    }

    // Settings methods
    public function startEditingSettings()
    {
        $this->settingsName = $this->tenant->name;
        $this->settingsDomain = $this->tenant->domain ?? '';
        $this->settingsPlatform = $this->tenant->platform ?? '';
        $this->editingSettings = true;
    }

    public function saveSettings()
    {
        $this->validate([
            'settingsName' => 'required|string|max:255',
            'settingsDomain' => 'nullable|string|max:255',
            'settingsPlatform' => 'nullable|string|max:50',
        ], [
            'settingsName.required' => 'Назва магазину обовʼязкова',
        ]);

        $this->tenant->update([
            'name' => $this->settingsName,
            'domain' => $this->settingsDomain ?: null,
            'platform' => $this->settingsPlatform ?: null,
        ]);

        $this->editingSettings = false;
        session()->flash('settings-saved', 'Налаштування збережено');
    }

    public function cancelEditingSettings()
    {
        $this->editingSettings = false;
    }

    public function regenerateApiToken()
    {
        $this->tenant->update([
            'api_token' => \Illuminate\Support\Str::random(32),
        ]);
        
        session()->flash('token-regenerated', 'API токен оновлено');
    }

    public function getChatsProperty()
    {
        $query = ChatSession::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->withCount('messages')
            ->with(['messages' => fn($q) => $q->latest()->limit(1)])
            ->latest();

        if ($this->chatSearch) {
            $query->where(function($q) {
                $q->where('session_id', 'like', "%{$this->chatSearch}%")
                  ->orWhereHas('messages', fn($mq) => 
                      $mq->where('content', 'like', "%{$this->chatSearch}%")
                  );
            });
        }

        if ($this->chatStatus) {
            $query->where('status', $this->chatStatus);
        }

        return $query->paginate(15);
    }

    public function getProductsProperty()
    {
        return Product::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->where('in_stock', true)
            ->latest()
            ->limit(10)
            ->get();
    }

    public function getFeaturesProperty()
    {
        return $this->tenant->getFeaturesStatus();
    }

    public function getEmbedCodeProperty()
    {
        return $this->tenant->getEmbedCode();
    }

    public function copyEmbedCode()
    {
        $this->dispatch('copy-to-clipboard', code: $this->embedCode);
    }

    public function render()
    {
        return view('livewire.tenant-dashboard', [
            'tenant' => $this->tenant,
            'user' => Auth::user(),
            'chats' => $this->chats,
            'products' => $this->products,
            'features' => $this->features,
            'embedCode' => $this->embedCode,
            'funnelData' => $this->funnelData,
        ])->layout('layouts.app');
    }
}
