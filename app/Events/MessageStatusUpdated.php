<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MessageStatusUpdated implements ShouldBroadcast
{

    use Dispatchable, InteractsWithSockets, SerializesModels;



    public $message;

    public $tenantId;



    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {

        $this->message = $message;

        $this->tenantId = $message->tenant_id;

    }



    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {

        return [

            new Channel(
                'tenant.' . $this->tenantId
            )

        ];

    }



    /**
     * Event name
     */
    public function broadcastAs(): string
    {

        return 'message.status.updated';

    }

}