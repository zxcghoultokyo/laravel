<?php

namespace App\Livewire\Admin;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\CannedResponse;
use App\Services\Metrics\MetricsService;
use App\Events\OperatorMessage;
use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatDetail extends Component
{
    public $sessionId;
    public $session;
    public $messages;
    public $operatorMode = false;
    public $operatorMessage = '';
    public $activeSessionData = null;
    
    // Canned responses
    public $cannedResponses = [];
    public $showCannedResponses = false;
    public $cannedSearch = '';
    public $selectedCategory = '';

    public function mount($sessionId)
    {
        $this->sessionId = $sessionId;
        $this->loadSession();
        $this->loadCannedResponses();
    }

    public function loadSession()
    {
        // Try to find existing session - could be id or session_id string
        $this->session = ChatSession::where('id', $this->sessionId)
            ->orWhere('session_id', $this->sessionId)
            ->with('messages')
            ->first();
        
        // If session doesn't exist, try to find by chat_session_id in messages
        if (!$this->session) {
            $firstMessage = DB::table('chat_messages')
                ->where('chat_session_id', $this->sessionId)
                ->orderBy('created_at')
                ->first();
            
            if ($firstMessage) {
                // Find or create session
                $this->session = ChatSession::firstOrCreate(
                    ['id' => $firstMessage->chat_session_id],
                    [
                        'session_id' => (string) $firstMessage->chat_session_id,
                        'created_at' => $firstMessage->created_at,
                        'updated_at' => now(),
                    ]
                );
            } else {
                abort(404, 'Сесія не знайдена');
            }
        }
        
        // Load messages by chat_session_id only (session_id column doesn't exist)
        $this->messages = ChatMessage::where('chat_session_id', $this->session->id)
            ->orderBy('created_at')
            ->get();
        
        // Check if operator has taken over
        $this->activeSessionData = DB::table('active_chat_sessions')
            ->where('session_id', $this->session->session_id ?? $this->sessionId)
            ->first();
        $this->operatorMode = $this->activeSessionData && $this->activeSessionData->status === 'operator';
    }
    
    public function loadCannedResponses()
    {
        $query = CannedResponse::where('is_active', true);
        
        if ($this->cannedSearch) {
            $query->where(function($q) {
                $q->where('title', 'like', "%{$this->cannedSearch}%")
                  ->orWhere('content', 'like', "%{$this->cannedSearch}%")
                  ->orWhere('shortcut', 'like', "%{$this->cannedSearch}%");
            });
        }
        
        if ($this->selectedCategory) {
            $query->where('category', $this->selectedCategory);
        }
        
        $this->cannedResponses = $query->orderBy('usage_count', 'desc')->take(20)->get();
    }
    
    public function updatedCannedSearch()
    {
        $this->loadCannedResponses();
    }
    
    public function updatedSelectedCategory()
    {
        $this->loadCannedResponses();
    }
    
    public function toggleCannedResponses()
    {
        $this->showCannedResponses = !$this->showCannedResponses;
        if ($this->showCannedResponses) {
            $this->loadCannedResponses();
        }
    }
    
    public function useCannedResponse($responseId)
    {
        $response = CannedResponse::find($responseId);
        if ($response) {
            $this->operatorMessage = $response->content;
            $response->increment('usage_count');
            $this->showCannedResponses = false;
        }
    }
    
    public function appendCannedResponse($responseId)
    {
        $response = CannedResponse::find($responseId);
        if ($response) {
            $this->operatorMessage .= ($this->operatorMessage ? "\n\n" : '') . $response->content;
            $response->increment('usage_count');
        }
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
            'role' => 'operator',
            'content' => $this->operatorMessage,
            'meta' => [
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
