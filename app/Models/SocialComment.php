<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SocialComment extends Model
{
    use HasFactory;

    protected $table = 'social_comments';

    protected $fillable = [
        'tenant_id',
        'social_account_id',
        'social_post_target_id',
        'platform',
        'comment_id',
        'post_id',
        'parent_comment_id',
        'commenter_id',
        'commenter_name',
        'commenter_avatar_url',
        'message',
        'created_time',
        'is_answered',
        'is_handled',
        'reply_message',
        'replied_at',
        'metadata',
    ];

    protected $casts = [
        'created_time' => 'datetime',
        'is_answered' => 'boolean',
        'is_handled' => 'boolean',
        'replied_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function postTarget(): BelongsTo
    {
        return $this->belongsTo(SocialPostTarget::class, 'social_post_target_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SocialComment::class, 'parent_comment_id', 'comment_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeUnhandled(Builder $query): Builder
    {
        return $query->where('is_handled', false);
    }

    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->where('is_answered', false);
    }
}

