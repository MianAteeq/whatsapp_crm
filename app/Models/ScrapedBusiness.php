<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapedBusiness extends Model
{
    protected $table = 'scraped_businesses';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'search_id',
        'google_place_id',
        'business_name',
        'normalized_business_name',
        'category',
        'website',
        'website_domain',
        'normalized_domain',
        'phone',
        'phone_original',
        'phone_normalized',
        'address',
        'google_maps_url',
        'rating',
        'review_count',
        'email',
        'email_normalized',
        'email_source',
        'source',
        'source_details',
        'status',
        'lead_quality_score',
        'lead_quality_grade',
        'enrichment_status',
        'missing_fields',
        'last_enriched_at',
        'enrichment_attempts',
        'is_imported_to_crm',
        'crm_contact_id',
        'failure_reason',
    ];

    protected $casts = [
        'is_imported_to_crm' => 'boolean',
        'rating' => 'float',
        'review_count' => 'integer',
        'lead_quality_score' => 'integer',
        'missing_fields' => 'array',
        'source_details' => 'array',
        'last_enriched_at' => 'datetime',
        'enrichment_attempts' => 'integer',
    ];

    public function enrichmentLogs(): HasMany
    {
        return $this->hasMany(ContactEnrichmentLog::class, 'scraped_business_id')->latest();
    }

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

    public function emails(): HasMany
    {
        return $this->hasMany(ScraperBusinessEmail::class, 'scraped_business_id');
    }

    public function crmContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'crm_contact_id');
    }
}
