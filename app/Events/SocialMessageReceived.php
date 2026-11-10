<?php

namespace App\Events;

use App\Models\SocialConversation;
use App\Models\SocialMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class SocialMessageReceived implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public SocialConversation $conversation,
        public SocialMessage $message
    ) {}

    /**
     * Broadcast on both private and tenant channels for maximum compatibility
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('social-inbox.' . $this->conversation->tenant_id),
            new Channel('tenant.' . $this->conversation->tenant_id),
        ];
    }

    /**
     * Custom event name (listened as .social.message.received or social.message.received)
     */
    public function broadcastAs(): string
    {
        return 'social.message.received';
    }

    /**
     * Broadcast payload with complete conversation and message data
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'conversation' => [
                'id' => $this->conversation->id,
                'tenant_id' => $this->conversation->tenant_id,
                'social_account_id' => $this->conversation->social_account_id,
                'platform' => $this->conversation->platform,
                'conversation_type' => $this->conversation->conversation_type,
                'participant_id' => $this->conversation->participant_id,
                'participant_name' => $this->conversation->participant_name,
                'participant_username' => $this->conversation->participant_username,
                'participant_avatar_url' => $this->conversation->participant_avatar_url,
                'status' => $this->conversation->status,
                'priority' => $this->conversation->priority,
                'is_unread' => $this->conversation->is_unread,
                'unread_count' => $this->conversation->unread_count,
                'last_message_at' => $this->conversation->last_message_at?->toISOString(),
                'last_message_preview' => $this->conversation->last_message_preview,
                'last_message_sender' => $this->conversation->last_message_sender,
                'assigned_to_user_id' => $this->conversation->assigned_to_user_id,
                'account' => $this->conversation->account ? [
                    'id' => $this->conversation->account->id,
                    'platform' => $this->conversation->account->platform,
                    'name' => $this->conversation->account->name,
                    'username' => $this->conversation->account->username,
                    'profile_picture_url' => $this->conversation->account->profile_picture_url,
                ] : null,
                'assignee' => $this->conversation->assignee ? [
                    'id' => $this->conversation->assignee->id,
                    'name' => $this->conversation->assignee->name,
                    'email' => $this->conversation->assignee->email,
                    'avatar' => $this->conversation->assignee->avatar,
                ] : null,
                'tags' => $this->conversation->tags ? $this->conversation->tags->toArray() : [],
            ],
            'message' => [
                'id' => $this->message->id,
                'social_conversation_id' => $this->message->social_conversation_id,
                'social_account_id' => $this->message->social_account_id,
                'sender_type' => $this->message->sender_type,
                'sender_user_id' => $this->message->sender_user_id,
                'sender_id' => $this->message->sender_id,
                'sender_name' => $this->message->sender_name,
                'sender_avatar_url' => $this->message->sender_avatar_url,
                'message_text' => $this->message->message_text,
                'platform_message_id' => $this->message->platform_message_id,
                'status' => $this->message->status,
                'sent_at' => $this->message->sent_at?->toISOString(),
                'delivered_at' => $this->message->delivered_at?->toISOString(),
                'read_at' => $this->message->read_at?->toISOString(),
                'attachments' => $this->message->attachments ? $this->message->attachments->toArray() : [],
                'agentUser' => $this->message->agentUser ? [
                    'id' => $this->message->agentUser->id,
                    'name' => $this->message->agentUser->name,
                    'avatar' => $this->message->agentUser->avatar,
                ] : null,
            ],
        ];
    }
}

