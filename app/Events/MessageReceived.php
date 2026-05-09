<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessageReceived implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public Message $message;

    public int $tenantId;

    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->tenantId = $message->tenant_id;
    }

    /**
     * Broadcast channel
     */
    public function broadcastOn(): array
    {
        return [
            new Channel(
                'tenant.' . $this->tenantId
            ),
        ];
    }

    /**
     * Custom event name
     */
    public function broadcastAs(): string
    {
        return 'message.received';
    }

    /**
     * Broadcast payload
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'message' => $this->message->message,
                'tenant_id' => $this->message->tenant_id,
                'sender_id' => $this->message->sender_id,
                'created_at' => $this->message->created_at,
            ],
        ];
    }
}