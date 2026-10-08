<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScraperSearch extends Model
{
    protected $table = 'scraper_searches';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'category_id',
        'keyword',
        'industry',
        'location',
        'radius',
        'max_results',
        'lead_volume',
        'search_mode',
        'status',
        'phase',
        'zones_total',
        'zones_completed',
        'coverage_exhausted',
        'exhaustion_note',
        'total_found',
        'websites_found',
        'total_processed',
        'total_emails_found',
        'total_duplicates',
        'duplicates_removed',
        'no_email',
        'total_failed',
        'error_message',
        'options',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'radius' => 'integer',
        'max_results' => 'integer',
        'lead_volume' => 'integer',
        'zones_total' => 'integer',
        'zones_completed' => 'integer',
        'coverage_exhausted' => 'boolean',
        'total_found' => 'integer',
        'websites_found' => 'integer',
        'total_processed' => 'integer',
        'total_emails_found' => 'integer',
        'total_duplicates' => 'integer',
        'duplicates_removed' => 'integer',
        'no_email' => 'integer',
        'total_failed' => 'integer',
        'options' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function scrapeResults(): HasMany
    {
        return $this->hasMany(ScrapeResult::class, 'scrape_search_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(ScrapedBusiness::class, 'search_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ScraperJob::class, 'search_id');
    }
}
