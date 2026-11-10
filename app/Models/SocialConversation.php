<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SocialConversation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'social_conversations';

    protected $fillable = [
        'tenant_id',
        'social_account_id',
        'contact_id',
        'assigned_to_user_id',
        'platform',
        'conversation_type',
        'platform_conversation_id',
        'participant_id',
        'participant_name',
        'participant_username',
        'participant_avatar_url',
        'status',
        'priority',
        'is_unread',
        'unread_count',
        'last_message_at',
        'last_message_preview',
        'last_message_sender',
        'post_reference_id',
        'post_reference_url',
        'metadata',
    ];

    protected $casts = [
        'is_unread' => 'boolean',
        'unread_count' => 'integer',
        'last_message_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';

    public const PRIORITY_URGENT = 'urgent';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW = 'low';

    public const TYPE_DIRECT_MESSAGE = 'direct_message';
    public const TYPE_COMMENT = 'comment';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class, 'social_conversation_id')->orderBy('sent_at', 'asc');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SocialConversationNote::class, 'social_conversation_id')->latest();
    }

    public function tags(): HasMany
    {
        return $this->hasMany(SocialConversationTag::class, 'social_conversation_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SocialConversationAssignment::class, 'social_conversation_id')->latest();
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_unread', true);
    }
}

