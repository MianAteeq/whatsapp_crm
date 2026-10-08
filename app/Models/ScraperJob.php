<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScraperJob extends Model
{
    protected $table = 'scraper_jobs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'search_id',
        'status',
        'total',
        'processed',
        'successful',
        'failed',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total' => 'integer',
        'processed' => 'integer',
        'successful' => 'integer',
        'failed' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(ScraperSearch::class, 'search_id');
    }
}
