<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SocialMessage extends Model
{
    use HasFactory;

    protected $table = 'social_messages';

    protected $fillable = [
        'tenant_id',
        'social_conversation_id',
        'social_account_id',
        'sender_type',
        'sender_user_id',
        'sender_id',
        'sender_name',
        'sender_avatar_url',
        'message_text',
        'platform_message_id',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const SENDER_CUSTOMER = 'customer';
    public const SENDER_AGENT = 'agent';
    public const SENDER_SYSTEM = 'system';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'social_conversation_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function agentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SocialMessageAttachment::class, 'social_message_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}

