<?php

namespace App\Services\Email;

use App\Models\Contact;
use App\Models\EmailSuppression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EmailAudienceFilterService
{
    /**
     * Build base query for contacts based on audience criteria
     */
    public static function buildBaseQuery(int $tenantId, array $filters = []): Builder
    {
        $query = Contact::query()->where('tenant_id', $tenantId);

        $audienceType = $filters['audience_type'] ?? 'all';

        // 1. Explicitly selected contacts
        if ($audienceType === 'selected' || !empty($filters['contact_ids'])) {
            $ids = (array) ($filters['contact_ids'] ?? []);
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        // 2. Filter by Tags
        if (!empty($filters['tag_ids'])) {
            $tagIds = (array) $filters['tag_ids'];
            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            });
        }

        // 2b. Filter by Categories (Requirement 17)
        if (!empty($filters['category_id']) || !empty($filters['category_ids'])) {
            $catIds = (array) ($filters['category_id'] ?? $filters['category_ids']);
            $query->whereHas('categories', function ($q) use ($catIds) {
                $q->whereIn('categories.id', $catIds);
            });
        }

        // 2c. Filter by Email Send History (Requirement 17: Never Sent, Previously Sent, Delivered, Bounced)
        if (!empty($filters['email_history'])) {
            $hist = strtolower(trim((string)$filters['email_history']));
            if ($hist === 'never_sent') {
                $query->whereDoesntHave('emailRecipients', function ($q) {
                    $q->whereIn('status', ['sent', 'delivered', 'opened', 'clicked', 'bounced', 'failed', 'complained', 'unsubscribed'])
                      ->orWhereNotNull('sent_at');
                });
            } elseif ($hist === 'previously_sent' || $hist === 'sent') {
                $query->whereHas('emailRecipients', function ($q) {
                    $q->whereIn('status', ['sent', 'delivered', 'opened', 'clicked'])
                      ->orWhereNotNull('sent_at');
                });
            } elseif ($hist === 'delivered') {
                $query->whereHas('emailRecipients', function ($q) {
                    $q->where('status', 'delivered')->orWhereNotNull('delivered_at');
                });
            } elseif ($hist === 'bounced') {
                $query->whereHas('emailRecipients', function ($q) {
                    $q->where('status', 'bounced')->orWhereNotNull('bounced_at');
                });
            }
        }

        // 2d. Must Have Email
        if (!empty($filters['has_email'])) {
            $query->whereNotNull('email')->where('email', '!=', '');
        }

        // 2e. Must Have Phone
        if (!empty($filters['has_phone'])) {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        }

        // 2f. Job Title
        if (!empty($filters['job_title'])) {
            $jt = trim($filters['job_title']);
            $query->where('job_title', 'LIKE', "%{$jt}%");
        }

        // 3. Filter by Source (e.g. "Business Scraper")
        if (!empty($filters['source'])) {
            $source = trim($filters['source']);
            if (strtolower($source) === 'business scraper') {
                $query->where(function ($q) {
                    $q->where('source', 'LIKE', '%scraper%')
                      ->orWhereNotNull('google_place_id');
                });
            } else {
                $query->where('source', $source);
            }
        }

        // 4. Lead Quality Grades (A, B, C, D)
        if (!empty($filters['lead_quality_grades']) && is_array($filters['lead_quality_grades'])) {
            $query->whereIn('lead_quality_grade', $filters['lead_quality_grades']);
        } elseif (!empty($filters['lead_quality'])) {
            $grades = is_array($filters['lead_quality']) ? $filters['lead_quality'] : explode(',', (string)$filters['lead_quality']);
            $grades = array_map('trim', array_map('strtoupper', $grades));
            $query->whereIn('lead_quality_grade', $grades);
        }

        // 5. Min Lead Score
        if (isset($filters['min_lead_score']) && is_numeric($filters['min_lead_score'])) {
            $query->where('lead_quality_score', '>=', (int)$filters['min_lead_score']);
        }

        // 6. Location / City search
        if (!empty($filters['location'])) {
            $loc = trim($filters['location']);
            $query->where(function ($q) use ($loc) {
                $q->where('address', 'LIKE', "%{$loc}%")
                  ->orWhere('source_details->city', 'LIKE', "%{$loc}%");
            });
        }

        // 7. Industry search
        if (!empty($filters['industry'])) {
            $ind = trim($filters['industry']);
            $query->where(function ($q) use ($ind) {
                $q->where('job_title', 'LIKE', "%{$ind}%")
                  ->orWhere('company', 'LIKE', "%{$ind}%")
                  ->orWhere('source_details->category', 'LIKE', "%{$ind}%");
            });
        }

        // 8. Contact Status (e.g. active)
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 9. Website available filter
        if (!empty($filters['website_available'])) {
            $query->whereNotNull('website')->where('website', '!=', '');
        }

        return $query;
    }

    /**
     * Evaluate audience for pre-flight stats: eligible vs excluded breakdown
     */
    public static function evaluateAudience(int $tenantId, array $filters = []): array
    {
        $baseQuery = self::buildBaseQuery($tenantId, $filters);
        
        // Fetch contacts matching audience criteria
        $contacts = $baseQuery->get([
            'id', 'name', 'company', 'email', 'status', 'lead_quality_grade', 'lead_quality_score', 'phone'
        ]);

        $suppressedEmails = EmailSuppression::where('tenant_id', $tenantId)
            ->pluck('email')
            ->map(fn($e) => strtolower(trim($e)))
            ->flip()
            ->all();

        $eligibleContacts = [];
        $seenEmails = [];
        $reasons = [
            'missing_email' => 0,
            'invalid_syntax' => 0,
            'unsubscribed' => 0,
            'suppressed' => 0,
            'duplicate_in_audience' => 0,
        ];

        foreach ($contacts as $contact) {
            $rawEmail = trim((string)$contact->email);
            
            // Check 1: Missing email
            if (empty($rawEmail)) {
                $reasons['missing_email']++;
                continue;
            }

            // Check 2: Invalid syntax
            if (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                $reasons['invalid_syntax']++;
                continue;
            }

            $normalizedEmail = strtolower($rawEmail);

            // Check 3: Duplicate within this audience
            if (isset($seenEmails[$normalizedEmail])) {
                $reasons['duplicate_in_audience']++;
                continue;
            }
            $seenEmails[$normalizedEmail] = true;

            // Check 4: Unsubscribed status
            if (strtolower((string)$contact->status) === 'unsubscribed') {
                $reasons['unsubscribed']++;
                continue;
            }

            // Check 5: Globally Suppressed for this tenant
            if (isset($suppressedEmails[$normalizedEmail])) {
                $reasons['suppressed']++;
                continue;
            }

            // Contact is valid and eligible!
            $eligibleContacts[] = $contact;
        }

        $totalEvaluated = $contacts->count();
        $eligibleCount = count($eligibleContacts);
        $excludedCount = $totalEvaluated - $eligibleCount;

        return [
            'total_evaluated' => $totalEvaluated,
            'eligible_count' => $eligibleCount,
            'excluded_count' => $excludedCount,
            'reasons' => $reasons,
            'sample_eligible' => array_slice($eligibleContacts, 0, 5),
        ];
    }
}
