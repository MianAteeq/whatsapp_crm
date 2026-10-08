<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaignRecipient extends Model
{
    protected $table = 'email_campaign_recipients';

    const STATUS_PENDING = 'pending';
    const STATUS_QUEUED = 'queued';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_DEFERRED = 'deferred';
    const STATUS_BOUNCED = 'bounced';
    const STATUS_FAILED = 'failed';
    const STATUS_COMPLAINED = 'complained';
    const STATUS_UNSUBSCRIBED = 'unsubscribed';
    const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'contact_id',
        'email',
        'status',
        'provider',
        'provider_message_id',
        'personalized_subject',
        'attempts',
        'error_message',
        'bounce_type',
        'bounce_reason',
        'sent_at',
        'delivered_at',
        'deferred_at',
        'bounced_at',
        'complained_at',
        'unsubscribed_at',
        'failed_at',
        'last_event_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'deferred_at' => 'datetime',
        'bounced_at' => 'datetime',
        'complained_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'failed_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaign()
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
