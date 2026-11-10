<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaLeadForm extends Model
{
    use HasFactory;

    protected $table = 'meta_lead_forms';

    protected $fillable = [
        'tenant_id',
        'social_account_id',
        'form_id',
        'page_id',
        'ad_account_id',
        'name',
        'status',
        'locale',
        'questions',
        'crm_mapping',
        'rules',
        'is_active',
        'auto_sync',
        'submissions_count',
        'last_synced_at',
    ];

    protected $casts = [
        'questions' => 'array',
        'crm_mapping' => 'array',
        'rules' => 'array',
        'is_active' => 'boolean',
        'auto_sync' => 'boolean',
        'submissions_count' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(MetaLeadSubmission::class, 'meta_lead_form_id');
    }

    /**
     * Scope for active syncing forms
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

