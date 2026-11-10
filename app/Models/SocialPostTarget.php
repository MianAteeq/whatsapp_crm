<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SocialPostTarget extends Model
{
    use HasFactory;

    protected $table = 'social_post_targets';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'post_id',
        'social_account_id',
        'platform',
        'status',
        'platform_post_id',
        'platform_post_url',
        'published_at',
        'error_message',
        'retry_count',
        'metrics',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'retry_count' => 'integer',
        'metrics' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($target) {
            if (empty($target->tenant_id)) {
                if ($target->post) {
                    $target->tenant_id = $target->post->tenant_id;
                } elseif (auth()->check() && auth()->user()->tenant_id) {
                    $target->tenant_id = auth()->user()->tenant_id;
                }
            }
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SocialPublishLog::class, 'post_target_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
