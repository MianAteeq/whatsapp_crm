<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPublishLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'social_publish_logs';

    protected $fillable = [
        'tenant_id',
        'post_id',
        'post_target_id',
        'status',
        'action',
        'message',
        'details',
        'created_at',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SocialPostTarget::class, 'post_target_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

