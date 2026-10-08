<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\Contact;
use App\Models\ScrapedBusiness;
use App\Models\ScraperBusinessEmail;
use App\Models\ScraperJob;
use App\Models\ScraperSearch;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScraperService
{
    public function __construct(
        protected GooglePlacesService $placesService,
        protected WebsiteEmailExtractorService $emailExtractor,
        protected BusinessDiscoveryEngine $discoveryEngine,
        protected PhoneNormalizationService $phoneNormalizer,
        protected LeadQualityService $qualityService,
        protected ContactUpsertService $contactUpsert
    ) {}

    /**
     * Check if scraper is enabled and configured
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->placesService->isEnabled(),
            'google_api_configured' => $this->placesService->isConfigured(),
        ];
    }

    /**
     * Start a new search
     */
    public function createSearch(int $tenantId, int $userId, array $params): ScraperSearch
    {
        $leadVolume = isset($params['lead_volume']) && (int) $params['lead_volume'] > 0
            ? (int) $params['lead_volume']
            : (isset($params['max_results']) ? (int) $params['max_results'] : 50);

        $searchMode = $params['search_mode'] ?? ($params['options']['search_mode'] ?? (($leadVolume > 100) ? 'deep' : 'standard'));

        $categoryId = !empty($params['category_id']) ? (int) $params['category_id'] : null;
        if (!$categoryId && !empty($params['category'])) {
            $cat = \App\Models\Category::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => trim($params['category'])],
                ['slug' => \Illuminate\Support\Str::slug($params['category'])]
            );
            $categoryId = $cat->id;
        } elseif (!$categoryId && !empty($params['industry'])) {
            $cat = \App\Models\Category::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => trim($params['industry'])],
                ['slug' => \Illuminate\Support\Str::slug($params['industry'])]
            );
            $categoryId = $cat->id;
        }

        $search = ScraperSearch::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'category_id' => $categoryId,
            'keyword' => trim($params['keyword']),
            'industry' => !empty($params['industry']) ? trim($params['industry']) : null,
            'location' => trim($params['location']),
            'radius' => isset($params['radius']) && $params['radius'] > 0 ? (int) $params['radius'] : 25000,
            'lead_volume' => $leadVolume,
            'max_results' => $leadVolume,
            'search_mode' => $searchMode,
            'phase' => 'queued',
            'zones_total' => 0,
            'zones_completed' => 0,
            'coverage_exhausted' => false,
            'options' => $params['options'] ?? null,
            'status' => 'queued',
        ]);

        ScraperJob::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'search_id' => $search->id,
            'status' => 'pending',
            'total' => $search->lead_volume,
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
        ]);

        Log::channel('scraper')->info("Scraper search created: ID {$search->id} for '{$search->keyword}' (Target: {$search->lead_volume}, Mode: {$search->search_mode}) in '{$search->location}'");

        return $search;
    }

    /**
     * Execute search processing for a ScraperSearch via BusinessDiscoveryEngine
     */
    public function processSearch(ScraperSearch $search): void
    {
        $job = ScraperJob::where('search_id', $search->id)->latest()->first();
        if ($job) {
            $job->update(['status' => 'running', 'started_at' => now()]);
        }

        Log::channel('scraper')->info("Processing scraper search ID {$search->id} via BusinessDiscoveryEngine");

        try {
            // PHASE 1: Large-Scale Geographic Discovery (Cells + Immediate Place ID Deduplication)
            $this->discoveryEngine->executeDiscovery($search, function ($s) use ($job) {
                if ($job) {
                    $job->update([
                        'total' => $s->lead_volume,
                        'processed' => $s->total_found,
                    ]);
                }
            });

            // PHASE 2: Website Email Extraction & Contact Enrichment
            $this->discoveryEngine->executeEnrichment($search, function ($s) use ($job) {
                if ($job) {
                    $job->update([
                        'processed' => (int) $s->total_processed,
                        'successful' => (int) $s->total_emails_found,
                        'failed' => (int) $s->total_failed,
                    ]);
                }
            });

            $search->refresh();
            if ($job) {
                $job->update([
                    'status' => 'completed',
                    'processed' => (int) $search->total_processed,
                    'successful' => (int) $search->total_emails_found,
                    'failed' => (int) $search->total_failed,
                    'completed_at' => now(),
                ]);
            }

            Log::channel('scraper')->info("Search ID {$search->id} fully completed. Discovered: {$search->total_found}, Websites: {$search->websites_found}, Emails: {$search->total_emails_found}, Duplicates: {$search->duplicates_removed}, No Email: {$search->no_email}, Failed: {$search->total_failed}");

        } catch (\Throwable $e) {
            $search->update([
                'status' => 'failed',
                'phase' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            if ($job) {
                $job->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                ]);
            }

            Log::channel('scraper')->error("Search ID {$search->id} failed: " . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Process an individual business discovered from Google Places
     */
    protected function processSingleBusiness(ScraperSearch $search, array $place): void
    {
        $tenantId = $search->tenant_id;
        $userId = $search->user_id;
        $placeId = $place['google_place_id'] ?? null;
        $rawName = $place['business_name'] ?? 'Unknown Business';
        $normalizedName = trim(preg_replace('/[^a-z0-9]+/i', ' ', strtolower($rawName)));
        $rawAddress = $place['address'] ?? null;
        $normalizedAddress = $rawAddress ? trim(preg_replace('/[^a-z0-9]+/i', ' ', strtolower($rawAddress))) : null;

        $rawWebsite = $place['website'] ?? null;
        $normalizedWebsite = $this->emailExtractor->normalizeUrl($rawWebsite);
        $normalizedDomain = $this->emailExtractor->extractDomain($normalizedWebsite);

        // 1. Business Duplicate Check by Google Place ID
        if ($placeId) {
            $exists = ScrapedBusiness::where('tenant_id', $tenantId)
                ->where('google_place_id', $placeId)
                ->exists();

            if ($exists) {
                $search->increment('total_duplicates');
                $search->increment('duplicates_removed');
                Log::channel('scraper')->debug("Duplicate Google Place ID skipped: {$placeId} ({$rawName})");
                return;
            }
        }

        // 2. Business Duplicate Check by Normalized Website / Domain
        if ($normalizedDomain) {
            $existingDomain = ScrapedBusiness::where('tenant_id', $tenantId)
                ->where(function ($q) use ($normalizedDomain) {
                    $q->where('website_domain', $normalizedDomain)
                      ->orWhere('normalized_domain', $normalizedDomain);
                })
                ->first();

            if ($existingDomain && !empty($existingDomain->email)) {
                // If this domain was already crawled and an email is known, reuse cached email
                $this->saveBusinessRecord($search, $place, [
                    'normalized_business_name' => $normalizedName,
                    'website' => $normalizedWebsite,
                    'website_domain' => $normalizedDomain,
                    'normalized_domain' => $normalizedDomain,
                    'email' => $existingDomain->email,
                    'email_normalized' => $existingDomain->email_normalized,
                    'email_source' => $existingDomain->email_source ?? 'Domain Cache',
                    'rating' => $place['rating'] ?? null,
                    'review_count' => $place['review_count'] ?? null,
                    'status' => 'email_found',
                    'failure_reason' => null,
                    'emails' => [
                        [
                            'email' => $existingDomain->email,
                            'email_normalized' => $existingDomain->email_normalized,
                            'source' => 'Domain Cache',
                            'source_page' => 'domain_cache',
                        ]
                    ],
                ]);
                $search->increment('websites_found');
                $search->increment('total_processed');
                $search->increment('total_emails_found');
                return;
            }
        }

        // 3. Duplicate Check by Normalized Business Name + Address
        if (!empty($normalizedName) && !empty($normalizedAddress)) {
            $nameAddressExists = ScrapedBusiness::where('tenant_id', $tenantId)
                ->where('normalized_business_name', $normalizedName)
                ->where('address', $rawAddress)
                ->exists();

            if ($nameAddressExists) {
                $search->increment('total_duplicates');
                $search->increment('duplicates_removed');
                Log::channel('scraper')->debug("Duplicate Name + Address skipped: {$rawName}");
                return;
            }
        }

        // Track website existence
        if ($normalizedWebsite) {
            $search->increment('websites_found');
        }

        // 4. Email Extraction from Website
        $primaryEmail = null;
        $normalizedEmail = null;
        $emailSource = null;
        $status = 'no_website';
        $failureReason = null;
        $extractedEmails = [];

        if ($normalizedWebsite) {
            $crawlOptions = [
                'email_discovery_mode' => $search->options['email_discovery_mode'] ?? 'website_contact',
                'timeout' => $search->options['advanced']['timeout'] ?? 10,
            ];

            $crawlResult = $this->emailExtractor->extractEmails($normalizedWebsite, $crawlOptions);

            if ($crawlResult['success'] && !empty($crawlResult['primary_email'])) {
                $extractedEmails = $crawlResult['emails'];
                $primaryEmail = $crawlResult['primary_email'];
                $normalizedEmail = strtolower(trim($primaryEmail));
                $emailSource = $crawlResult['email_source'] ?? 'Website';

                // Email Duplicate Check against tenant's existing records
                $emailExists = ScrapedBusiness::where('tenant_id', $tenantId)
                    ->where('email_normalized', $normalizedEmail)
                    ->exists();

                if ($emailExists) {
                    $search->increment('total_duplicates');
                    $search->increment('duplicates_removed');
                    Log::channel('scraper')->debug("Duplicate email detected: {$normalizedEmail}");
                }

                $status = 'email_found';
                $search->increment('total_emails_found');
            } elseif ($crawlResult['success']) {
                $status = 'no_email_found';
                $search->increment('no_email');
            } else {
                $status = 'failed';
                $failureReason = $crawlResult['error'] ?? 'Website request failed';
                $search->increment('total_failed');
            }
        } else {
            $search->increment('no_email');
        }

        // 5. Store Scraped Business
        $this->saveBusinessRecord($search, $place, [
            'normalized_business_name' => $normalizedName,
            'website' => $normalizedWebsite,
            'website_domain' => $normalizedDomain,
            'normalized_domain' => $normalizedDomain,
            'email' => $primaryEmail,
            'email_normalized' => $normalizedEmail,
            'email_source' => $emailSource,
            'rating' => $place['rating'] ?? null,
            'review_count' => $place['review_count'] ?? null,
            'status' => $status,
            'failure_reason' => $failureReason,
            'emails' => $extractedEmails,
        ]);

        $search->increment('total_processed');
    }

    /**
     * Save the business and its associated email records inside a transaction
     */
    protected function saveBusinessRecord(ScraperSearch $search, array $place, array $details): void
    {
        $rawPhone = $place['phone'] ?? null;
        $phoneNorm = !empty($rawPhone) ? $this->phoneNormalizer->normalizeE164($rawPhone, $search->location) : null;

        $tempQuality = $this->qualityService->calculate([
            'business_name' => $place['business_name'] ?? 'Unknown Business',
            'phone_normalized' => $phoneNorm,
            'phone' => $rawPhone,
            'email' => $details['email'],
            'email_normalized' => $details['email_normalized'],
            'website' => $details['website'],
            'address' => $place['address'] ?? null,
            'category' => $place['category'] ?? null,
            'google_place_id' => $place['google_place_id'] ?? null,
            'rating' => $details['rating'] ?? null,
            'review_count' => $details['review_count'] ?? null,
        ]);

        DB::transaction(function () use ($search, $place, $details, $rawPhone, $phoneNorm, $tempQuality) {
            $business = ScrapedBusiness::create([
                'tenant_id' => $search->tenant_id,
                'user_id' => $search->user_id,
                'search_id' => $search->id,
                'google_place_id' => $place['google_place_id'] ?? null,
                'business_name' => $place['business_name'] ?? 'Unknown Business',
                'normalized_business_name' => $details['normalized_business_name'] ?? null,
                'category' => $place['category'] ?? null,
                'website' => $details['website'],
                'website_domain' => $details['website_domain'],
                'normalized_domain' => $details['normalized_domain'] ?? null,
                'phone' => $rawPhone,
                'phone_original' => $rawPhone,
                'phone_normalized' => $phoneNorm,
                'address' => $place['address'] ?? null,
                'google_maps_url' => $place['google_maps_url'] ?? null,
                'rating' => $details['rating'] ?? null,
                'review_count' => $details['review_count'] ?? null,
                'email' => $details['email'],
                'email_normalized' => $details['email_normalized'],
                'email_source' => $details['email_source'] ?? null,
                'source' => 'Google Maps / Business Scraper',
                'source_details' => array_filter([
                    'name' => 'Google Places',
                    'phone' => !empty($phoneNorm) ? 'Google Places' : null,
                    'website' => !empty($details['website']) ? 'Google Places' : null,
                    'email' => !empty($details['email']) ? ($details['email_source'] ?? 'Website') : null,
                    'address' => !empty($place['address']) ? 'Google Places' : null,
                ]),
                'lead_quality_score' => $tempQuality['score'],
                'lead_quality_grade' => $tempQuality['grade'],
                'enrichment_status' => $tempQuality['enrichment_status'],
                'missing_fields' => $tempQuality['missing_fields'],
                'status' => $details['status'],
                'is_imported_to_crm' => false,
                'failure_reason' => $details['failure_reason'],
            ]);

            // Save individual emails to scraper_business_emails
            if (!empty($details['emails'])) {
                foreach ($details['emails'] as $em) {
                    ScraperBusinessEmail::create([
                        'scraped_business_id' => $business->id,
                        'email' => $em['email'],
                        'email_normalized' => $em['email_normalized'],
                        'is_primary' => ($em['email_normalized'] === $details['email_normalized']),
                        'source' => $em['source'] ?? ($details['email_source'] ?? 'Website'),
                        'source_page' => $em['source_page'] ?? null,
                    ]);
                }
            }
        });
    }

    /**
     * Import selected or all scraped businesses into CRM Contacts table with smart deduplication & enrichment
     */
    public function importToCrm(int $tenantId, int $userId, array $businessIds = [], ?int $searchId = null, array $filters = []): array
    {
        return $this->contactUpsert->upsertMany($tenantId, $userId, $businessIds, $searchId, $filters);
    }

    /**
     * Enrich single lead specifically targeting missing fields
     */
    public function enrichLead(int $tenantId, int $userId, int $scrapedBusinessId): array
    {
        return $this->contactUpsert->enrichMissingData($tenantId, $userId, $scrapedBusinessId);
    }

    /**
     * Bulk enrich missing data for multiple leads
     */
    public function bulkEnrichLeads(int $tenantId, int $userId, array $businessIds): array
    {
        $selectedCount = count($businessIds);
        $enrichedCount = 0;
        $stillMissingCount = 0;
        $failedCount = 0;

        foreach ($businessIds as $id) {
            try {
                $res = $this->contactUpsert->enrichMissingData($tenantId, $userId, (int) $id);
                if (!empty($res['enriched_fields'])) {
                    $enrichedCount++;
                } else {
                    $stillMissingCount++;
                }
            } catch (\Throwable $e) {
                $failedCount++;
                Log::channel('scraper')->error("Bulk enrich error on ID {$id}: " . $e->getMessage());
            }
        }

        return [
            'total_selected' => $selectedCount,
            'enriched_count' => $enrichedCount,
            'still_missing_count' => $stillMissingCount,
            'failed_count' => $failedCount,
        ];
    }
}
