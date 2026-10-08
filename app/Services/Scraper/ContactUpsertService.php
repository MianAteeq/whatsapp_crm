<?php

namespace App\Services\Scraper;

use App\Models\Business;
use App\Models\Category;
use App\Models\Contact;
use App\Models\ContactEnrichmentLog;
use App\Models\ScrapedBusiness;
use App\Models\ScraperBusinessEmail;
use App\Models\ScraperSearch;
use App\Models\ScrapeResult;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ContactUpsertService
{
    protected PhoneNormalizationService $phoneNormalizer;
    protected LeadQualityService $qualityService;
    protected WebsiteEmailExtractorService $emailExtractor;

    public function __construct(
        PhoneNormalizationService $phoneNormalizer,
        LeadQualityService $qualityService,
        WebsiteEmailExtractorService $emailExtractor
    ) {
        $this->phoneNormalizer = $phoneNormalizer;
        $this->qualityService = $qualityService;
        $this->emailExtractor = $emailExtractor;
    }

    /**
     * Infer human-like contact type from title or email
     */
    public function inferContactType(?string $jobTitle, ?string $email): string
    {
        $text = strtolower(trim(($jobTitle ?? '') . ' ' . ($email ?? '')));
        if (str_contains($text, 'founder') || str_contains($text, 'co-founder')) return 'Founder';
        if (str_contains($text, 'ceo') || str_contains($text, 'chief executive')) return 'CEO';
        if (str_contains($text, 'owner') || str_contains($text, 'principal') || str_contains($text, 'dr.') || str_contains($text, 'doctor')) return 'Owner';
        if (str_contains($text, 'director') || str_contains($text, 'md')) return 'Director';
        if (str_contains($text, 'manager') || str_contains($text, 'lead') || str_contains($text, 'head')) return 'Manager';
        if (str_contains($text, 'marketing') || str_contains($text, 'growth') || str_contains($text, 'brand')) return 'Marketing';
        if (str_contains($text, 'sales') || str_contains($text, 'bd') || str_contains($text, 'business dev')) return 'Sales';
        if (str_contains($text, 'reception') || str_contains($text, 'frontdesk') || str_contains($text, 'desk')) return 'Reception';
        if (str_contains($text, 'info@') || str_contains($text, 'contact@') || str_contains($text, 'hello@') || str_contains($text, 'support@') || str_contains($text, 'office@')) return 'General';
        return 'General';
    }

    /**
     * Split a full name into first and last name
     */
    public function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);
        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * Find or create/enrich a Business record with deduplication (Requirement 3 & 8)
     */
    public function findOrCreateBusiness(int $tenantId, ScrapedBusiness $business, ?int $categoryId = null): array
    {
        $phoneNorm = $business->phone_normalized ?: $this->phoneNormalizer->normalizeE164($business->phone);
        $placeId = !empty($business->google_place_id) ? trim($business->google_place_id) : null;
        $normName = !empty($business->normalized_business_name)
            ? trim($business->normalized_business_name)
            : strtolower(trim($business->business_name));

        // Extract city from address if possible
        $city = null;
        if (!empty($business->address)) {
            $parts = array_map('trim', explode(',', $business->address));
            if (count($parts) >= 2) {
                $city = $parts[count($parts) - 2];
            }
        }

        $matchedBusiness = null;

        // 1. Google Place ID
        if (!empty($placeId)) {
            $matchedBusiness = Business::where('tenant_id', $tenantId)
                ->where('google_place_id', $placeId)
                ->first();
        }

        // 2. Normalized Phone
        if (!$matchedBusiness && !empty($phoneNorm)) {
            $matchedBusiness = Business::where('tenant_id', $tenantId)
                ->where('normalized_phone', $phoneNorm)
                ->first();
        }

        // 3. Normalized business name + city
        if (!$matchedBusiness && !empty($normName) && !empty($city)) {
            $matchedBusiness = Business::where('tenant_id', $tenantId)
                ->where('normalized_business_name', $normName)
                ->where('city', $city)
                ->first();
        }

        $wasCreated = false;
        $wasEnriched = false;

        if ($matchedBusiness) {
            $updates = [];
            if (empty($matchedBusiness->website) && !empty($business->website)) {
                $updates['website'] = $business->website;
                $updates['website_domain'] = $business->website_domain ?: $this->emailExtractor->extractDomain($business->website);
            }
            if (empty($matchedBusiness->phone) && !empty($business->phone)) {
                $updates['phone'] = $business->phone;
                $updates['normalized_phone'] = $phoneNorm;
                $updates['phone_original'] = $business->phone;
            }
            if (empty($matchedBusiness->address) && !empty($business->address)) {
                $updates['address'] = $business->address;
                if (!empty($city)) {
                    $updates['city'] = $city;
                }
            }
            if (empty($matchedBusiness->google_place_id) && !empty($placeId)) {
                $updates['google_place_id'] = $placeId;
            }
            if (empty($matchedBusiness->google_maps_url) && !empty($business->google_maps_url)) {
                $updates['google_maps_url'] = $business->google_maps_url;
            }
            if ($matchedBusiness->rating === null && $business->rating !== null) {
                $updates['rating'] = $business->rating;
            }
            if ($matchedBusiness->review_count === null && $business->review_count !== null) {
                $updates['review_count'] = $business->review_count;
            }
            $updates['last_discovered_at'] = now();

            if (!empty($updates)) {
                $matchedBusiness->update($updates);
                $wasEnriched = true;
            }
        } else {
            $matchedBusiness = Business::create([
                'tenant_id' => $tenantId,
                'business_name' => $business->business_name,
                'normalized_business_name' => $normName,
                'website' => $business->website,
                'website_domain' => $business->website_domain ?: ($business->website ? $this->emailExtractor->extractDomain($business->website) : null),
                'phone' => $business->phone,
                'normalized_phone' => $phoneNorm,
                'phone_original' => $business->phone,
                'address' => $business->address,
                'city' => $city,
                'google_place_id' => $placeId,
                'google_maps_url' => $business->google_maps_url,
                'rating' => $business->rating,
                'review_count' => $business->review_count,
                'source' => $business->source ?: 'Business Scraper',
                'first_discovered_at' => now(),
                'last_discovered_at' => now(),
            ]);
            $wasCreated = true;
        }

        // Link category to business (many-to-many)
        if ($categoryId) {
            $matchedBusiness->categories()->syncWithoutDetaching([$categoryId]);
        }

        return [
            'business' => $matchedBusiness,
            'created' => $wasCreated,
            'enriched' => $wasEnriched,
        ];
    }

    /**
     * Find existing contact following strict matching priority (Requirement 8):
     * 1. normalized phone
     * 2. normalized email
     * 3. existing business + email
     * 4. existing business + phone
     */
    public function findExistingContactAdvanced(
        int $tenantId,
        ?string $phoneNorm,
        ?string $emailNorm,
        ?int $businessId = null,
        ?string $placeId = null
    ): ?Contact {
        // 1. PRIMARY: Normalized phone number
        if (!empty($phoneNorm)) {
            $contact = Contact::where('tenant_id', $tenantId)
                ->where(function ($q) use ($phoneNorm) {
                    $q->where('phone_normalized', $phoneNorm)
                      ->orWhere('secondary_phone_normalized', $phoneNorm);
                })
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        // 2. SECONDARY: Normalized email
        if (!empty($emailNorm)) {
            $contact = Contact::where('tenant_id', $tenantId)
                ->where(function ($q) use ($emailNorm) {
                    $q->where('email_normalized', $emailNorm)
                      ->orWhere('email', $emailNorm)
                      ->orWhere('secondary_email', $emailNorm);
                })
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        // 3. TERTIARY: existing business + email
        if ($businessId && !empty($emailNorm)) {
            $contact = Contact::where('tenant_id', $tenantId)
                ->where('business_id', $businessId)
                ->where(function ($q) use ($emailNorm) {
                    $q->where('email_normalized', $emailNorm)
                      ->orWhere('email', $emailNorm);
                })
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        // 4. QUATERNARY: existing business + phone
        if ($businessId && !empty($phoneNorm)) {
            $contact = Contact::where('tenant_id', $tenantId)
                ->where('business_id', $businessId)
                ->where('phone_normalized', $phoneNorm)
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        // 5. FALLBACK: Google Place ID
        if (!empty($placeId)) {
            $contact = Contact::where('tenant_id', $tenantId)
                ->where('google_place_id', $placeId)
                ->first();

            if ($contact) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Backward-compatible findExistingContact wrapper
     */
    public function findExistingContact(int $tenantId, ScrapedBusiness $business): ?Contact
    {
        $phoneNorm = $business->phone_normalized ?: $this->phoneNormalizer->normalizeE164($business->phone);
        $emailNorm = !empty($business->email_normalized) ? strtolower(trim($business->email_normalized)) : (
            !empty($business->email) ? strtolower(trim($business->email)) : null
        );

        return $this->findExistingContactAdvanced($tenantId, $phoneNorm, $emailNorm, null, $business->google_place_id);
    }

    /**
     * Upsert a single scraped business into CRM Contact & Business (Backward compatible)
     */
    public function upsertBusiness(int $tenantId, int $userId, ScrapedBusiness $business, string $source = 'Business Scraper'): array
    {
        if (empty($business->phone_normalized) && !empty($business->phone)) {
            $business->phone_normalized = $this->phoneNormalizer->normalizeE164($business->phone);
            $business->phone_original = $business->phone;
            $business->save();
        }

        // Resolve Category
        $categoryId = null;
        if (!empty($business->category)) {
            $cat = Category::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => trim($business->category)],
                ['slug' => Str::slug($business->category)]
            );
            $categoryId = $cat->id;
        }

        // 1. Upsert Business
        $bizResult = $this->findOrCreateBusiness($tenantId, $business, $categoryId);
        $bizModel = $bizResult['business'];

        // 2. Upsert Contact
        $phoneNorm = $business->phone_normalized ?: $this->phoneNormalizer->normalizeE164($business->phone);
        $emailNorm = !empty($business->email_normalized) ? strtolower(trim($business->email_normalized)) : (
            !empty($business->email) ? strtolower(trim($business->email)) : null
        );

        $matchedContact = $this->findExistingContactAdvanced($tenantId, $phoneNorm, $emailNorm, $bizModel->id, $business->google_place_id);

        if ($matchedContact) {
            $enriched = $this->enrichExistingContact($matchedContact, $business, $source);
            $matchedContact->update(['business_id' => $bizModel->id]);
            if ($categoryId) {
                $matchedContact->categories()->syncWithoutDetaching([$categoryId]);
            }
            $this->recordScrapeResult($tenantId, $business->search_id, $bizModel->id, $matchedContact->id, $categoryId, $source);
            return $enriched;
        }

        $created = $this->createNewContact($tenantId, $userId, $business, $source);
        if (!empty($created['contact'])) {
            $created['contact']->update(['business_id' => $bizModel->id]);
            if ($categoryId) {
                $created['contact']->categories()->syncWithoutDetaching([$categoryId]);
            }
            $this->recordScrapeResult($tenantId, $business->search_id, $bizModel->id, $created['contact']->id, $categoryId, $source);
        }

        return $created;
    }

    /**
     * Helper to upsert both business and contacts with category association
     */
    public function upsertBusinessAndContacts(
        int $tenantId,
        ScrapedBusiness $business,
        ?int $categoryId = null,
        ?int $searchId = null,
        int $userId = 1
    ): array {
        if ($categoryId) {
            $cat = Category::find($categoryId);
            if ($cat) {
                $business->category = $cat->name;
            }
        }
        if ($searchId) {
            $business->search_id = $searchId;
        }

        $result = $this->upsertBusiness($tenantId, $userId, $business);

        $phoneNorm = $business->phone_normalized ?: $this->phoneNormalizer->normalizeE164($business->phone);
        $biz = Business::where('tenant_id', $tenantId)
            ->where(function ($q) use ($business, $phoneNorm) {
                if ($phoneNorm) {
                    $q->where('normalized_phone', $phoneNorm);
                } elseif ($business->google_place_id) {
                    $q->where('google_place_id', $business->google_place_id);
                } else {
                    $q->where('business_name', $business->business_name);
                }
            })->first();

        return [
            'contact' => $result['contact'] ?? null,
            'business' => $biz,
            'action' => $result['action'] ?? 'created',
        ];
    }

    /**
     * Record discovery in scrape_results for provenance history (Requirement 7)
     */
    public function recordScrapeResult(
        int $tenantId,
        ?int $searchId,
        ?int $businessId,
        ?int $contactId,
        ?int $categoryId,
        string $source = 'Google Maps'
    ): void {
        if (!$searchId && !$businessId && !$contactId) {
            return;
        }

        try {
            ScrapeResult::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'scrape_search_id' => $searchId ?: 0,
                    'business_id' => $businessId,
                    'contact_id' => $contactId,
                ],
                [
                    'category_id' => $categoryId,
                    'discovery_source' => $source,
                    'discovered_at' => now(),
                    'enrichment_status' => 'COMPLETED',
                ]
            );
        } catch (\Throwable $e) {
            Log::channel('scraper')->warning("ScrapeResult record skipped: " . $e->getMessage());
        }
    }

    /**
     * Enrich existing contact without losing valid data (Requirement 8)
     */
    protected function enrichExistingContact(Contact $contact, ScrapedBusiness $business, string $source): array
    {
        $changes = [];
        $sourceDetails = is_array($contact->source_details) ? $contact->source_details : [];

        DB::transaction(function () use ($contact, $business, $source, &$changes, &$sourceDetails) {
            // Field: Name
            if (empty($contact->name) || strtolower(trim($contact->name)) === 'unknown business') {
                if (!empty($business->business_name) && strtolower(trim($business->business_name)) !== 'unknown business') {
                    $old = $contact->name;
                    $contact->name = $business->business_name;
                    $contact->company = $business->business_name;
                    $changes['name'] = ['old' => $old, 'new' => $contact->name];
                    $sourceDetails['name'] = $source;
                    $this->logChange($contact, $business, 'name', $old, $contact->name, $source);
                }
            }

            // Field: Phone
            $newPhoneNorm = $business->phone_normalized ?: $this->phoneNormalizer->normalizeE164($business->phone);
            if (empty($contact->phone_normalized) && !empty($newPhoneNorm)) {
                $old = $contact->phone;
                $contact->phone = $business->phone ?: $newPhoneNorm;
                $contact->phone_original = $business->phone;
                $contact->phone_normalized = $newPhoneNorm;
                $changes['phone'] = ['old' => $old, 'new' => $newPhoneNorm];
                $sourceDetails['phone'] = $source;
                $this->logChange($contact, $business, 'phone', $old, $newPhoneNorm, $source);
            } elseif (!empty($newPhoneNorm) && $contact->phone_normalized !== $newPhoneNorm) {
                if (empty($contact->secondary_phone_normalized)) {
                    $old = $contact->secondary_phone;
                    $contact->secondary_phone = $business->phone ?: $newPhoneNorm;
                    $contact->secondary_phone_normalized = $newPhoneNorm;
                    $changes['secondary_phone'] = ['old' => $old, 'new' => $newPhoneNorm];
                    $sourceDetails['secondary_phone'] = $source;
                    $this->logChange($contact, $business, 'secondary_phone', $old, $newPhoneNorm, $source);
                }
            }

            // Field: Email
            $newEmail = !empty($business->email_normalized) ? strtolower(trim($business->email_normalized)) : (
                !empty($business->email) ? strtolower(trim($business->email)) : null
            );
            if (!empty($newEmail)) {
                if (empty($contact->email)) {
                    $old = $contact->email;
                    $contact->email = $newEmail;
                    $contact->email_normalized = $newEmail;
                    $changes['email'] = ['old' => $old, 'new' => $newEmail];
                    $sourceDetails['email'] = $business->email_source ?: 'Website Scrape';
                    $this->logChange($contact, $business, 'email', $old, $newEmail, $sourceDetails['email']);
                } elseif (strtolower(trim($contact->email)) !== $newEmail) {
                    if (empty($contact->secondary_email)) {
                        $old = $contact->secondary_email;
                        $contact->secondary_email = $newEmail;
                        $changes['secondary_email'] = ['old' => $old, 'new' => $newEmail];
                        $sourceDetails['secondary_email'] = $business->email_source ?: 'Website Scrape';
                        $this->logChange($contact, $business, 'secondary_email', $old, $newEmail, $sourceDetails['secondary_email']);
                    }
                }
            }

            // Field: Website & Domain
            if (!empty($business->website)) {
                if (empty($contact->website)) {
                    $old = $contact->website;
                    $contact->website = $business->website;
                    $contact->website_domain = $business->website_domain ?: $this->emailExtractor->extractDomain($business->website);
                    $changes['website'] = ['old' => $old, 'new' => $contact->website];
                    $sourceDetails['website'] = 'Google Places';
                    $this->logChange($contact, $business, 'website', $old, $contact->website, 'Google Places');
                }
            }

            // Field: Address
            if (empty($contact->address) && !empty($business->address)) {
                $old = $contact->address;
                $contact->address = $business->address;
                $changes['address'] = ['old' => $old, 'new' => $contact->address];
                $sourceDetails['address'] = 'Google Places';
                $this->logChange($contact, $business, 'address', $old, $contact->address, 'Google Places');
            }

            // Field: Google Place ID
            if (empty($contact->google_place_id) && !empty($business->google_place_id)) {
                $old = $contact->google_place_id;
                $contact->google_place_id = $business->google_place_id;
                $changes['google_place_id'] = ['old' => $old, 'new' => $contact->google_place_id];
                $sourceDetails['google_place_id'] = 'Google Places';
                $this->logChange($contact, $business, 'google_place_id', $old, $contact->google_place_id, 'Google Places');
            }

            // Recalculate lead quality score
            $quality = $this->qualityService->calculate($contact);
            $contact->lead_quality_score = $quality['score'];
            $contact->lead_quality_grade = $quality['grade'];
            $contact->enrichment_status = $quality['enrichment_status'];
            $contact->missing_fields = $quality['missing_fields'];
            $contact->source_details = $sourceDetails;
            $contact->last_enriched_at = now();
            $contact->enrichment_attempts += 1;
            $contact->save();

            // Sync with scraped_business record
            $business->crm_contact_id = $contact->id;
            $business->is_imported_to_crm = true;
            $business->last_enriched_at = now();
            $business->save();
        });

        return [
            'action' => 'enriched',
            'contact' => $contact->fresh(),
            'changes' => $changes,
        ];
    }

    /**
     * Create brand new contact safely (Requirement 4)
     */
    protected function createNewContact(int $tenantId, int $userId, ScrapedBusiness $business, string $source): array
    {
        $phoneNorm = $business->phone_normalized ?: $this->phoneNormalizer->normalizeE164($business->phone);
        $domain = $business->website_domain ?: ($business->website ? $this->emailExtractor->extractDomain($business->website) : null);
        $emailNorm = !empty($business->email_normalized) ? strtolower(trim($business->email_normalized)) : (
            !empty($business->email) ? strtolower(trim($business->email)) : null
        );

        $nameParts = $this->splitName($business->business_name);
        $contactType = $this->inferContactType(null, $emailNorm);

        $tempData = [
            'business_name' => $business->business_name,
            'phone_normalized' => $phoneNorm,
            'email_normalized' => $emailNorm,
            'website' => $business->website,
            'address' => $business->address,
            'category' => $business->category,
            'google_place_id' => $business->google_place_id,
            'rating' => $business->rating,
            'review_count' => $business->review_count,
        ];
        $quality = $this->qualityService->calculate($tempData);

        $sourceDetails = [
            'name' => 'Google Places',
            'phone' => 'Google Places',
            'website' => !empty($business->website) ? 'Google Places' : null,
            'email' => !empty($emailNorm) ? ($business->email_source ?: 'Website Scrape') : null,
            'address' => !empty($business->address) ? 'Google Places' : null,
        ];

        try {
            $contact = DB::transaction(function () use ($tenantId, $business, $phoneNorm, $domain, $emailNorm, $quality, $source, $sourceDetails, $nameParts, $contactType) {
                $c = Contact::create([
                    'tenant_id' => $tenantId,
                    'name' => $business->business_name,
                    'first_name' => $nameParts['first_name'],
                    'last_name' => $nameParts['last_name'],
                    'phone' => $business->phone ?? ($phoneNorm ?? ''),
                    'phone_normalized' => $phoneNorm,
                    'phone_original' => $business->phone,
                    'email' => $emailNorm,
                    'email_normalized' => $emailNorm,
                    'website' => $business->website,
                    'website_domain' => $domain,
                    'google_place_id' => $business->google_place_id,
                    'company' => $business->business_name,
                    'contact_type' => $contactType,
                    'address' => $business->address,
                    'notes' => "Discovered via Business Scraper.\nCategory: " . ($business->category ?? 'N/A') . "\nAddress: " . ($business->address ?? 'N/A'),
                    'status' => 'active',
                    'lead_quality_score' => $quality['score'],
                    'lead_quality_grade' => $quality['grade'],
                    'enrichment_status' => $quality['enrichment_status'],
                    'missing_fields' => $quality['missing_fields'],
                    'source' => $source,
                    'source_details' => array_filter($sourceDetails),
                    'last_scraped_at' => now(),
                    'last_enriched_at' => now(),
                    'enrichment_attempts' => 1,
                ]);

                $this->logChange($c, $business, 'contact_created', null, 'Contact created from business discovery', $source);

                $business->crm_contact_id = $c->id;
                $business->is_imported_to_crm = true;
                $business->lead_quality_score = $quality['score'];
                $business->lead_quality_grade = $quality['grade'];
                $business->enrichment_status = $quality['enrichment_status'];
                $business->missing_fields = $quality['missing_fields'];
                $business->last_enriched_at = now();
                $business->save();

                return $c;
            });

            return [
                'action' => 'created',
                'contact' => $contact,
                'changes' => [],
            ];
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062 && !empty($phoneNorm)) {
                Log::channel('scraper')->warning("Race condition caught on phone_normalized: {$phoneNorm}. Falling back to enrich.");
                $existing = Contact::where('tenant_id', $tenantId)
                    ->where('phone_normalized', $phoneNorm)
                    ->first();
                if ($existing) {
                    return $this->enrichExistingContact($existing, $business, $source);
                }
            }
            throw $e;
        }
    }

    /**
     * Import scraper data into CRM with full UPSERT / ENRICH and detailed summary (Requirement 18)
     */
    public function upsertMany(int $tenantId, int $userId, array $businessIds = [], ?int $searchId = null, array $filters = []): array
    {
        $tenant = Tenant::find($tenantId);
        $maxNum = null;
        if ($tenant) {
            $limits = $tenant->getLimits();
            $maxContacts = $limits['contacts'] ?? 'Unlimited';
            if (strtolower((string) $maxContacts) !== 'unlimited') {
                $maxNum = (int) str_replace([',', ' '], '', (string) $maxContacts);
            }
        }

        $query = ScrapedBusiness::where('tenant_id', $tenantId);
        if (!empty($businessIds)) {
            $query->whereIn('id', $businessIds);
        } else {
            if ($searchId) {
                $query->where('search_id', $searchId);
            }

            $tab = $filters['tab'] ?? null;
            if ($tab === 'email_found') {
                $query->whereNotNull('email')->where('email', '!=', '');
            } elseif ($tab === 'no_email') {
                $query->where(function ($q) {
                    $q->whereNull('email')->orWhere('email', '');
                })->where('status', '!=', 'failed');
            } elseif ($tab === 'website_found' || $tab === 'has_website') {
                $query->whereNotNull('website')->where('website', '!=', '');
            } elseif ($tab === 'no_website') {
                $query->where(function ($q) {
                    $q->whereNull('website')->orWhere('website', '');
                });
            } elseif ($tab === 'not_in_crm') {
                $query->where(function ($q) {
                    $q->whereNull('is_imported_to_crm')->orWhere('is_imported_to_crm', false);
                });
            } elseif ($tab === 'in_crm') {
                $query->where('is_imported_to_crm', true);
            }

            if (!empty($filters['search'])) {
                $term = $filters['search'];
                $query->where(function ($q) use ($term) {
                    $q->where('business_name', 'like', "%{$term}%")
                      ->orWhere('email', 'like', "%{$term}%")
                      ->orWhere('phone', 'like', "%{$term}%")
                      ->orWhere('category', 'like', "%{$term}%")
                      ->orWhere('address', 'like', "%{$term}%")
                      ->orWhere('website', 'like', "%{$term}%");
                });
            }

            if (!empty($filters['unimported_only'])) {
                $query->where(function ($q) {
                    $q->whereNull('is_imported_to_crm')->orWhere('is_imported_to_crm', false);
                });
            }
        }

        $businesses = $query->get();

        $totalDiscovered = $businesses->count();
        $alreadyExisting = 0;
        $newBusinesses = 0;
        $existingBusinessesEnriched = 0;
        $newContacts = 0;
        $existingContactsEnriched = 0;
        $duplicatesPrevented = 0;
        $failedCount = 0;
        $errors = [];

        $currentCount = Contact::where('tenant_id', $tenantId)->count();

        // Resolve search object for category inheritance
        $searchObj = $searchId ? ScraperSearch::find($searchId) : null;

        foreach ($businesses as $biz) {
            try {
                // Determine Category
                $categoryId = null;
                if ($searchObj && $searchObj->category_id) {
                    $categoryId = $searchObj->category_id;
                } elseif (!empty($biz->category)) {
                    $cat = Category::firstOrCreate(
                        ['tenant_id' => $tenantId, 'name' => trim($biz->category)],
                        ['slug' => Str::slug($biz->category)]
                    );
                    $categoryId = $cat->id;
                }

                // 1. Separate Business Record Handling
                $bizRes = $this->findOrCreateBusiness($tenantId, $biz, $categoryId);
                $businessModel = $bizRes['business'];

                if ($bizRes['created']) {
                    $newBusinesses++;
                } elseif ($bizRes['enriched']) {
                    $existingBusinessesEnriched++;
                    $alreadyExisting++;
                    $duplicatesPrevented++;
                } else {
                    $alreadyExisting++;
                    $duplicatesPrevented++;
                }

                // 2. Separate Contact Record(s) Handling
                // Check if multiple emails exist in scraper_business_emails
                $extraEmails = ScraperBusinessEmail::where('scraped_business_id', $biz->id)->get();
                $allEmailsToProcess = [];

                if ($extraEmails->isNotEmpty()) {
                    foreach ($extraEmails as $ee) {
                        $allEmailsToProcess[] = [
                            'email' => $ee->email,
                            'source' => $ee->source ?: 'Website Scrape',
                            'is_primary' => $ee->is_primary,
                        ];
                    }
                } elseif (!empty($biz->email)) {
                    $allEmailsToProcess[] = [
                        'email' => $biz->email,
                        'source' => $biz->email_source ?: 'Website Scrape',
                        'is_primary' => true,
                    ];
                } else {
                    // Contact with phone only
                    $allEmailsToProcess[] = [
                        'email' => null,
                        'source' => 'Google Places',
                        'is_primary' => true,
                    ];
                }

                foreach ($allEmailsToProcess as $emData) {
                    $emailNorm = !empty($emData['email']) ? strtolower(trim($emData['email'])) : null;
                    $phoneNorm = $biz->phone_normalized ?: $this->phoneNormalizer->normalizeE164($biz->phone);

                    // Strict deduplication matching priority (Requirement 8)
                    $matchedContact = $this->findExistingContactAdvanced(
                        $tenantId,
                        $phoneNorm,
                        $emailNorm,
                        $businessModel->id,
                        $biz->google_place_id
                    );

                    if ($matchedContact) {
                        // Enrich existing contact
                        $res = $this->enrichExistingContact($matchedContact, $biz, 'Scraper Import');
                        $matchedContact->update(['business_id' => $businessModel->id]);
                        if ($categoryId) {
                            $matchedContact->categories()->syncWithoutDetaching([$categoryId]);
                        }
                        if (!empty($res['changes'])) {
                            $existingContactsEnriched++;
                        }
                        $alreadyExisting++;
                        $duplicatesPrevented++;

                        $this->recordScrapeResult($tenantId, $biz->search_id, $businessModel->id, $matchedContact->id, $categoryId);
                        continue;
                    }

                    // Quota check before creating new contact
                    if ($maxNum !== null && ($currentCount + $newContacts) >= $maxNum) {
                        $errors[] = "Plan contact limit of {$maxNum} reached. Skipped contact creation for {$biz->business_name}.";
                        continue;
                    }

                    // Create new contact
                    $nameParts = $this->splitName($biz->business_name);
                    $contactType = $this->inferContactType(null, $emailNorm);

                    $newContact = Contact::create([
                        'tenant_id' => $tenantId,
                        'business_id' => $businessModel->id,
                        'name' => $biz->business_name,
                        'first_name' => $nameParts['first_name'],
                        'last_name' => $nameParts['last_name'],
                        'phone' => $biz->phone ?? ($phoneNorm ?? ''),
                        'phone_normalized' => $phoneNorm,
                        'phone_original' => $biz->phone,
                        'email' => $emailNorm,
                        'email_normalized' => $emailNorm,
                        'website' => $biz->website,
                        'website_domain' => $businessModel->website_domain,
                        'google_place_id' => $biz->google_place_id,
                        'company' => $biz->business_name,
                        'contact_type' => $contactType,
                        'address' => $biz->address,
                        'status' => 'active',
                        'source' => 'Business Scraper',
                        'last_scraped_at' => now(),
                        'last_enriched_at' => now(),
                        'enrichment_attempts' => 1,
                    ]);

                    if ($categoryId) {
                        $newContact->categories()->syncWithoutDetaching([$categoryId]);
                    }

                    $newContacts++;

                    $this->recordScrapeResult($tenantId, $biz->search_id, $businessModel->id, $newContact->id, $categoryId);
                }

                $biz->is_imported_to_crm = true;
                $biz->save();

            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = "Error on '{$biz->business_name}': " . $e->getMessage();
                Log::channel('scraper')->error("Upsert failed for business {$biz->id}: " . $e->getMessage());
            }
        }

        $finalUnique = Contact::where('tenant_id', $tenantId)->count();

        return [
            'success' => true,
            'discovered' => $totalDiscovered,
            'already_existing' => $alreadyExisting,
            'new_businesses' => $newBusinesses,
            'existing_businesses_enriched' => $existingBusinessesEnriched,
            'new_contacts' => $newContacts,
            'existing_contacts_enriched' => $existingContactsEnriched,
            'duplicates_prevented' => $duplicatesPrevented,
            'failed' => $failedCount,
            'final_unique' => $finalUnique,
            // Aliases for UI backward compatibility
            'total' => $totalDiscovered,
            'created' => $newContacts,
            'enriched' => $existingContactsEnriched,
            'already_up_to_date' => $alreadyExisting,
            'errors' => $errors,
        ];
    }

    /**
     * Targeted enrichment for a single business (e.g. missing email)
     */
    public function enrichMissingData(int $tenantId, int $userId, int $scrapedBusinessId): array
    {
        $business = ScrapedBusiness::where('tenant_id', $tenantId)->findOrFail($scrapedBusinessId);

        $missing = [];
        if (empty($business->email)) $missing[] = 'email';
        if (empty($business->website)) $missing[] = 'website';
        if (empty($business->phone)) $missing[] = 'phone';

        $enrichedFields = [];

        if (in_array('email', $missing) && !empty($business->website)) {
            $extracted = $this->emailExtractor->extractFromUrl($business->website);
            if (!empty($extracted['email'])) {
                $business->email = $extracted['email'];
                $business->email_normalized = strtolower(trim($extracted['email']));
                $business->email_source = 'Website Scrape (Enriched)';
                $enrichedFields[] = 'email';
            }
        }

        $business->last_enriched_at = now();
        $business->enrichment_attempts += 1;
        $business->save();

        if ($business->crm_contact_id) {
            $contact = Contact::where('tenant_id', $tenantId)->find($business->crm_contact_id);
            if ($contact) {
                $this->enrichExistingContact($contact, $business, 'Scraper Enrichment');
            }
        }

        return [
            'success' => true,
            'message' => count($enrichedFields) > 0 ? 'Lead successfully enriched.' : 'No new data found during enrichment.',
            'enriched_fields' => $enrichedFields,
            'business' => $business,
        ];
    }

    /**
     * Audit log helper
     */
    protected function logChange(Contact $contact, ScrapedBusiness $business, string $fieldName, ?string $oldVal, ?string $newVal, string $source): void
    {
        try {
            ContactEnrichmentLog::create([
                'tenant_id' => $contact->tenant_id,
                'contact_id' => $contact->id,
                'scraped_business_id' => $business->id,
                'search_id' => $business->search_id,
                'field_name' => $fieldName,
                'old_value' => $oldVal,
                'new_value' => $newVal,
                'source' => $source,
            ]);
        } catch (\Throwable $e) {
            Log::channel('scraper')->warning("Failed to write contact enrichment log: " . $e->getMessage());
        }
    }
}
