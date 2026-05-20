<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class CampaignUpdated implements ShouldBroadcast
{

    use Dispatchable, InteractsWithSockets, SerializesModels;



    public $campaign;

    public $delivery_rate;

    public $read_rate;

    public $tenantId;



    /**
     * Create a new event instance.
     */
    public function __construct($data)
    {

        $this->campaign = $data['campaign'];

        $this->delivery_rate = $data['delivery_rate'];

        $this->read_rate = $data['read_rate'];

        $this->tenantId = $data['campaign']->tenant_id;

    }



    /**
     * Broadcast channel
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

        return 'campaign.updated';

    }



    /**
     * Broadcast payload
     */
    public function broadcastWith(): array
    {

        return [

            'campaign' => [

                'id' => $this->campaign->id,

                'name' => $this->campaign->name,

                'status' => $this->campaign->status,

                'total_contacts' => $this->campaign->total_contacts,

                'sent_count' => $this->campaign->sent_count,

                'delivered_count' => $this->campaign->delivered_count,

                'read_count' => $this->campaign->read_count,

                'failed_count' => $this->campaign->failed_count,

                'started_at' => $this->campaign->started_at,

                'completed_at' => $this->campaign->completed_at

            ],

            'delivery_rate' => $this->delivery_rate,

            'read_rate' => $this->read_rate

        ];

    }

}