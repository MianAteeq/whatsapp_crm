<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SocialAccount extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_WEBHOOK_PENDING = 'webhook_pending';
    public const STATUS_WEBHOOK_ACTIVE = 'webhook_active';
    public const STATUS_WEBHOOK_FAILED = 'webhook_failed';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $table = 'social_accounts';

    protected $fillable = [
        'tenant_id',
        'platform',
        'account_id',
        'name',
        'username',
        'profile_picture_url',
        'access_token',
        'token_expires_at',
        'is_active',
        'webhook_status',
        'webhook_subscription_error',
        'last_webhook_received_at',
        'last_synced_at',
        'category',
        'followers_count',
        'connected_at',
        'metadata',
    ];

    /**
     * Never expose raw encrypted tokens to frontend API responses
     */
    protected $hidden = [
        'access_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_webhook_received_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'followers_count' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function postTargets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class, 'social_account_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SocialComment::class, 'social_account_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(SocialConversation::class, 'social_account_id');
    }

    public function analytics(): HasMany
    {
        return $this->hasMany(SocialAnalytic::class, 'social_account_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    public function scopeWebhookActive(Builder $query): Builder
    {
        return $query->where('webhook_status', self::STATUS_WEBHOOK_ACTIVE);
    }

    public function isWebhookActive(): bool
    {
        return $this->webhook_status === self::STATUS_WEBHOOK_ACTIVE;
    }
}

