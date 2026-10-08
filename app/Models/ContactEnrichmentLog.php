<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactEnrichmentLog extends Model
{
    protected $table = 'contact_enrichment_logs';

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'scraped_business_id',
        'search_id',
        'field_name',
        'old_value',
        'new_value',
        'source',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function scrapedBusiness(): BelongsTo
    {
        return $this->belongsTo(ScrapedBusiness::class, 'scraped_business_id');
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(ScraperSearch::class, 'search_id');
    }
}
