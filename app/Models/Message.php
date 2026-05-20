<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{

    protected $fillable = [

        'tenant_id',
        'conversation_id',
        'message_id',
        'direction',
        'message',
        'type',
        'status',
        'payload',
        'media_url',
        'media_type',
        'mime_type',
        'file_name',
        'campaign_id',
        'is_campaign'
        

    ];


    protected $casts = [

        'payload' => 'array'

    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
