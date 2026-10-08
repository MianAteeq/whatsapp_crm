<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailDeliveryEvent;
use App\Models\EmailSendingDomain;
use App\Models\EmailSetting;
use App\Models\EmailSuppression;
use App\Models\MarketingKnowledge;
use App\Models\MarketingService;
use App\Services\Email\EmailAudienceFilterService;
use App\Services\Email\EmailCampaignService;
use App\Services\Email\MailtrapDomainService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiMarketingAgentTools
{
    /**
     * Tool: get_categories()
     * Enforces tenant isolation.
     */
    public function get_categories(int $tenantId): array
    {
        return Category::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get(['id', 'name', 'slug', 'description'])
            ->toArray();
    }

    /**
     * Tool: get_services()
     * Retrieves active company services & products for the tenant.
     */
    public function get_services(int $tenantId): array
    {
        // Seed standard defaults if tenant has no services yet
        MarketingService::seedDefaultServicesForTenant($tenantId);

        return MarketingService::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get([
                'id', 'name', 'slug', 'description', 'target_industries',
                'features', 'benefits', 'pricing', 'cta_text', 'cta_url'
            ])
            ->toArray();
    }

    /**
     * Tool: get_service_details(service_id)
     */
    public function get_service_details(int $tenantId, int $serviceId): ?array
    {
        $service = MarketingService::where('tenant_id', $tenantId)->find($serviceId);
        return $service ? $service->toArray() : null;
    }

    /**
     * Tool: get_marketing_knowledge()
     * Access tenant brand voice, company description, booking links, safety limits.
     */
    public function get_marketing_knowledge(int $tenantId): array
    {
        return MarketingKnowledge::getOrCreateForTenant($tenantId)->toArray();
    }

    /**
     * Tool: get_verified_sending_domains()
     * Queries only verified domains belonging to this tenant.
     */
    public function get_verified_sending_domains(int $tenantId): array
    {
        return EmailSendingDomain::where('tenant_id', $tenantId)
            ->where('status', EmailSendingDomain::STATUS_VERIFIED)
            ->get(['id', 'domain', 'status', 'spf_status', 'dkim_status', 'verified_at'])
            ->toArray();
    }

    /**
     * Tool: search_contacts(filters)
     * Queries contacts matching audience filters, evaluating eligibility & exclusion breakdown.
     */
    public function search_contacts(int $tenantId, array $filters = []): array
    {
        $knowledge = MarketingKnowledge::getOrCreateForTenant($tenantId);
        $cooldownDays = isset($knowledge->cooldown_days_per_contact) ? (int) $knowledge->cooldown_days_per_contact : 0;

        // Fetch base query
        $baseQuery = EmailAudienceFilterService::buildBaseQuery($tenantId, $filters);
        $contacts = $baseQuery->get([
            'id', 'name', 'first_name', 'last_name', 'company', 'email', 'status', 'address', 'job_title'
        ]);

        $suppressedEmails = EmailSuppression::where('tenant_id', $tenantId)
            ->pluck('email')
            ->map(fn($e) => strtolower(trim($e)))
            ->flip()
            ->all();

        // Contact cooldown check (Do not contact same person within X days if cooldown is configured)
        $recentlyContactedIds = [];
        if ($cooldownDays > 0) {
            $cooldownThreshold = Carbon::now()->subDays($cooldownDays);
            $recentlyContactedIds = EmailCampaignRecipient::where('tenant_id', $tenantId)
                ->where('sent_at', '>=', $cooldownThreshold)
                ->pluck('contact_id')
                ->filter()
                ->flip()
                ->all();
        }

        $eligibleContacts = [];
        $seenEmails = [];
        $reasons = [
            'missing_email' => 0,
            'invalid_syntax' => 0,
            'unsubscribed' => 0,
            'suppressed' => 0,
            'recently_contacted_cooldown' => 0,
            'duplicate_in_audience' => 0,
        ];

        foreach ($contacts as $contact) {
            $rawEmail = trim((string)$contact->email);

            if (empty($rawEmail)) {
                $reasons['missing_email']++;
                continue;
            }

            if (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                $reasons['invalid_syntax']++;
                continue;
            }

            $normEmail = strtolower($rawEmail);
            if (isset($seenEmails[$normEmail])) {
                $reasons['duplicate_in_audience']++;
                continue;
            }
            $seenEmails[$normEmail] = true;

            if (strtolower((string)$contact->status) === 'unsubscribed') {
                $reasons['unsubscribed']++;
                continue;
            }

            if (isset($suppressedEmails[$normEmail])) {
                $reasons['suppressed']++;
                continue;
            }

            // Cooldown check (unless explicitly filtered for follow_up)
            $isFollowUp = !empty($filters['is_follow_up']);
            if (!$isFollowUp && isset($recentlyContactedIds[$contact->id])) {
                $reasons['recently_contacted_cooldown']++;
                continue;
            }

            $eligibleContacts[] = $contact;
        }

        $totalEvaluated = $contacts->count();
        $eligibleCount = count($eligibleContacts);
        $excludedCount = $totalEvaluated - $eligibleCount;

        return [
            'total_evaluated' => $totalEvaluated,
            'contacts_with_email' => $totalEvaluated - $reasons['missing_email'],
            'eligible_count' => $eligibleCount,
            'excluded_count' => $excludedCount,
            'reasons' => $reasons,
            'sample_eligible' => array_map(function ($c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'email' => $c->email,
                    'company' => $c->company,
                ];
            }, array_slice($eligibleContacts, 0, 5)),
        ];
    }

    /**
     * Tool: get_contact_email_history(contact_id)
     */
    public function get_contact_email_history(int $tenantId, int $contactId): array
    {
        return EmailCampaignRecipient::where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->with(['campaign:id,name,subject'])
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->toArray();
    }

    /**
     * Tool: get_campaign_history()
     * Memory of previous campaigns and their real delivery/open/click rates.
     */
    public function get_campaign_history(int $tenantId): array
    {
        return EmailCampaign::where('tenant_id', $tenantId)
            ->whereIn('status', [EmailCampaign::STATUS_COMPLETED, EmailCampaign::STATUS_SENDING])
            ->latest('created_at')
            ->limit(10)
            ->get([
                'id', 'name', 'subject', 'status', 'total_recipients',
                'eligible_count', 'sent_count', 'delivered_count', 'bounced_count',
                'unsubscribed_count', 'created_at', 'completed_at'
            ])
            ->map(function ($c) {
                $sent = max(1, $c->sent_count);
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'subject' => $c->subject,
                    'status' => $c->status,
                    'sent' => $c->sent_count,
                    'delivered' => $c->delivered_count,
                    'delivery_rate' => round(($c->delivered_count / $sent) * 100, 1) . '%',
                    'bounce_rate' => round(($c->bounced_count / $sent) * 100, 1) . '%',
                    'completed_at' => $c->completed_at?->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Tool: create_campaign(data)
     * Creates the campaign draft in the existing EmailCampaign system.
     */
    public function create_campaign(int $tenantId, int $userId, array $data): EmailCampaign
    {
        // Require verified domain belonging to tenant
        $domainService = app(MailtrapDomainService::class);
        $domainCheck = $domainService->validateDomainForSending(
            $tenantId,
            $data['from_email'],
            $data['sending_domain_id'] ?? null
        );

        if (!$domainCheck['allowed']) {
            throw ValidationException::withMessages(['from_email' => $domainCheck['message']]);
        }

        $domainRecord = $domainCheck['domain'];

        return EmailCampaign::create([
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'name' => $data['name'],
            'subject' => $data['subject'],
            'from_name' => $data['from_name'],
            'from_email' => $data['from_email'],
            'reply_to' => $data['reply_to'] ?? $data['from_email'],
            'sending_domain_id' => $domainRecord?->id,
            'sending_domain' => $domainRecord?->domain,
            'html_body' => $data['html_body'],
            'text_body' => $data['text_body'] ?? strip_tags($data['html_body']),
            'audience_filter' => $data['audience_filter'] ?? [],
            'status' => EmailCampaign::STATUS_DRAFT,
            'scheduled_at' => !empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
        ]);
    }

    /**
     * Tool: validate_campaign(campaign_id)
     * Pre-flight safety checks (domain verification, audience size, tenant limits).
     */
    public function validate_campaign(int $tenantId, int $campaignId): array
    {
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($campaignId);
        $knowledge = MarketingKnowledge::getOrCreateForTenant($tenantId);

        $domainService = app(MailtrapDomainService::class);
        $domainCheck = $domainService->validateDomainForSending(
            $tenantId,
            $campaign->from_email,
            $campaign->sending_domain_id
        );

        if (!$domainCheck['allowed']) {
            return [
                'is_valid' => false,
                'error' => $domainCheck['message'],
            ];
        }

        $audience = $this->search_contacts($tenantId, $campaign->audience_filter ?? []);
        if ($audience['eligible_count'] === 0) {
            return [
                'is_valid' => false,
                'error' => 'No eligible contacts found matching this campaign audience criteria.',
            ];
        }

        // Autonomous Safety Limit: Maximum emails per campaign
        if ($audience['eligible_count'] > $knowledge->max_emails_per_campaign) {
            return [
                'is_valid' => false,
                'error' => "Audience size ({$audience['eligible_count']}) exceeds tenant safety limit of {$knowledge->max_emails_per_campaign} emails per campaign.",
            ];
        }

        return [
            'is_valid' => true,
            'domain_verified' => true,
            'eligible_count' => $audience['eligible_count'],
            'excluded_count' => $audience['excluded_count'],
            'sending_domain' => $campaign->sending_domain,
        ];
    }

    /**
     * Tool: send_campaign(campaign_id)
     * Triggers the existing Mailtrap queue pipeline via EmailCampaignService.
     */
    public function send_campaign(int $tenantId, int $campaignId): void
    {
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($campaignId);
        EmailCampaignService::launchCampaign($campaign, true);
    }

    /**
     * Tool: schedule_campaign(campaign_id, datetime)
     */
    public function schedule_campaign(int $tenantId, int $campaignId, string $datetime): void
    {
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($campaignId);
        $campaign->scheduled_at = Carbon::parse($datetime);
        $campaign->save();

        EmailCampaignService::launchCampaign($campaign, false);
    }

    /**
     * Tool: get_campaign_results(campaign_id)
     */
    public function get_campaign_results(int $tenantId, int $campaignId): array
    {
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($campaignId);
        $campaign->recalculateMetrics();
        $campaign->refresh();

        $sent = max(1, $campaign->sent_count);
        $delivered = $campaign->delivered_count;
        $bounced = $campaign->bounced_count;
        $failed = $campaign->failed_count;
        $unsubscribed = $campaign->unsubscribed_count;

        // Open & click counts from events table
        $opens = EmailDeliveryEvent::where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->where('event_type', 'opened')
            ->count();

        $clicks = EmailDeliveryEvent::where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->where('event_type', 'clicked')
            ->count();

        return [
            'campaign_id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status,
            'sent' => $campaign->sent_count,
            'delivered' => $delivered,
            'bounced' => $bounced,
            'failed' => $failed,
            'unsubscribed' => $unsubscribed,
            'opens' => $opens,
            'clicks' => $clicks,
            'delivery_rate' => round(($delivered / $sent) * 100, 1),
            'bounce_rate' => round(($bounced / $sent) * 100, 1),
            'open_rate' => round(($opens / $sent) * 100, 1),
            'click_rate' => round(($clicks / $sent) * 100, 1),
            'unsubscribe_rate' => round(($unsubscribed / $sent) * 100, 1),
        ];
    }
}
