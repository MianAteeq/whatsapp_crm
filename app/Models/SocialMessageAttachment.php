<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMessageAttachment extends Model
{
    use HasFactory;

    protected $table = 'social_message_attachments';

    protected $fillable = [
        'tenant_id',
        'social_message_id',
        'attachment_type',
        'url',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'width',
        'height',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'metadata' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SocialMessage::class, 'social_message_id');
    }
}

