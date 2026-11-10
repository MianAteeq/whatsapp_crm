<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SocialWebhookEvent extends Model
{
    use HasFactory;

    protected $table = 'social_webhook_events';

    protected $fillable = [
        'tenant_id',
        'platform',
        'event_id',
        'event_type',
        'sender_id',
        'recipient_id',
        'processed',
        'payload',
        'processed_at',
        'error_message',
    ];

    protected $casts = [
        'processed' => 'boolean',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}

