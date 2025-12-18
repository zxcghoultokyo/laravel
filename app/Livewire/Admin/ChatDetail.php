<?php

namespace App\Livewire\Admin;

use App\Models\ChatSession;
use Livewire\Component;

class ChatDetail extends Component
{
    public $sessionId;
    public $session;
    public $messages;

    public function mount($sessionId)
    {
        $this->sessionId = $sessionId;
        $this->loadSession();
    }

    public function loadSession()
    {
        $this->session = ChatSession::where('session_id', $this->sessionId)
            ->with('messages')
            ->firstOrFail();
        $this->messages = $this->session->messages()->orderBy('created_at')->get();
    }

    public function markAsResolved()
    {
        $this->session->update(['status' => 'closed']);
        $this->dispatch('session-updated');
        $this->loadSession();
    }

    public function flagSession()
    {
        $this->session->update(['status' => 'flagged']);
        $this->dispatch('session-updated');
        $this->loadSession();
    }

    public function copySessionSummary()
    {
        $summary = "Session: {$this->session->session_id}\n";
        $summary .= "Messages: {$this->session->messages_count}\n";
        $summary .= "Intent: {$this->session->last_intent}\n";
        $summary .= "Status: {$this->session->status}\n";
        $summary .= "Last: {$this->session->last_message_at}\n";

        $this->dispatch('clipboard-copy', text: $summary);
    }

    public function render()
    {
        return view('livewire.admin.chat-detail')->layout('admin.layout');
    }
}
