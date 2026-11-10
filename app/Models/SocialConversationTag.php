<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialConversationTag extends Model
{
    use HasFactory;

    protected $table = 'social_conversation_tags';

    protected $fillable = [
        'tenant_id',
        'social_conversation_id',
        'tag',
        'color',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SocialConversation::class, 'social_conversation_id');
    }
}

