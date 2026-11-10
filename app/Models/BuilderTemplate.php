<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuilderTemplate extends Model
{
    use HasFactory;

    protected $table = 'builder_templates';

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'template_type',
        'thumbnail_url',
        'description',
        'content',
        'is_system',
    ];

    protected $casts = [
        'content' => 'array',
        'is_system' => 'boolean',
    ];

    public function scopeAvailableForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('is_system', true)
            ->when($tenantId, fn($q) => $q->orWhere('tenant_id', $tenantId));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}

