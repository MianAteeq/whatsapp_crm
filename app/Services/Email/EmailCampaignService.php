<?php

namespace App\Services\Email;

use App\Jobs\ProcessEmailCampaignJob;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSetting;
use App\Models\EmailSuppression;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailCampaignService
{
    /**
     * Send a one-off test email without touching campaign records
     */
    public static function sendTestEmail(int $tenantId, array $data): array
    {
        $testEmail = trim($data['test_email'] ?? '');
        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['test_email' => 'Please provide a valid test email address.']);
        }

        $setting = EmailSetting::where('tenant_id', $tenantId)->first();
        $provider = EmailProviderFactory::make($setting);

        $fromEmail = trim($data['from_email'] ?? $setting?->from_email ?? config('mail.from.address', 'hello@example.com'));
        $fromName = trim($data['from_name'] ?? $setting?->from_name ?? config('mail.from.name', 'Marketing'));
        $replyTo = trim($data['reply_to'] ?? $setting?->reply_to ?? '');

        // HARD SECURITY RULE: Validate sending domain ownership and verification status
        $domainService = app(\App\Services\Email\MailtrapDomainService::class);
        $domainCheck = $domainService->validateDomainForSending($tenantId, $fromEmail);
        if (!$domainCheck['allowed']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['from_email' => $domainCheck['message']]);
        }

        // Generate sample variables for a dummy contact
        $dummyContact = Contact::where('tenant_id', $tenantId)
            ->whereNotNull('email')
            ->first();

        $variables = EmailPersonalizationService::buildContactVariables($dummyContact, '#test-unsubscribe');
        $variables['email'] = $testEmail;
        $variables['first_name'] = $variables['first_name'] ?: 'Test User';

        $subject = EmailPersonalizationService::render($data['subject'] ?? 'Test Email', $variables);
        $subject = '[TEST] ' . $subject;

        $htmlBody = EmailPersonalizationService::render($data['html_body'] ?? '<p>Test message content.</p>', $variables);
        $textBody = EmailPersonalizationService::render($data['text_body'] ?? null, $variables);

        $result = $provider->send([
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'reply_to' => $replyTo ?: null,
            'to_email' => $testEmail,
            'to_name' => 'Test Recipient',
            'subject' => $subject,
            'html_body' => $htmlBody,
            'text_body' => $textBody,
        ]);

        if (!$result['success']) {
            throw new \RuntimeException($result['error'] ?? 'Failed to send test email.');
        }

        return $result;
    }

    /**
     * Populate recipients and dispatch background queue jobs for a campaign
     */
    public static function launchCampaign(EmailCampaign $campaign, bool $sendNow = true): void
    {
        // 1. Validation Pre-flight
        if (empty($campaign->from_email) || !filter_var($campaign->from_email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['from_email' => 'Valid From Email address is required.']);
        }

        // HARD SECURITY RULE: Validate sending domain ownership and verification status
        $domainService = app(\App\Services\Email\MailtrapDomainService::class);
        $domainCheck = $domainService->validateDomainForSending($campaign->tenant_id, $campaign->from_email, $campaign->sending_domain_id);
        if (!$domainCheck['allowed']) {
            throw ValidationException::withMessages(['from_email' => $domainCheck['message']]);
        }
        if (empty($campaign->subject)) {
            throw ValidationException::withMessages(['subject' => 'Campaign subject is required.']);
        }
        if (empty($campaign->html_body)) {
            throw ValidationException::withMessages(['html_body' => 'Campaign email body cannot be empty.']);
        }

        // If scheduled for later
        if (!$sendNow && $campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            $campaign->update([
                'status' => EmailCampaign::STATUS_SCHEDULED,
            ]);
            return;
        }

        DB::beginTransaction();
        try {
            // 2. Fetch and evaluate audience
            $filters = $campaign->audience_filter ?? [];
            $evaluation = EmailAudienceFilterService::evaluateAudience($campaign->tenant_id, $filters);

            if ($evaluation['eligible_count'] === 0) {
                throw ValidationException::withMessages(['audience' => 'No eligible recipients found matching audience criteria.']);
            }

            $campaign->update([
                'status' => EmailCampaign::STATUS_SENDING,
                'started_at' => now(),
                'total_recipients' => $evaluation['total_evaluated'],
                'eligible_count' => $evaluation['eligible_count'],
                'excluded_count' => $evaluation['excluded_count'],
            ]);

            // 3. Populate recipient rows (with idempotency / duplicate check)
            $baseQuery = EmailAudienceFilterService::buildBaseQuery($campaign->tenant_id, $filters);
            $contacts = $baseQuery->get(['id', 'name', 'company', 'email', 'status']);

            $suppressedEmails = EmailSuppression::where('tenant_id', $campaign->tenant_id)
                ->pluck('email')
                ->map(fn($e) => strtolower(trim($e)))
                ->flip()
                ->all();

            $alreadySentContactIds = EmailCampaignRecipient::where('tenant_id', $campaign->tenant_id)
                ->where('campaign_id', $campaign->id)
                ->where(function ($q) {
                    $q->whereIn('status', ['sent', 'delivered', 'opened', 'clicked'])
                      ->orWhereNotNull('sent_at');
                })
                ->pluck('contact_id')
                ->filter()
                ->flip()
                ->all();

            $seenEmails = [];
            $recipientsToInsert = [];
            $now = now();

            foreach ($contacts as $contact) {
                // Prevent duplicate send for this contact in this campaign (Requirement 16)
                if (isset($alreadySentContactIds[$contact->id])) {
                    continue;
                }

                $rawEmail = trim((string)$contact->email);
                if (empty($rawEmail) || !filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $normEmail = strtolower($rawEmail);
                if (isset($seenEmails[$normEmail])) {
                    continue;
                }
                $seenEmails[$normEmail] = true;

                if (strtolower((string)$contact->status) === 'unsubscribed') {
                    continue;
                }
                if (isset($suppressedEmails[$normEmail])) {
                    continue;
                }

                $recipientsToInsert[] = [
                    'tenant_id' => $campaign->tenant_id,
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'email' => $rawEmail,
                    'status' => EmailCampaignRecipient::STATUS_PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Insert in batches of 500
                if (count($recipientsToInsert) >= 500) {
                    DB::table('email_campaign_recipients')->insertOrIgnore($recipientsToInsert);
                    $recipientsToInsert = [];
                }
            }

            if (!empty($recipientsToInsert)) {
                DB::table('email_campaign_recipients')->insertOrIgnore($recipientsToInsert);
            }

            DB::commit();

            // 4. Dispatch the background campaign process job
            ProcessEmailCampaignJob::dispatch($campaign->id);

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("[EmailCampaignService] Launch failed: " . $e->getMessage(), [
                'campaign_id' => $campaign->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $campaign->update([
                'status' => EmailCampaign::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Pause a sending campaign
     */
    public static function pauseCampaign(EmailCampaign $campaign): void
    {
        if ($campaign->status === EmailCampaign::STATUS_SENDING) {
            $campaign->update(['status' => EmailCampaign::STATUS_PAUSED]);
        }
    }

    /**
     * Resume a paused campaign
     */
    public static function resumeCampaign(EmailCampaign $campaign): void
    {
        if ($campaign->status === EmailCampaign::STATUS_PAUSED) {
            $campaign->update(['status' => EmailCampaign::STATUS_SENDING]);
            ProcessEmailCampaignJob::dispatch($campaign->id);
        }
    }

    /**
     * Cancel a campaign
     */
    public static function cancelCampaign(EmailCampaign $campaign): void
    {
        $campaign->update(['status' => EmailCampaign::STATUS_CANCELLED]);
        
        // Mark any remaining pending/queued recipients as skipped
        EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', [EmailCampaignRecipient::STATUS_PENDING, EmailCampaignRecipient::STATUS_QUEUED])
            ->update(['status' => EmailCampaignRecipient::STATUS_SKIPPED]);
    }

    /**
     * Duplicate a campaign as draft
     */
    public static function duplicateCampaign(EmailCampaign $campaign, int $userId): EmailCampaign
    {
        $duplicate = $campaign->replicate([
            'status',
            'sent_count',
            'delivered_count',
            'bounced_count',
            'failed_count',
            'unsubscribed_count',
            'started_at',
            'completed_at',
            'error_message',
            'created_at',
            'updated_at',
        ]);

        $duplicate->name = $campaign->name . ' (Copy)';
        $duplicate->status = EmailCampaign::STATUS_DRAFT;
        $duplicate->created_by = $userId;
        $duplicate->save();

        return $duplicate;
    }
}
