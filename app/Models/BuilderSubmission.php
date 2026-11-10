<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuilderSubmission extends Model
{
    use HasFactory;

    protected $table = 'builder_submissions';

    protected $fillable = [
        'tenant_id',
        'form_id',
        'page_id',
        'contact_id',
        'data',
        'survey_results',
        'ip_address',
        'user_agent',
        'referer',
        'utm_params',
        'status',
    ];

    protected $casts = [
        'data' => 'array',
        'survey_results' => 'array',
        'utm_params' => 'array',
    ];

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACT_CREATED = 'contact_created';
    public const STATUS_CONTACT_UPDATED = 'contact_updated';
    public const STATUS_SPAM = 'spam';

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(BuilderForm::class, 'form_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(BuilderPage::class, 'page_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}

