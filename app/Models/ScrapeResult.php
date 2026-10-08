<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeResult extends Model
{
    protected $fillable = [
        'tenant_id',
        'scrape_search_id',
        'business_id',
        'contact_id',
        'category_id',
        'discovery_source',
        'discovered_at',
        'enrichment_status',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(ScraperSearch::class, 'scrape_search_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
