<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class SocialAnalytic extends Model
{
    use HasFactory;

    protected $table = 'social_analytics';

    protected $fillable = [
        'tenant_id',
        'social_account_id',
        'date',
        'platform',
        'reach',
        'impressions',
        'engagement',
        'likes',
        'comments',
        'shares',
        'followers_count',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'reach' => 'integer',
        'impressions' => 'integer',
        'engagement' => 'integer',
        'likes' => 'integer',
        'comments' => 'integer',
        'shares' => 'integer',
        'followers_count' => 'integer',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}

