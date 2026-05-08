<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class MessageReceived implements ShouldBroadcast
{

    use InteractsWithSockets, SerializesModels;

    public $message;

    public $tenantId;



    public function __construct(Message $message)
    {

        $this->message = $message;

        $this->tenantId = $message->tenant_id;

    }



    public function broadcastOn()
    {

        return new Channel(

            'tenant.' . $this->tenantId

        );

    }



    public function broadcastAs()
    {

        return 'message.received';

    }

}