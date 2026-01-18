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

class TenantDashboard extends Component
{
    use WithPagination;

    public string $activeTab = 'overview';
    public array $stats = [];
    public array $chartData = [];

    // Chat filters
    public string $chatSearch = '';
    public string $chatStatus = '';
    
    protected $queryString = ['activeTab'];

    public function mount()
    {
        $this->loadStats();
        $this->loadChartData();
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
            
            // Categories
            'categories_count' => $tenant->products()
                ->withoutGlobalScope(TenantScope::class)
                ->select('category_path')
                ->distinct()
                ->count(),
            
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

    public function setTab(string $tab)
    {
        $this->activeTab = $tab;
        $this->resetPage();
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
        ])->layout('layouts.app');
    }
}
