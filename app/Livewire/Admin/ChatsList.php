<?php

namespace App\Livewire\Admin;

use App\Models\ChatSession;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Log;

class ChatsList extends Component
{
    use WithPagination;

    public $search = '';
    public $statusFilter = 'all';
    public $intentFilter = 'all';
    public $dateFilter = 'all';
    public $escalationFilter = 'all'; // new: filter by needs_human

    protected $queryString = ['search', 'statusFilter', 'intentFilter', 'dateFilter', 'escalationFilter'];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function markAsClosed($sessionId)
    {
        $session = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('session_id', $sessionId)->first();
        if ($session) {
            $session->update(['status' => 'closed']);
            $this->dispatch('session-updated');
        }
    }

    public function flagSession($sessionId)
    {
        $session = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('session_id', $sessionId)->first();
        if ($session) {
            $session->update(['status' => 'flagged']);
            $this->dispatch('session-updated');
        }
    }

    public function render()
    {
        $user = auth()->user();
        
        // Bypass TenantScope but filter by tenant_id for non-SuperAdmins
        $query = ChatSession::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->with('messages');

        // Non-SuperAdmin users only see their tenant's chats
        if ($user && !$user->isSuperAdmin()) {
            $query->where('tenant_id', $user->tenant_id);
        }

        // Search
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('session_id', 'like', '%' . $this->search . '%')
                  ->orWhere('last_user_query', 'like', '%' . $this->search . '%');
            });
        }

        // Status filter
        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        // Intent filter
        if ($this->intentFilter !== 'all') {
            $query->where('last_intent', $this->intentFilter);
        }

        // Escalation filter (needs_human)
        if ($this->escalationFilter === 'escalated') {
            $query->where('needs_human', true);
        } elseif ($this->escalationFilter === 'not_escalated') {
            $query->where(function ($q) {
                $q->where('needs_human', false)->orWhereNull('needs_human');
            });
        }

        // Date filter
        if ($this->dateFilter === 'today') {
            $query->where(function ($q) {
                $q->whereDate('last_message_at', today())
                  ->orWhere(function ($sq) {
                      $sq->whereNull('last_message_at')
                         ->whereDate('created_at', today());
                  });
            });
        } elseif ($this->dateFilter === '7days') {
            $query->where(function ($q) {
                $q->where('last_message_at', '>=', now()->subDays(7))
                  ->orWhere(function ($sq) {
                      $sq->whereNull('last_message_at')
                         ->where('created_at', '>=', now()->subDays(7));
                  });
            });
        }

        $sessions = $query->orderByDesc('last_message_at')->orderByDesc('created_at')->paginate(20);

        Log::info('ChatsList render', [
            'total_sessions' => ChatSession::count(),
            'filtered_sessions' => $sessions->total(),
            'filters' => [
                'search' => $this->search,
                'status' => $this->statusFilter,
                'intent' => $this->intentFilter,
                'date' => $this->dateFilter,
            ],
        ]);

        return view('livewire.admin.chats-list', [
            'sessions' => $sessions,
        ])->layout('admin.layout');
    }
}
