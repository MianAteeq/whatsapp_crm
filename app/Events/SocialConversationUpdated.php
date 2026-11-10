<?php

namespace App\Events;

use App\Models\SocialConversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class SocialConversationUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public SocialConversation $conversation,
        public string $action = 'updated'
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('social-inbox.' . $this->conversation->tenant_id),
            new Channel('tenant.' . $this->conversation->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'social.conversation.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'conversation_id' => $this->conversation->id,
            'conversation' => [
                'id' => $this->conversation->id,
                'tenant_id' => $this->conversation->tenant_id,
                'status' => $this->conversation->status,
                'priority' => $this->conversation->priority,
                'is_unread' => $this->conversation->is_unread,
                'unread_count' => $this->conversation->unread_count,
                'assigned_to_user_id' => $this->conversation->assigned_to_user_id,
                'assignee' => $this->conversation->assignee ? [
                    'id' => $this->conversation->assignee->id,
                    'name' => $this->conversation->assignee->name,
                    'avatar' => $this->conversation->assignee->avatar,
                ] : null,
                'tags' => $this->conversation->tags ? $this->conversation->tags->toArray() : [],
            ],
        ];
    }
}

