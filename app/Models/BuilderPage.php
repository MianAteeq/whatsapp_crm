<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuilderPage extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'builder_pages';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'slug',
        'description',
        'category',
        'status',
        'theme',
        'sections',
        'seo_settings',
        'published_at',
        'view_count',
        'submission_count',
    ];

    protected $casts = [
        'theme' => 'array',
        'sections' => 'array',
        'seo_settings' => 'array',
        'published_at' => 'datetime',
        'view_count' => 'integer',
        'submission_count' => 'integer',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(BuilderForm::class, 'page_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(BuilderSubmission::class, 'page_id');
    }
}

