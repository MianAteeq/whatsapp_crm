<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialConversationAssignment extends Model
{
    use HasFactory;

    protected $table = 'social_conversation_assignments';

    protected $fillable = [
        'tenant_id',
        'social_conversation_id',
        'assigned_by_user_id',
        'assigned_to_user_id',
        'reason',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'social_conversation_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}

