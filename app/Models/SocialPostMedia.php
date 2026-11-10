<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPostMedia extends Model
{
    use HasFactory;

    protected $table = 'social_post_media';

    protected $fillable = [
        'tenant_id',
        'post_id',
        'media_type',
        'url',
        'file_path',
        'filename',
        'file_size',
        'mime_type',
        'width',
        'height',
        'aspect_ratio',
        'order',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($media) {
            if (empty($media->tenant_id)) {
                if ($media->post) {
                    $media->tenant_id = $media->post->tenant_id;
                } elseif (auth()->check() && auth()->user()->tenant_id) {
                    $media->tenant_id = auth()->user()->tenant_id;
                }
            }
            if ($media->file_path === null) {
                $media->file_path = '';
            }
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
