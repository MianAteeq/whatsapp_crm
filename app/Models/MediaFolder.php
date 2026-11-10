<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFolder extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'media_folders';

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'slug',
        'color',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MediaFolder::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'media_folder_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}

