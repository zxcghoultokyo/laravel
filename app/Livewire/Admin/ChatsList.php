<?php

namespace App\Livewire\Admin;

use App\Models\ChatSession;
use Livewire\Component;
use Livewire\WithPagination;

class ChatsList extends Component
{
    use WithPagination;

    public $search = '';
    public $statusFilter = 'all';
    public $intentFilter = 'all';
    public $dateFilter = '7days';

    protected $queryString = ['search', 'statusFilter', 'intentFilter', 'dateFilter'];

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
        $session = ChatSession::where('session_id', $sessionId)->first();
        if ($session) {
            $session->update(['status' => 'closed']);
            $this->dispatch('session-updated');
        }
    }

    public function flagSession($sessionId)
    {
        $session = ChatSession::where('session_id', $sessionId)->first();
        if ($session) {
            $session->update(['status' => 'flagged']);
            $this->dispatch('session-updated');
        }
    }

    public function render()
    {
        $query = ChatSession::query()->with('messages');

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

        // Date filter
        if ($this->dateFilter === 'today') {
            $query->whereDate('last_message_at', today());
        } elseif ($this->dateFilter === '7days') {
            $query->where('last_message_at', '>=', now()->subDays(7));
        }

        $sessions = $query->orderByDesc('last_message_at')->paginate(20);

        return view('livewire.admin.chats-list', [
            'sessions' => $sessions,
        ])->layout('admin.layout');
    }
}
