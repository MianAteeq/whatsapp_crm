<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailDeliveryEvent;
use App\Models\EmailSuppression;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMailtrapWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        public array $events
    ) {}

    public function handle(): void
    {
        $processedCount = 0;

        foreach ($this->events as $event) {
            try {
                $this->processSingleEvent($event);
                $processedCount++;
            } catch (Throwable $e) {
                Log::error("[ProcessMailtrapWebhookJob] Failed to process event: " . $e->getMessage(), [
                    'event' => $event,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info("[ProcessMailtrapWebhookJob] Processed {$processedCount} Mailtrap webhook events.");
    }

    /**
     * Process an individual event payload from Mailtrap
     */
    protected function processSingleEvent(array $event): void
    {
        $rawType = strtolower(trim((string)($event['event'] ?? '')));
        $rawMessageId = trim((string)($event['message_id'] ?? ''));
        $eventId = !empty($event['event_id']) ? trim((string)$event['event_id']) : null;
        $email = !empty($event['email']) ? strtolower(trim((string)$event['email'])) : null;

        // Clean message ID (remove any angle brackets e.g. <uuid>)
        $messageId = trim($rawMessageId, '<> ');

        // Map Mailtrap event name to standardized internal event type
        $eventType = match ($rawType) {
            'delivery', 'delivered' => 'delivered',
            'bounce' => 'bounced',
            'soft_bounce' => 'soft_bounce',
            'reject', 'dropped' => 'failed',
            'spam_complaint', 'spam', 'complaint' => 'complained',
            'unsubscribe', 'unsubscribed' => 'unsubscribed',
            'deferral', 'deferred' => 'deferred',
            'open', 'opened' => 'opened',
            'click', 'clicked' => 'clicked',
            default => $rawType,
        };

        // Determine timestamp
        $timestamp = null;
        if (!empty($event['timestamp'])) {
            $timestamp = is_numeric($event['timestamp']) 
                ? Carbon::createFromTimestamp($event['timestamp'])
                : Carbon::parse($event['timestamp']);
        } else {
            $timestamp = now();
        }

        // 1. Idempotency Check: Don't process the same event twice
        if ($eventId) {
            $existing = EmailDeliveryEvent::where('provider', 'mailtrap')
                ->where('provider_event_id', $eventId)
                ->first();
            if ($existing) {
                Log::info("[ProcessMailtrapWebhookJob] Event {$eventId} already processed. Skipping duplicate.");
                return;
            }
        }

        // 2. Locate Recipient using provider_message_id (preferred) or email fallback
        $recipient = null;
        if (!empty($messageId)) {
            $recipient = EmailCampaignRecipient::where('provider_message_id', $messageId)->first();
            
            // Check without prefix/suffix if not matched
            if (!$recipient) {
                $recipient = EmailCampaignRecipient::where('provider_message_id', 'LIKE', "%{$messageId}%")->first();
            }
        }

        // Fallback: check custom_variables passed in payload
        if (!$recipient && !empty($event['custom_variables']['recipient_id'])) {
            $recipient = EmailCampaignRecipient::find($event['custom_variables']['recipient_id']);
        }

        // Fallback: match by email on latest active/sending campaign
        if (!$recipient && !empty($email)) {
            $recipient = EmailCampaignRecipient::where('email', $email)
                ->latest('updated_at')
                ->first();
        }

        if (!$recipient) {
            Log::warning("[ProcessMailtrapWebhookJob] No recipient found matching message_id '{$messageId}' or email '{$email}'. Logging raw event.");
            
            // Store unlinked delivery event for audit
            EmailDeliveryEvent::create([
                'tenant_id' => 1,
                'campaign_id' => null,
                'recipient_id' => null,
                'provider' => 'mailtrap',
                'provider_message_id' => $messageId ?: null,
                'provider_event_id' => $eventId,
                'event_type' => $eventType,
                'event_timestamp' => $timestamp,
                'payload' => $event,
            ]);
            return;
        }

        $reason = $event['reason'] ?? $event['response'] ?? $event['error'] ?? null;
        if (is_array($reason)) {
            $reason = json_encode($reason);
        }

        // 3. Update Recipient status & metrics according to event
        $updates = [
            'last_event_at' => $timestamp,
            'provider' => 'mailtrap',
        ];

        switch ($eventType) {
            case 'delivered':
                $updates['status'] = EmailCampaignRecipient::STATUS_DELIVERED;
                $updates['delivered_at'] = $timestamp;
                break;

            case 'bounced':
            case 'soft_bounce':
                $isHard = ($rawType === 'bounce') && (strtolower($event['bounce_type'] ?? 'hard') === 'hard');
                $updates['status'] = EmailCampaignRecipient::STATUS_BOUNCED;
                $updates['bounced_at'] = $timestamp;
                $updates['bounce_type'] = $isHard ? 'hard' : 'soft';
                $updates['bounce_reason'] = $reason;

                // Permanent hard bounce: automatically suppress email
                if ($isHard && $recipient->email) {
                    EmailSuppression::updateOrCreate(
                        [
                            'tenant_id' => $recipient->tenant_id,
                            'email' => strtolower($recipient->email),
                        ],
                        [
                            'contact_id' => $recipient->contact_id,
                            'reason' => EmailSuppression::REASON_HARD_BOUNCE,
                            'details' => "Hard bounce reported by Mailtrap: {$reason}",
                        ]
                    );

                    if ($recipient->contact_id) {
                        Contact::where('id', $recipient->contact_id)->update(['status' => 'bounced']);
                    }
                }
                break;

            case 'complained':
                $updates['status'] = EmailCampaignRecipient::STATUS_COMPLAINED;
                $updates['complained_at'] = $timestamp;

                // Spam complaint: automatically suppress email
                if ($recipient->email) {
                    EmailSuppression::updateOrCreate(
                        [
                            'tenant_id' => $recipient->tenant_id,
                            'email' => strtolower($recipient->email),
                        ],
                        [
                            'contact_id' => $recipient->contact_id,
                            'reason' => EmailSuppression::REASON_SPAM_COMPLAINT,
                            'details' => 'Spam complaint reported by Mailtrap webhook.',
                        ]
                    );

                    if ($recipient->contact_id) {
                        Contact::where('id', $recipient->contact_id)->update(['status' => 'unsubscribed']);
                    }
                }
                break;

            case 'unsubscribed':
                $updates['status'] = EmailCampaignRecipient::STATUS_UNSUBSCRIBED;
                $updates['unsubscribed_at'] = $timestamp;

                // Unsubscribe: automatically suppress email
                if ($recipient->email) {
                    EmailSuppression::updateOrCreate(
                        [
                            'tenant_id' => $recipient->tenant_id,
                            'email' => strtolower($recipient->email),
                        ],
                        [
                            'contact_id' => $recipient->contact_id,
                            'reason' => EmailSuppression::REASON_UNSUBSCRIBED,
                            'details' => 'Unsubscribed event reported by Mailtrap webhook.',
                        ]
                    );

                    if ($recipient->contact_id) {
                        Contact::where('id', $recipient->contact_id)->update(['status' => 'unsubscribed']);
                    }
                }
                break;

            case 'deferred':
                $updates['status'] = EmailCampaignRecipient::STATUS_DEFERRED;
                $updates['deferred_at'] = $timestamp;
                $updates['error_message'] = $reason;
                break;

            case 'failed':
                $updates['status'] = EmailCampaignRecipient::STATUS_FAILED;
                $updates['failed_at'] = $timestamp;
                $updates['error_message'] = $reason;
                break;

            case 'opened':
            case 'clicked':
                // Delivery is implied if opened or clicked
                if ($recipient->status === EmailCampaignRecipient::STATUS_SENT) {
                    $updates['status'] = EmailCampaignRecipient::STATUS_DELIVERED;
                    $updates['delivered_at'] = $recipient->delivered_at ?: $timestamp;
                }
                break;
        }

        $recipient->update($updates);

        // 4. Log in email_delivery_events table for audit and idempotency
        EmailDeliveryEvent::create([
            'tenant_id' => $recipient->tenant_id,
            'campaign_id' => $recipient->campaign_id,
            'recipient_id' => $recipient->id,
            'provider' => 'mailtrap',
            'provider_message_id' => $messageId ?: $recipient->provider_message_id,
            'provider_event_id' => $eventId,
            'event_type' => $eventType,
            'event_timestamp' => $timestamp,
            'payload' => $event,
        ]);

        // 5. Recalculate campaign dashboard statistics
        if ($recipient->campaign_id) {
            $campaign = EmailCampaign::find($recipient->campaign_id);
            if ($campaign) {
                $campaign->recalculateMetrics();

                // If all recipients are finished, mark campaign as completed
                $remaining = EmailCampaignRecipient::where('campaign_id', $campaign->id)
                    ->whereIn('status', [
                        EmailCampaignRecipient::STATUS_PENDING, 
                        EmailCampaignRecipient::STATUS_QUEUED, 
                        EmailCampaignRecipient::STATUS_SENDING
                    ])
                    ->count();

                if ($remaining === 0 && in_array($campaign->status, [EmailCampaign::STATUS_SENDING])) {
                    $campaign->update([
                        'status' => EmailCampaign::STATUS_COMPLETED,
                        'completed_at' => now(),
                    ]);
                }
            }
        }
    }
}
