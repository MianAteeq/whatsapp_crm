<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaign extends Model
{
    protected $table = 'email_campaigns';

    const STATUS_DRAFT = 'draft';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_SENDING = 'sending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PAUSED = 'paused';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'subject',
        'from_name',
        'from_email',
        'reply_to',
        'sending_domain_id',
        'sending_domain',
        'template_id',
        'html_body',
        'text_body',
        'audience_filter',
        'status',
        'total_recipients',
        'eligible_count',
        'excluded_count',
        'sent_count',
        'delivered_count',
        'bounced_count',
        'failed_count',
        'unsubscribed_count',
        'scheduled_at',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'audience_filter' => 'array',
        'total_recipients' => 'integer',
        'eligible_count' => 'integer',
        'excluded_count' => 'integer',
        'sent_count' => 'integer',
        'delivered_count' => 'integer',
        'bounced_count' => 'integer',
        'failed_count' => 'integer',
        'unsubscribed_count' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function template()
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }

    public function recipients()
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'campaign_id');
    }

    public function sendingDomainRecord()
    {
        return $this->belongsTo(EmailSendingDomain::class, 'sending_domain_id');
    }

    /**
     * Recalculate summary metrics from recipients table
     */
    public function recalculateMetrics(): void
    {
        $stats = $this->recipients()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('sent', 'delivered', 'bounced', 'complained', 'unsubscribed') THEN 1 ELSE 0 END) as sent_total,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_total,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) as bounced_total,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_total,
                SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed_total
            ")
            ->first();

        if ($stats) {
            $this->update([
                'sent_count' => (int) ($stats->sent_total ?? 0),
                'delivered_count' => (int) ($stats->delivered_total ?? 0),
                'bounced_count' => (int) ($stats->bounced_total ?? 0),
                'failed_count' => (int) ($stats->failed_total ?? 0),
                'unsubscribed_count' => (int) ($stats->unsubscribed_total ?? 0),
            ]);
        }
    }
}
