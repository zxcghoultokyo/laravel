<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event for operator messages in live chat.
 * 
 * Broadcasts to the chat widget via WebSocket/Pusher.
 * Frontend subscribes to: chat.{sessionId}
 */
class OperatorMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public array $payload,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('chat.' . $this->sessionId),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'operator.message';
    }
}
