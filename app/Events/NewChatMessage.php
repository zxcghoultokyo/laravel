<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event for new chat messages (for admin dashboard).
 * 
 * Allows operators to see new messages in real-time.
 * Frontend subscribes to: admin.chats
 */
class NewChatMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $message,
        public string $type, // 'user' or 'ai'
        public array $meta = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('admin.chats'),
            new Channel('chat.' . $this->sessionId),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'message' => $this->message,
            'type' => $this->type,
            'meta' => $this->meta,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }
}
