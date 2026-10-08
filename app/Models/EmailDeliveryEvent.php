<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailDeliveryEvent extends Model
{
    protected $table = 'email_delivery_events';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'recipient_id',
        'provider',
        'provider_message_id',
        'provider_event_id',
        'event_type',
        'event_timestamp',
        'payload',
    ];

    protected $casts = [
        'event_timestamp' => 'datetime',
        'payload' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaign()
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function recipient()
    {
        return $this->belongsTo(EmailCampaignRecipient::class, 'recipient_id');
    }
}
