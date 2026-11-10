<?php

namespace App\Services\Builder;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\BuilderForm;
use App\Models\BuilderPage;
use App\Models\BuilderSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CrmLeadSyncService
{
    /**
     * Synchronize a submission into the CRM Contact management system
     */
    public function syncSubmissionToContact(
        int $tenantId,
        BuilderForm $form,
        array $submissionData,
        ?BuilderPage $page = null,
        ?array $meta = []
    ): ?Contact {
        try {
            return DB::transaction(function () use ($tenantId, $form, $submissionData, $page, $meta) {
                $mapping = $form->crm_mapping ?? [];
                $fieldMappings = $mapping['field_mappings'] ?? [];
                $duplicateStrategy = $mapping['duplicate_strategy'] ?? 'update'; // update, create_always, skip

                // 1. Extract contact fields from submissionData using field mappings or heuristics
                $mappedContact = $this->extractContactFields($submissionData, $fieldMappings);

                // Ensure a name exists
                if (empty($mappedContact['name'])) {
                    if (!empty($mappedContact['first_name']) || !empty($mappedContact['last_name'])) {
                        $mappedContact['name'] = trim(($mappedContact['first_name'] ?? '') . ' ' . ($mappedContact['last_name'] ?? ''));
                    } elseif (!empty($mappedContact['email'])) {
                        $mappedContact['name'] = explode('@', $mappedContact['email'])[0];
                    } elseif (!empty($mappedContact['phone'])) {
                        $mappedContact['name'] = 'Lead ' . substr($mappedContact['phone'], -4);
                    } else {
                        $mappedContact['name'] = 'Web Form Lead #' . rand(1000, 9999);
                    }
                }

                // Phone is a required field in the contacts table schema
                if (empty($mappedContact['phone'])) {
                    $mappedContact['phone'] = 'N/A';
                }

                // 2. Duplicate Detection (by email or phone)
                $existingContact = null;
                if ($duplicateStrategy !== 'create_always') {
                    $existingContact = $this->findExistingContact($tenantId, $mappedContact['email'] ?? null, $mappedContact['phone'] ?? null);
                }

                if ($existingContact && $duplicateStrategy === 'skip') {
                    Log::info("[CrmLeadSync] Skipping existing contact ID {$existingContact->id} as per form duplicate strategy.");
                    return $existingContact;
                }

                // 3. Prepare Source & Source Details
                $sourceDetails = [
                    'form_id' => $form->id,
                    'form_title' => $form->title,
                    'form_slug' => $form->slug,
                    'form_type' => $form->form_type,
                    'page_id' => $page?->id,
                    'page_title' => $page?->title,
                    'page_slug' => $page?->slug,
                    'submitted_at' => now()->toIso8601String(),
                    'utm_params' => $meta['utm_params'] ?? null,
                    'ip_address' => $meta['ip_address'] ?? null,
                    'referer' => $meta['referer'] ?? null,
                    'raw_submission' => $submissionData,
                ];

                $contactAttributes = [
                    'name' => $mappedContact['name'],
                    'first_name' => $mappedContact['first_name'] ?? null,
                    'last_name' => $mappedContact['last_name'] ?? null,
                    'email' => $mappedContact['email'] ?? null,
                    'email_normalized' => !empty($mappedContact['email']) ? strtolower(trim($mappedContact['email'])) : null,
                    'phone' => $mappedContact['phone'],
                    'phone_normalized' => $this->normalizePhone($mappedContact['phone']),
                    'company' => $mappedContact['company'] ?? null,
                    'job_title' => $mappedContact['job_title'] ?? null,
                    'address' => $mappedContact['address'] ?? null,
                    'contact_type' => 'Lead',
                    'status' => 'active',
                    'source' => 'Page & Form Builder: ' . ($form->title ?: 'Form'),
                    'source_details' => $sourceDetails,
                ];

                if (!empty($mappedContact['notes'])) {
                    $contactAttributes['notes'] = $mappedContact['notes'];
                }

                // 4. Create or Update Contact
                if ($existingContact) {
                    // Merge details
                    $existingDetails = $existingContact->source_details ?? [];
                    if (!is_array($existingDetails)) $existingDetails = [];
                    $existingDetails['last_form_submission'] = $sourceDetails;
                    $contactAttributes['source_details'] = $existingDetails;

                    // Keep existing notes and append new notes if present
                    if (!empty($mappedContact['notes']) && !empty($existingContact->notes)) {
                        $contactAttributes['notes'] = $existingContact->notes . "\n---\n" . $mappedContact['notes'];
                    }

                    $existingContact->update(array_filter($contactAttributes, fn($v) => !is_null($v)));
                    $contact = $existingContact;
                    Log::info("[CrmLeadSync] Updated existing contact ID {$contact->id} from form submission.");
                } else {
                    $contactAttributes['tenant_id'] = $tenantId;
                    $contact = Contact::create($contactAttributes);
                    Log::info("[CrmLeadSync] Created new contact ID {$contact->id} from form submission.");
                }

                // 5. Automatic Tagging
                $autoTags = $mapping['auto_tags'] ?? [];
                // Include default tags based on form type
                $defaultTag = $form->form_type === BuilderForm::TYPE_SURVEY ? 'Survey-Lead' : 'Web-Lead';
                if (!in_array($defaultTag, $autoTags)) {
                    $autoTags[] = $defaultTag;
                }

                $this->attachTags($contact, $autoTags);

                return $contact;
            });
        } catch (\Throwable $e) {
            Log::error("[CrmLeadSync] Failed to sync contact from form submission: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Extract and normalize fields mapped to Contact model columns
     */
    protected function extractContactFields(array $data, array $mappings): array
    {
        $contact = [];

        // Apply explicit form-builder mappings if configured
        foreach ($mappings as $formKey => $contactColumn) {
            if (isset($data[$formKey]) && !empty($contactColumn)) {
                $contact[$contactColumn] = $data[$formKey];
            }
        }

        // Apply smart heuristics for standard field names if not explicitly mapped
        $heuristics = [
            'name' => ['name', 'full_name', 'fullname', 'contact_name', 'your_name'],
            'first_name' => ['first_name', 'firstname', 'fname', 'given_name'],
            'last_name' => ['last_name', 'lastname', 'lname', 'surname'],
            'email' => ['email', 'email_address', 'mail', 'your_email'],
            'phone' => ['phone', 'phone_number', 'mobile', 'cell', 'telephone', 'tel', 'whatsapp'],
            'company' => ['company', 'company_name', 'organization', 'business_name', 'business'],
            'job_title' => ['job_title', 'title', 'position', 'role'],
            'address' => ['address', 'location', 'city', 'street_address'],
            'notes' => ['message', 'notes', 'comments', 'feedback', 'description', 'inquiry'],
        ];

        foreach ($heuristics as $column => $candidates) {
            if (empty($contact[$column])) {
                foreach ($candidates as $candidate) {
                    if (isset($data[$candidate]) && !is_null($data[$candidate]) && $data[$candidate] !== '') {
                        $contact[$column] = is_array($data[$candidate])
                            ? implode(', ', $data[$candidate])
                            : (string) $data[$candidate];
                        break;
                    }
                }
            }
        }

        return $contact;
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
     * Attach string tag names to Contact
     */
    protected function attachTags(Contact $contact, array $tagNames): void
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
}

