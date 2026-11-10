<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'media_assets';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'media_folder_id',
        'filename',
        'original_name',
        'disk',
        'file_path',
        'url',
        'mime_type',
        'file_type',
        'file_size',
        'file_hash',
        'width',
        'height',
        'duration',
        'metadata',
        'tags',
        'access_count',
        'last_accessed_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'float',
        'access_count' => 'integer',
        'metadata' => 'array',
        'tags' => 'array',
        'last_accessed_at' => 'datetime',
    ];

    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_DOCUMENT = 'document';
    const TYPE_AUDIO = 'audio';
    const TYPE_OTHER = 'other';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'media_folder_id');
    }

    public function references(): HasMany
    {
        return $this->hasMany(MediaAssetReference::class, 'media_asset_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Increment usage tracking counter
     */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }
}

