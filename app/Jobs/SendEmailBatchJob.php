<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSetting;
use App\Models\EmailSuppression;
use App\Services\Email\EmailPersonalizationService;
use App\Services\Email\EmailProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendEmailBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;

    public function __construct(
        public int $campaignId,
        public array $recipientIds
    ) {}

    public function handle(): void
    {
        $campaign = EmailCampaign::with('tenant')->find($this->campaignId);
        if (!$campaign) {
            Log::warning("[SendEmailBatchJob] Campaign {$this->campaignId} not found.");
            return;
        }

        // Check if campaign was paused or cancelled by user
        if (in_array($campaign->status, [EmailCampaign::STATUS_PAUSED, EmailCampaign::STATUS_CANCELLED])) {
            Log::info("[SendEmailBatchJob] Campaign {$this->campaignId} is {$campaign->status}. Halting batch execution.");
            return;
        }

        // HARD SECURITY RULE (Requirement 8 & 9): Worker must revalidate sending domain before sending
        $domainService = app(\App\Services\Email\MailtrapDomainService::class);
        $domainCheck = $domainService->validateDomainForSending(
            $campaign->tenant_id,
            $campaign->from_email,
            $campaign->sending_domain_id
        );

        if (!$domainCheck['allowed']) {
            Log::error("[SendEmailBatchJob] Campaign {$this->campaignId} blocked: Sending domain is no longer verified.", [
                'reason' => $domainCheck['message'],
            ]);

            EmailCampaignRecipient::where('campaign_id', $this->campaignId)
                ->whereIn('id', $this->recipientIds)
                ->whereNotIn('status', [EmailCampaignRecipient::STATUS_SENT, EmailCampaignRecipient::STATUS_DELIVERED])
                ->update([
                    'status' => EmailCampaignRecipient::STATUS_FAILED,
                    'failed_at' => now(),
                    'error_message' => 'Sending domain is no longer verified.',
                ]);

            $campaign->update([
                'status' => EmailCampaign::STATUS_FAILED,
                'error_message' => 'Sending domain is no longer verified: ' . $domainCheck['message'],
            ]);
            $campaign->recalculateMetrics();
            return;
        }

        $setting = EmailSetting::where('tenant_id', $campaign->tenant_id)->first();
        $provider = EmailProviderFactory::make($setting);

        $recipients = EmailCampaignRecipient::with('contact')
            ->where('campaign_id', $this->campaignId)
            ->whereIn('id', $this->recipientIds)
            ->get();

        foreach ($recipients as $recipient) {
            // Re-check campaign state between recipients
            $campaign->refresh();
            if (in_array($campaign->status, [EmailCampaign::STATUS_PAUSED, EmailCampaign::STATUS_CANCELLED])) {
                Log::info("[SendEmailBatchJob] Campaign paused/cancelled during batch processing.");
                break;
            }

            // CRITICAL IDEMPOTENCY: Check if recipient has already been sent
            if (in_array($recipient->status, [EmailCampaignRecipient::STATUS_SENT, EmailCampaignRecipient::STATUS_DELIVERED])) {
                Log::info("[SendEmailBatchJob] Recipient {$recipient->id} ({$recipient->email}) already sent. Skipping duplicate send.");
                continue;
            }

            // Check if contact is globally suppressed
            if (EmailSuppression::isSuppressed($campaign->tenant_id, $recipient->email)) {
                $recipient->update([
                    'status' => EmailCampaignRecipient::STATUS_SKIPPED,
                    'error_message' => 'Excluded by global suppression / unsubscribe rules.',
                ]);
                continue;
            }

            try {
                $recipient->update(['status' => EmailCampaignRecipient::STATUS_SENDING]);

                // Construct unsubscribe URL
                $token = hash_hmac('sha256', $recipient->email . '|' . $campaign->id, config('app.key', 'secret'));
                $unsubscribeUrl = url("/api/email/unsubscribe?cid={$campaign->id}&email=" . urlencode($recipient->email) . "&token={$token}");

                // Personalize variables
                $variables = EmailPersonalizationService::buildContactVariables($recipient->contact, $unsubscribeUrl);
                $variables['email'] = $recipient->email;

                $subject = EmailPersonalizationService::render($campaign->subject, $variables);
                $htmlBody = EmailPersonalizationService::render($campaign->html_body, $variables);
                $textBody = EmailPersonalizationService::render($campaign->text_body, $variables);

                // Ensure unsubscribe footer is present if not already embedded
                if (!str_contains($htmlBody, 'unsubscribe')) {
                    $footer = '<div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center; font-family: sans-serif;">'
                        . '<p>You received this email because you are in contact with ' . htmlspecialchars($campaign->from_name) . '.</p>'
                        . '<p><a href="' . htmlspecialchars($unsubscribeUrl) . '" style="color: #64748b; text-decoration: underline;">Unsubscribe from marketing emails</a></p>'
                        . '</div>';
                    $htmlBody .= $footer;
                }

                $sendPayload = [
                    'from_email' => $campaign->from_email,
                    'from_name' => $campaign->from_name,
                    'reply_to' => $campaign->reply_to ?: null,
                    'to_email' => $recipient->email,
                    'to_name' => $recipient->contact?->name ?? '',
                    'subject' => $subject,
                    'html_body' => $htmlBody,
                    'text_body' => $textBody,
                    'headers' => [
                        'X-Campaign-Id' => (string)$campaign->id,
                        'X-Recipient-Id' => (string)$recipient->id,
                        'List-Unsubscribe' => "<{$unsubscribeUrl}>",
                    ],
                ];

                $response = $provider->send($sendPayload);
                $providerName = $setting?->provider ?: 'mailtrap';

                if ($response['success']) {
                    $msgId = $response['message_id'] ?? null;

                    $recipient->update([
                        'status' => EmailCampaignRecipient::STATUS_SENT,
                        'sent_at' => now(),
                        'provider' => $providerName,
                        'provider_message_id' => $msgId,
                        'personalized_subject' => $subject,
                        'error_message' => null,
                        'last_event_at' => now(),
                    ]);

                    // Record initial sent event in email_delivery_events
                    \App\Models\EmailDeliveryEvent::create([
                        'tenant_id' => $recipient->tenant_id,
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'provider' => $providerName,
                        'provider_message_id' => $msgId,
                        'provider_event_id' => $msgId ? "sent_{$msgId}" : null,
                        'event_type' => 'sent',
                        'event_timestamp' => now(),
                        'payload' => [
                            'to' => $recipient->email,
                            'subject' => $subject,
                            'provider' => $providerName,
                        ],
                    ]);
                } else {
                    $errorMsg = $response['error'] ?? 'Unknown sending error';

                    // Check for rate limit 429
                    if (str_contains(strtolower($errorMsg), '429') || str_contains(strtolower($errorMsg), 'rate_limit')) {
                        Log::warning("[SendEmailBatchJob] Rate limit encountered from provider. Backing off 60s.");
                        $recipient->update([
                            'status' => EmailCampaignRecipient::STATUS_QUEUED,
                            'provider' => $providerName,
                            'error_message' => 'Rate limit encountered, backing off.',
                        ]);
                        $this->release(60);
                        return;
                    }

                    $recipient->update([
                        'status' => EmailCampaignRecipient::STATUS_FAILED,
                        'failed_at' => now(),
                        'provider' => $providerName,
                        'attempts' => $recipient->attempts + 1,
                        'error_message' => $errorMsg,
                        'last_event_at' => now(),
                    ]);

                    \App\Models\EmailDeliveryEvent::create([
                        'tenant_id' => $recipient->tenant_id,
                        'campaign_id' => $campaign->id,
                        'recipient_id' => $recipient->id,
                        'provider' => $providerName,
                        'provider_message_id' => null,
                        'provider_event_id' => null,
                        'event_type' => 'failed',
                        'event_timestamp' => now(),
                        'payload' => [
                            'to' => $recipient->email,
                            'error' => $errorMsg,
                        ],
                    ]);
                }
            } catch (Throwable $e) {
                Log::error("[SendEmailBatchJob] Exception sending to recipient {$recipient->id}: " . $e->getMessage());
                $recipient->update([
                    'status' => EmailCampaignRecipient::STATUS_FAILED,
                    'failed_at' => now(),
                    'provider' => $providerName ?? 'mailtrap',
                    'attempts' => $recipient->attempts + 1,
                    'error_message' => $e->getMessage(),
                    'last_event_at' => now(),
                ]);
            }
        }

        // Recalculate campaign metrics
        $campaign->recalculateMetrics();

        // Check if all recipients for this campaign have finished processing
        $remainingPending = EmailCampaignRecipient::where('campaign_id', $this->campaignId)
            ->whereIn('status', [EmailCampaignRecipient::STATUS_PENDING, EmailCampaignRecipient::STATUS_QUEUED, EmailCampaignRecipient::STATUS_SENDING])
            ->count();

        if ($remainingPending === 0 && $campaign->status === EmailCampaign::STATUS_SENDING) {
            $campaign->update([
                'status' => EmailCampaign::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
            Log::info("[SendEmailBatchJob] Campaign {$this->campaignId} completed successfully.");
        }
    }
}
