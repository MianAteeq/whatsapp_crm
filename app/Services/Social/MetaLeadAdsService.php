<?php

namespace App\Services\Social;

use App\Models\Contact;
use App\Models\MetaLeadForm;
use App\Models\MetaLeadSubmission;
use App\Models\SocialAccount;
use App\Models\Tag;
use App\Services\Workflow\WorkflowTriggerManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class MetaLeadAdsService
{
    public function __construct(
        protected MetaGraphApiService $metaApi
    ) {}

    /**
     * Get all connected Facebook Pages and Instagram accounts with Lead Ads capability & permission status
     */
    public function getConnectedAccountsWithStatus(int $tenantId): array
    {
        $accounts = SocialAccount::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $result = [];

        foreach ($accounts as $acc) {
            $perms = ['has_leads_retrieval' => false, 'has_pages_manage_ads' => false, 'granted' => []];

            if ($acc->platform === 'facebook' && !empty($acc->access_token)) {
                $perms = Cache::remember("meta_token_perms_{$acc->id}", now()->addMinutes(15), function () use ($acc) {
                    $checked = $this->metaApi->checkTokenPermissions($acc->access_token);
                    
                    $tasks = is_array($acc->metadata['tasks'] ?? null) ? $acc->metadata['tasks'] : [];
                    if (in_array('MANAGE_LEADS', $tasks)) {
                        $checked['has_leads_retrieval'] = true;
                    }
                    if (in_array('ADVERTISE', $tasks)) {
                        $checked['has_pages_manage_ads'] = true;
                    }
                    
                    return $checked;
                });
            }

            // Fallback: If metadata tasks contain MANAGE_LEADS, grant readiness even if debugToken had network hiccup
            $tasks = is_array($acc->metadata['tasks'] ?? null) ? $acc->metadata['tasks'] : [];
            $hasLeadsTask = in_array('MANAGE_LEADS', $tasks);
            $hasLeadsRetrieval = ($perms['has_leads_retrieval'] ?? false) || $hasLeadsTask;
            $hasManageAds = ($perms['has_pages_manage_ads'] ?? false) || in_array('ADVERTISE', $tasks);

            $formsCount = MetaLeadForm::where('tenant_id', $tenantId)
                ->where('social_account_id', $acc->id)
                ->count();

            $result[] = [
                'id' => $acc->id,
                'platform' => $acc->platform,
                'account_id' => $acc->account_id,
                'name' => $acc->name,
                'username' => $acc->username,
                'profile_picture_url' => $acc->profile_picture_url,
                'category' => $acc->category,
                'forms_count' => $formsCount,
                'permissions' => [
                    'leads_retrieval' => $hasLeadsRetrieval,
                    'pages_manage_ads' => $hasManageAds,
                    'granted_list' => $perms['granted'] ?? [],
                ],
                'is_ready_for_lead_ads' => $acc->platform === 'facebook' && $hasLeadsRetrieval,
                'connected_at' => $acc->connected_at?->toIso8601String(),
            ];
        }

        return $result;
    }

    /**
     * Sync and discover Leadgen Forms from Meta Graph API for a specific Page
     */
    public function syncFormsForPage(int $tenantId, int $socialAccountId): array
    {
        $account = SocialAccount::where('tenant_id', $tenantId)
            ->where('id', $socialAccountId)
            ->firstOrFail();

        if ($account->platform !== 'facebook') {
            throw new Exception("Lead Ads forms belong to Facebook Pages. Please select a Facebook Page.");
        }

        $pageAccessToken = $account->access_token;
        if (empty($pageAccessToken)) {
            throw new Exception("Missing access token for Page: {$account->name}");
        }

        // 1. Subscribe Page to leadgen webhooks automatically
        $this->metaApi->subscribePageToWebhooks($account->account_id, $pageAccessToken);

        // 2. Fetch Leadgen Forms from Meta
        $metaForms = $this->metaApi->getLeadgenForms($account->account_id, $pageAccessToken);

        $syncedForms = [];

        foreach ($metaForms as $formItem) {
            $formId = (string) $formItem['id'];
            $name = (string) ($formItem['name'] ?? "Lead Form #{$formId}");
            $status = strtoupper((string) ($formItem['status'] ?? 'ACTIVE'));
            $locale = (string) ($formItem['locale'] ?? 'en_US');
            $leadsCount = (int) ($formItem['leads_count'] ?? 0);
            $questions = $formItem['questions'] ?? [];

            // Find or create local record
            $form = MetaLeadForm::where('tenant_id', $tenantId)
                ->where('form_id', $formId)
                ->first();

            if (!$form) {
                // Generate smart initial field mapping
                $defaultMapping = $this->generateDefaultFieldMapping($questions);

                $form = MetaLeadForm::create([
                    'tenant_id' => $tenantId,
                    'social_account_id' => $account->id,
                    'form_id' => $formId,
                    'page_id' => $account->account_id,
                    'name' => $name,
                    'status' => $status,
                    'locale' => $locale,
                    'questions' => $questions,
                    'crm_mapping' => $defaultMapping,
                    'rules' => [
                        'duplicate_strategy' => 'update',
                        'auto_tags' => ['Facebook Lead', 'Lead Ads'],
                        'assigned_user_id' => null,
                        'pipeline_id' => null,
                        'stage_id' => null,
                    ],
                    'is_active' => true,
                    'auto_sync' => true,
                    'submissions_count' => $leadsCount,
                    'last_synced_at' => now(),
                ]);
            } else {
                $form->update([
                    'name' => $name,
                    'status' => $status,
                    'locale' => $locale,
                    'questions' => $questions,
                    'submissions_count' => max($form->submissions_count, $leadsCount),
                    'last_synced_at' => now(),
                ]);
            }

            $syncedForms[] = $form;
        }

        return $syncedForms;
    }

    /**
     * Handle incoming real-time lead webhook notification
     */
    public function handleInboundLeadWebhook(array $data): ?MetaLeadSubmission
    {
        $leadgenId = (string) ($data['leadgen_id'] ?? '');
        $pageId = (string) ($data['page_id'] ?? '');
        $formId = (string) ($data['form_id'] ?? '');

        if (empty($leadgenId) || empty($pageId)) {
            Log::warning("[MetaLeadAds] Invalid webhook payload received: missing leadgen_id or page_id", $data);
            return null;
        }

        // 1. Resolve SocialAccount for this Facebook Page
        $account = SocialAccount::where('account_id', $pageId)
            ->where('platform', 'facebook')
            ->first();

        if (!$account) {
            Log::warning("[MetaLeadAds] Received leadgen event for unknown page_id: {$pageId}");
            return null;
        }

        $tenantId = (int) $account->tenant_id;

        // 2. Prevent duplicate processing
        $existing = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->where('leadgen_id', $leadgenId)
            ->first();

        if ($existing) {
            Log::info("[MetaLeadAds] Lead {$leadgenId} has already been ingested. Skipping duplicate webhook.");
            return $existing;
        }

        // 3. Retrieve raw lead details from Meta Graph API
        try {
            $rawLead = $this->metaApi->getLeadData($leadgenId, $account->access_token);
        } catch (\Throwable $e) {
            Log::error("[MetaLeadAds] Failed to fetch lead {$leadgenId} from Meta API: " . $e->getMessage());

            // Save failed submission record for visibility & retry
            return MetaLeadSubmission::create([
                'tenant_id' => $tenantId,
                'leadgen_id' => $leadgenId,
                'form_id' => $formId,
                'page_id' => $pageId,
                'ad_id' => $data['ad_id'] ?? null,
                'raw_payload' => ['error' => $e->getMessage(), 'webhook_data' => $data],
                'sync_status' => MetaLeadSubmission::STATUS_FAILED,
                'error_message' => "Meta Graph API retrieval failed: {$e->getMessage()}",
            ]);
        }

        // 4. Resolve or Auto-Create MetaLeadForm record
        $metaLeadForm = MetaLeadForm::where('tenant_id', $tenantId)
            ->where('form_id', $formId)
            ->first();

        if (!$metaLeadForm) {
            try {
                $formDetails = $this->metaApi->getLeadgenFormDetails($formId, $account->access_token);
                $questions = $formDetails['questions'] ?? [];
                $metaLeadForm = MetaLeadForm::create([
                    'tenant_id' => $tenantId,
                    'social_account_id' => $account->id,
                    'form_id' => $formId,
                    'page_id' => $pageId,
                    'name' => $formDetails['name'] ?? "Lead Form #{$formId}",
                    'status' => $formDetails['status'] ?? 'ACTIVE',
                    'locale' => $formDetails['locale'] ?? 'en_US',
                    'questions' => $questions,
                    'crm_mapping' => $this->generateDefaultFieldMapping($questions),
                    'rules' => [
                        'duplicate_strategy' => 'update',
                        'auto_tags' => ['Facebook Lead', 'Lead Ads'],
                    ],
                    'is_active' => true,
                    'auto_sync' => true,
                ]);
            } catch (\Throwable $fEx) {
                Log::warning("[MetaLeadAds] Could not fetch form details for {$formId}: " . $fEx->getMessage());
            }
        }

        // 5. Check if synchronization is paused
        $shouldSync = $metaLeadForm ? ($metaLeadForm->is_active && $metaLeadForm->auto_sync) : true;

        // 6. Process CRM Lead Conversion
        return $this->processLeadDataToSubmission(
            $tenantId,
            $account,
            $metaLeadForm,
            $leadgenId,
            $rawLead,
            $shouldSync
        );
    }

    /**
     * Process extracted lead payload into submission record and CRM Contact
     */
    public function processLeadDataToSubmission(
        int $tenantId,
        SocialAccount $account,
        ?MetaLeadForm $metaLeadForm,
        string $leadgenId,
        array $rawLead,
        bool $syncToCrm = true
    ): MetaLeadSubmission {
        return DB::transaction(function () use ($tenantId, $account, $metaLeadForm, $leadgenId, $rawLead, $syncToCrm) {
            $formId = (string) ($rawLead['form_id'] ?? $metaLeadForm?->form_id ?? '');
            $pageId = (string) $account->account_id;
            $adId = (string) ($rawLead['ad_id'] ?? '');
            $adName = $rawLead['ad_name'] ?? null;
            $adsetId = (string) ($rawLead['adset_id'] ?? '');
            $adsetName = $rawLead['adset_name'] ?? null;
            $campaignId = (string) ($rawLead['campaign_id'] ?? '');
            $campaignName = $rawLead['campaign_name'] ?? null;
            $platform = strtolower((string) ($rawLead['platform'] ?? 'facebook'));
            if ($platform !== 'instagram') $platform = 'facebook';

            // Extract field_data array
            $fieldData = $rawLead['field_data'] ?? [];
            $fieldKeyMap = [];
            foreach ($fieldData as $fd) {
                $fName = $fd['name'] ?? '';
                $fVals = $fd['values'] ?? [];
                $fieldKeyMap[$fName] = count($fVals) === 1 ? $fVals[0] : implode(', ', $fVals);
            }

            // Extract CRM mapping and rules
            $crmMapping = $metaLeadForm?->crm_mapping ?? [];
            $rules = $metaLeadForm?->rules ?? [];
            $duplicateStrategy = $rules['duplicate_strategy'] ?? 'update';

            // Map fields to CRM Contact model
            $mappedData = $this->mapLeadFieldsToContact($fieldKeyMap, $crmMapping);

            // Create submission record
            $submission = MetaLeadSubmission::create([
                'tenant_id' => $tenantId,
                'meta_lead_form_id' => $metaLeadForm?->id,
                'leadgen_id' => $leadgenId,
                'form_id' => $formId,
                'page_id' => $pageId,
                'ad_id' => $adId ?: null,
                'ad_name' => $adName,
                'adset_id' => $adsetId ?: null,
                'adset_name' => $adsetName,
                'campaign_id' => $campaignId ?: null,
                'campaign_name' => $campaignName,
                'platform' => $platform,
                'raw_payload' => $rawLead,
                'mapped_data' => $mappedData,
                'sync_status' => $syncToCrm ? MetaLeadSubmission::STATUS_PENDING : MetaLeadSubmission::STATUS_PENDING,
            ]);

            if (!$syncToCrm) {
                Log::info("[MetaLeadAds] Form {$formId} sync is paused. Submission saved as pending.");
                return $submission;
            }

            // Perform Contact deduplication & creation
            $contact = $this->syncToCrmContact(
                $tenantId,
                $mappedData,
                $duplicateStrategy,
                $platform,
                $metaLeadForm,
                [
                    'leadgen_id' => $leadgenId,
                    'form_id' => $formId,
                    'ad_id' => $adId,
                    'ad_name' => $adName,
                    'campaign_id' => $campaignId,
                    'campaign_name' => $campaignName,
                    'raw_fields' => $fieldKeyMap,
                ]
            );

            if ($contact) {
                // Attach auto-tags
                $autoTags = $rules['auto_tags'] ?? ['Facebook Lead'];
                if ($platform === 'instagram' && !in_array('Instagram Lead', $autoTags)) {
                    $autoTags[] = 'Instagram Lead';
                }
                if ($metaLeadForm && !in_array("Form: {$metaLeadForm->name}", $autoTags)) {
                    $autoTags[] = "Form: {$metaLeadForm->name}";
                }
                $this->attachTagsToContact($contact, $autoTags);

                // Update submission status
                $submission->update([
                    'crm_contact_id' => $contact->id,
                    'sync_status' => MetaLeadSubmission::STATUS_SYNCED,
                    'synced_at' => now(),
                ]);

                // Increment form submission count
                if ($metaLeadForm) {
                    $metaLeadForm->increment('submissions_count');
                    $metaLeadForm->update(['last_synced_at' => now()]);
                }

                // Trigger CRM automation workflow
                try {
                    WorkflowTriggerManager::dispatchEvent('contact_created', $tenantId, [
                        'contact' => $contact,
                        'source' => "lead_ads_{$platform}",
                        'form_id' => $formId,
                    ]);
                } catch (\Throwable $wEx) {
                    Log::warning("[MetaLeadAds] Workflow trigger notice: " . $wEx->getMessage());
                }

                Log::info("[MetaLeadAds] Successfully synced lead {$leadgenId} to Contact #{$contact->id}");
            } else {
                $submission->update([
                    'sync_status' => MetaLeadSubmission::STATUS_FAILED,
                    'error_message' => 'CRM Contact creation failed during field mapping.',
                ]);
            }

            return $submission;
        });
    }

    /**
     * Map Meta raw field key/value pairs to Contact model attributes
     */
    public function mapLeadFieldsToContact(array $fieldMap, array $crmMapping): array
    {
        $contact = [];

        // 1. Explicit user-configured mapping
        foreach ($crmMapping as $metaFieldKey => $crmField) {
            if (!empty($crmField) && isset($fieldMap[$metaFieldKey])) {
                $contact[$crmField] = $fieldMap[$metaFieldKey];
            }
        }

        // 2. Intelligent fallback heuristics for common standard Meta fields
        $heuristics = [
            'name' => ['full_name', 'name', 'contact_name', 'your_name'],
            'first_name' => ['first_name', 'fname'],
            'last_name' => ['last_name', 'lname'],
            'email' => ['email', 'email_address', 'work_email'],
            'phone' => ['phone_number', 'phone', 'mobile', 'whatsapp_number'],
            'company' => ['company_name', 'company', 'organization'],
            'job_title' => ['job_title', 'title', 'position'],
            'address' => ['street_address', 'address', 'city', 'location'],
        ];

        foreach ($heuristics as $column => $candidates) {
            if (empty($contact[$column])) {
                foreach ($candidates as $candidate) {
                    if (!empty($fieldMap[$candidate])) {
                        $contact[$column] = $fieldMap[$candidate];
                        break;
                    }
                }
            }
        }

        // Ensure name is assembled
        if (empty($contact['name'])) {
            if (!empty($contact['first_name']) || !empty($contact['last_name'])) {
                $contact['name'] = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
            } elseif (!empty($contact['email'])) {
                $contact['name'] = explode('@', $contact['email'])[0];
            } elseif (!empty($contact['phone'])) {
                $contact['name'] = 'Lead ' . substr($contact['phone'], -4);
            } else {
                $contact['name'] = 'Meta Lead #' . rand(1000, 9999);
            }
        }

        if (empty($contact['phone'])) {
            $contact['phone'] = 'N/A';
        }

        return $contact;
    }

    /**
     * Create or update CRM contact using normalized phone and email deduplication
     */
    protected function syncToCrmContact(
        int $tenantId,
        array $mappedData,
        string $duplicateStrategy,
        string $platform,
        ?MetaLeadForm $metaLeadForm,
        array $sourceMeta
    ): ?Contact {
        $email = $mappedData['email'] ?? null;
        $phone = $mappedData['phone'] ?? null;

        $existingContact = null;
        if ($duplicateStrategy !== 'create_always') {
            $existingContact = $this->findExistingContact($tenantId, $email, $phone);
        }

        if ($existingContact && $duplicateStrategy === 'skip') {
            Log::info("[MetaLeadAds] Skipping existing contact #{$existingContact->id} per skip strategy.");
            return $existingContact;
        }

        $sourceLabel = ($platform === 'instagram' ? 'Instagram Lead Ads' : 'Facebook Lead Ads') .
            ($metaLeadForm ? ": {$metaLeadForm->name}" : '');

        $contactAttributes = [
            'name' => $mappedData['name'],
            'first_name' => $mappedData['first_name'] ?? null,
            'last_name' => $mappedData['last_name'] ?? null,
            'email' => $email,
            'email_normalized' => $email ? strtolower(trim($email)) : null,
            'phone' => $phone,
            'phone_normalized' => $this->normalizePhone($phone),
            'company' => $mappedData['company'] ?? null,
            'job_title' => $mappedData['job_title'] ?? null,
            'address' => $mappedData['address'] ?? null,
            'contact_type' => 'Lead',
            'status' => 'active',
            'source' => $sourceLabel,
            'source_details' => $sourceMeta,
        ];

        if ($existingContact) {
            $existingDetails = $existingContact->source_details ?? [];
            if (!is_array($existingDetails)) $existingDetails = [];
            $existingDetails['last_meta_lead'] = $sourceMeta;
            $contactAttributes['source_details'] = $existingDetails;

            $existingContact->update(array_filter($contactAttributes, fn($v) => !is_null($v)));
            return $existingContact;
        }

        $contactAttributes['tenant_id'] = $tenantId;
        return Contact::create($contactAttributes);
    }

    /**
     * Find existing contact by email or normalized phone
     */
    protected function findExistingContact(int $tenantId, ?string $email, ?string $phone): ?Contact
    {
        if (!empty($email)) {
            $normalizedEmail = strtolower(trim($email));
            $found = Contact::where('tenant_id', $tenantId)
                ->where(function ($q) use ($email, $normalizedEmail) {
                    $q->where('email', $email)
                      ->orWhere('email_normalized', $normalizedEmail);
                })
                ->first();

            if ($found) return $found;
        }

        if (!empty($phone) && $phone !== 'N/A') {
            $normalizedPhone = $this->normalizePhone($phone);
            if (!empty($normalizedPhone)) {
                $found = Contact::where('tenant_id', $tenantId)
                    ->where(function ($q) use ($phone, $normalizedPhone) {
                        $q->where('phone', $phone)
                          ->orWhere('phone_normalized', $normalizedPhone);
                    })
                    ->first();

                if ($found) return $found;
            }
        }

        return null;
    }

    /**
     * Normalize phone numbers (strips formatting, spaces, dashes)
     */
    protected function normalizePhone(?string $phone): ?string
    {
        if (empty($phone) || $phone === 'N/A') return null;
        $digits = preg_replace('/[^0-9]/', '', $phone);
        return $digits ?: null;
    }

    /**
     * Attach tags to Contact
     */
    protected function attachTagsToContact(Contact $contact, array $tagNames): void
    {
        if (empty($tagNames)) return;

        $tagIds = [];
        foreach ($tagNames as $name) {
            $trimmed = trim((string) $name);
            if (empty($trimmed)) continue;

            $tag = Tag::firstOrCreate(['name' => $trimmed]);
            $tagIds[] = $tag->id;
        }

        if (!empty($tagIds)) {
            $contact->tags()->syncWithoutDetaching($tagIds);
        }
    }

    /**
     * Generate smart initial field mapping based on Meta form questions
     */
    protected function generateDefaultFieldMapping(array $questions): array
    {
        $mapping = [];

        foreach ($questions as $q) {
            $key = $q['key'] ?? '';
            $type = strtolower((string) ($q['type'] ?? ''));

            if (empty($key)) continue;

            if (str_contains($key, 'full_name') || str_contains($key, 'name')) {
                $mapping[$key] = 'name';
            } elseif (str_contains($key, 'email')) {
                $mapping[$key] = 'email';
            } elseif (str_contains($key, 'phone')) {
                $mapping[$key] = 'phone';
            } elseif (str_contains($key, 'company')) {
                $mapping[$key] = 'company';
            } elseif (str_contains($key, 'job_title') || str_contains($key, 'title')) {
                $mapping[$key] = 'job_title';
            } elseif (str_contains($key, 'city') || str_contains($key, 'street') || str_contains($key, 'address')) {
                $mapping[$key] = 'address';
            }
        }

        return $mapping;
    }

    /**
     * Import historical leads for a given Meta Lead Form
     */
    public function importHistoricalLeads(int $tenantId, int $formId, int $limit = 50): array
    {
        $metaLeadForm = MetaLeadForm::where('tenant_id', $tenantId)
            ->where('id', $formId)
            ->firstOrFail();

        $account = $metaLeadForm->socialAccount;
        if (!$account || empty($account->access_token)) {
            throw new Exception("Connected page access token is missing or expired.");
        }

        $res = $this->metaApi->getHistoricalLeads($metaLeadForm->form_id, $account->access_token, $limit);
        $leads = $res['data'] ?? [];

        $imported = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($leads as $rawLead) {
            $leadgenId = (string) ($rawLead['id'] ?? '');
            if (empty($leadgenId)) continue;

            // Check if already in submissions table
            $existing = MetaLeadSubmission::where('tenant_id', $tenantId)
                ->where('leadgen_id', $leadgenId)
                ->first();

            if ($existing) {
                $duplicates++;
                continue;
            }

            try {
                $sub = $this->processLeadDataToSubmission(
                    $tenantId,
                    $account,
                    $metaLeadForm,
                    $leadgenId,
                    $rawLead,
                    true
                );

                if ($sub->sync_status === MetaLeadSubmission::STATUS_SYNCED) {
                    $imported++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error("[MetaLeadAds] Historical lead import error: " . $e->getMessage());
                $failed++;
            }
        }

        return [
            'total_fetched' => count($leads),
            'imported' => $imported,
            'duplicates' => $duplicates,
            'failed' => $failed,
        ];
    }
}

