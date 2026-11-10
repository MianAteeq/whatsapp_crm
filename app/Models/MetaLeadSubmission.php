<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaLeadSubmission extends Model
{
    use HasFactory;

    protected $table = 'meta_lead_submissions';

    protected $fillable = [
        'tenant_id',
        'meta_lead_form_id',
        'leadgen_id',
        'form_id',
        'page_id',
        'ad_id',
        'ad_name',
        'adset_id',
        'adset_name',
        'campaign_id',
        'campaign_name',
        'platform',
        'raw_payload',
        'mapped_data',
        'crm_contact_id',
        'sync_status',
        'error_message',
        'synced_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'mapped_data' => 'array',
        'synced_at' => 'datetime',
    ];

    const STATUS_SYNCED = 'synced';
    const STATUS_DUPLICATE = 'duplicate';
    const STATUS_FAILED = 'failed';
    const STATUS_PENDING = 'pending';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(MetaLeadForm::class, 'meta_lead_form_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'crm_contact_id');
    }
}

