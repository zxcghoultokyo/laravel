<?php

namespace App\Livewire\Admin;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Services\Metrics\MetricsService;
use App\Events\OperatorMessage;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

class ChatDetail extends Component
{
    public $sessionId;
    public $session;
    public $messages;
    public $operatorMode = false;
    public $operatorMessage = '';
    public $activeSessionData = null;

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
        
        // Check if operator has taken over
        $this->activeSessionData = DB::table('active_chat_sessions')
            ->where('session_id', $this->sessionId)
            ->first();
        $this->operatorMode = $this->activeSessionData && $this->activeSessionData->status === 'operator';
    }

    public function takeOver()
    {
        app(MetricsService::class)->markOperatorTakeover($this->sessionId, auth()->id() ?? 1);
        $this->operatorMode = true;
        $this->loadSession();
        $this->dispatch('operator-took-over');
    }

    public function release()
    {
        app(MetricsService::class)->releaseSession($this->sessionId);
        $this->operatorMode = false;
        $this->loadSession();
        $this->dispatch('operator-released');
    }

    public function sendOperatorMessage()
    {
        if (empty(trim($this->operatorMessage))) {
            return;
        }

        // Save message to DB
        $message = ChatMessage::create([
            'chat_session_id' => $this->session->id,
            'role' => 'assistant',
            'content' => $this->operatorMessage,
            'meta' => [
                'operator' => true,
                'operator_id' => auth()->id() ?? 1,
            ],
        ]);

        // Update session
        $this->session->update([
            'last_message_at' => now(),
        ]);

        // Broadcast to widget (if WebSocket configured)
        try {
            broadcast(new OperatorMessage($this->sessionId, $this->operatorMessage))->toOthers();
        } catch (\Throwable $e) {
            // WebSocket not configured - that's ok
        }

        $this->operatorMessage = '';
        $this->loadSession();
        $this->dispatch('message-sent');
    }

    public function markAsResolved()
    {
        $this->session->update(['status' => 'closed']);
        $this->release(); // Also release from operator mode
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
