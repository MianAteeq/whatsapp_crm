<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SocialPost extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'social_posts';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'content',
        'status',
        'scheduled_at',
        'published_at',
        'options',
        'error_message',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'options' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(SocialPostMedia::class, 'post_id')->orderBy('order');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class, 'post_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SocialPublishLog::class, 'post_id')->orderByDesc('id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeScheduledDue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }
}

