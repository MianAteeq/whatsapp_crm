<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'tenant_id',
        'business_id',
        'name',
        'first_name',
        'last_name',
        'phone',
        'phone_normalized',
        'phone_original',
        'google_place_id',
        'website_domain',
        'secondary_phone',
        'secondary_phone_normalized',
        'secondary_email',
        'email',
        'email_normalized',
        'company',
        'job_title',
        'contact_type',
        'address',
        'birthday',
        'website',
        'notes',
        'status',
        'lead_quality_score',
        'lead_quality_grade',
        'enrichment_status',
        'missing_fields',
        'source',
        'source_details',
        'last_scraped_at',
        'last_enriched_at',
        'enrichment_attempts',
    ];

    protected $casts = [
        'lead_quality_score' => 'integer',
        'missing_fields' => 'array',
        'source_details' => 'array',
        'last_scraped_at' => 'datetime',
        'last_enriched_at' => 'datetime',
        'enrichment_attempts' => 'integer',
        'birthday' => 'date',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'contact_categories');
    }

    public function scrapeResults()
    {
        return $this->hasMany(ScrapeResult::class, 'contact_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function enrichmentLogs()
    {
        return $this->hasMany(ContactEnrichmentLog::class, 'contact_id')->latest();
    }

    public function emailRecipients()
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'contact_id');
    }

    public function emailSuppressions()
    {
        return $this->hasMany(EmailSuppression::class, 'contact_id');
    }

    protected static function booted()
    {
        static::saving(function (Contact $contact) {
            if (!empty($contact->email)) {
                $contact->email_normalized = strtolower(trim($contact->email));
            }
            if (empty($contact->name) && (!empty($contact->first_name) || !empty($contact->last_name))) {
                $contact->name = trim("{$contact->first_name} {$contact->last_name}");
            }
        });

        static::deleting(function (Contact $contact) {
            // Detach category pivots
            $contact->categories()->detach();

            // 1. Delete associated conversations & their messages
            foreach ($contact->conversations as $conversation) {
                $conversation->messages()->delete();
                $conversation->delete();
            }

            // 2. Detach tags pivot
            $contact->tags()->detach();

            // 3. Delete WhatsApp campaign contacts
            \App\Models\CampaignContact::where('contact_id', $contact->id)->delete();

            // 4. Delete Email campaign recipients
            \App\Models\EmailCampaignRecipient::where('contact_id', $contact->id)->delete();

            // 5. Delete enrichment logs
            $contact->enrichmentLogs()->delete();

            // 6. Unlink from scraped businesses so they can be re-imported if needed
            \App\Models\ScrapedBusiness::where('crm_contact_id', $contact->id)
                ->update([
                    'crm_contact_id' => null,
                    'is_imported_to_crm' => false,
                ]);

            // 7. Nullify message logs contact_id
            \App\Models\WhatsappMessageLog::where('contact_id', $contact->id)
                ->update(['contact_id' => null]);
        });
    }
}
