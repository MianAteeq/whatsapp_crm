<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSavedReport extends Model
{
    use HasFactory;

    protected $table = 'marketing_saved_reports';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'description',
        'report_type',
        'filters',
        'metrics',
        'schedule',
    ];

    protected $casts = [
        'filters' => 'array',
        'metrics' => 'array',
        'schedule' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}

