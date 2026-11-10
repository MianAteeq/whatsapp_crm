<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialConversationNote extends Model
{
    use HasFactory;

    protected $table = 'social_conversation_notes';

    protected $fillable = [
        'tenant_id',
        'social_conversation_id',
        'user_id',
        'content',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'social_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

