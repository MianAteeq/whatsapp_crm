<?php

namespace App\Jobs;

use App\Events\SocialMessageReceived;
use App\Models\SocialAccount;
use App\Models\SocialConversation;
use App\Models\SocialMessage;
use App\Models\SocialMessageAttachment;
use App\Models\SocialWebhookEvent;
use App\Services\Social\MetaGraphApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessSocialWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public array $payload
    ) {}

    public function handle(MetaGraphApiService $metaApi): void
    {
        $object = (string) ($this->payload['object'] ?? '');
        $entries = $this->payload['entry'] ?? [];

        Log::info("[ProcessSocialWebhookJob] Processing Meta Webhook object: {$object} (" . count($entries) . " entries)");

        foreach ($entries as $entry) {
            $entryAccountId = (string) ($entry['id'] ?? '');

            // 1. Process messaging events (Facebook Messenger & Instagram Direct)
            if (!empty($entry['messaging'])) {
                foreach ($entry['messaging'] as $event) {
                    $this->processMessagingEvent($object, $entryAccountId, $event, $metaApi);
                }
            }

            // 2. Process changes (Facebook feed comments & Instagram comments)
            if (!empty($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    $this->processChangeEvent($object, $entryAccountId, $change);
                }
            }
        }
    }

    /**
     * Resolve connected SocialAccount matching recipient, entry ID, or sender ID
     */
    protected function resolveSocialAccount(string $object, string $entryAccountId, string $recipientId, string $senderId): ?SocialAccount
    {
        // 1. Match by account_id on recipient or entry id
        $query = SocialAccount::active();
        if ($object === 'instagram') {
            $query->where('platform', 'instagram');
        } elseif ($object === 'page') {
            $query->where('platform', 'facebook');
        }

        $account = (clone $query)->whereIn('account_id', array_filter([$recipientId, $entryAccountId]))->first();
        if ($account) {
            return $account;
        }

        // 2. If object is instagram, match via linked_facebook_page_id in metadata
        if ($object === 'instagram') {
            $account = SocialAccount::active()
                ->where('platform', 'instagram')
                ->where(function ($q) use ($recipientId, $entryAccountId) {
                    $q->whereJsonContains('metadata->linked_facebook_page_id', $recipientId)
                      ->orWhereJsonContains('metadata->linked_facebook_page_id', $entryAccountId);
                })
                ->first();
            if ($account) {
                return $account;
            }
        }

        // 3. Match by sender_id in case of echo messages
        return SocialAccount::active()
            ->whereIn('account_id', array_filter([$senderId]))
            ->first();
    }

    /**
     * Process Facebook Messenger or Instagram Direct messaging event
     */
    protected function processMessagingEvent(
        string $object,
        string $entryAccountId,
        array $event,
        MetaGraphApiService $metaApi
    ): void {
        $senderId = (string) ($event['sender']['id'] ?? '');
        $recipientId = (string) ($event['recipient']['id'] ?? '');
        $timestamp = isset($event['timestamp']) ? date('Y-m-d H:i:s', (int) ($event['timestamp'] / 1000)) : now();

        $account = $this->resolveSocialAccount($object, $entryAccountId, $recipientId, $senderId);

        if (!$account) {
            Log::debug("[ProcessSocialWebhookJob] No active social account found matching recipient '{$recipientId}', entry '{$entryAccountId}', or sender '{$senderId}'");
            return;
        }

        $tenantId = $account->tenant_id;
        $platform = $account->platform; // 'facebook' or 'instagram'

        // A. Inbound Message Event or Postback Event
        if (!empty($event['message']) || !empty($event['postback'])) {
            $msgData = $event['message'] ?? [];
            $postbackData = $event['postback'] ?? [];
            $mid = (string) ($msgData['mid'] ?? ('pb_' . md5($senderId . '_' . ($event['timestamp'] ?? time()))));
            $text = (string) ($msgData['text'] ?? $postbackData['title'] ?? $postbackData['payload'] ?? '');
            $isEcho = !empty($msgData['is_echo']);

            if (!$mid) {
                return;
            }

            // Deduplication check via social_webhook_events
            $eventRecord = SocialWebhookEvent::firstOrCreate(
                ['event_id' => $mid],
                [
                    'tenant_id' => $tenantId,
                    'platform' => $platform,
                    'event_type' => 'messages',
                    'sender_id' => $senderId,
                    'recipient_id' => $recipientId,
                    'payload' => $event,
                ]
            );

            if ($eventRecord->processed) {
                Log::debug("[ProcessSocialWebhookJob] Skipping already processed message '{$mid}'");
                return;
            }

            $customerParticipantId = $isEcho ? $recipientId : $senderId;

            // Find or create SocialConversation
            $conversation = SocialConversation::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'social_account_id' => $account->id,
                    'participant_id' => $customerParticipantId,
                    'conversation_type' => SocialConversation::TYPE_DIRECT_MESSAGE,
                ],
                [
                    'platform' => $platform,
                    'participant_name' => 'Customer',
                    'status' => SocialConversation::STATUS_OPEN,
                    'priority' => SocialConversation::PRIORITY_NORMAL,
                    'is_unread' => true,
                    'unread_count' => 1,
                    'last_message_at' => $timestamp,
                ]
            );

            // Fetch profile if still default 'Customer'
            if ($conversation->participant_name === 'Customer') {
                $profile = $metaApi->getSenderProfile($platform, $customerParticipantId, $account->access_token);
                if ($profile) {
                    $conversation->update([
                        'participant_name' => $profile['name'] ?? $conversation->participant_name,
                        'participant_username' => $profile['username'] ?? $conversation->participant_username,
                        'participant_avatar_url' => $profile['profile_pic'] ?? $conversation->participant_avatar_url,
                    ]);
                }
            }

            $senderType = $isEcho ? SocialMessage::SENDER_AGENT : SocialMessage::SENDER_CUSTOMER;
            $preview = $text ?: '[Media Attachment]';

            // Create SocialMessage
            $socialMsg = SocialMessage::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'platform_message_id' => $mid,
                ],
                [
                    'social_conversation_id' => $conversation->id,
                    'social_account_id' => $account->id,
                    'sender_type' => $senderType,
                    'sender_id' => $senderId,
                    'sender_name' => $isEcho ? $account->name : $conversation->participant_name,
                    'sender_avatar_url' => $isEcho ? $account->profile_picture_url : $conversation->participant_avatar_url,
                    'message_text' => $text,
                    'status' => SocialMessage::STATUS_DELIVERED,
                    'sent_at' => $timestamp,
                ]
            );

            // Handle Attachments
            if (!empty($msgData['attachments'])) {
                foreach ($msgData['attachments'] as $att) {
                    $type = $att['type'] ?? 'image';
                    $url = $att['payload']['url'] ?? null;
                    if ($url) {
                        SocialMessageAttachment::firstOrCreate(
                            [
                                'tenant_id' => $tenantId,
                                'social_message_id' => $socialMsg->id,
                                'url' => $url,
                            ],
                            [
                                'attachment_type' => $type,
                            ]
                        );
                    }
                }
            }

            // Update conversation preview and unread counters
            $conversation->update([
                'last_message_at' => $timestamp,
                'last_message_preview' => $preview,
                'last_message_sender' => $isEcho ? 'agent' : 'customer',
                'is_unread' => $isEcho ? false : true,
                'unread_count' => $isEcho ? 0 : ($conversation->unread_count + 1),
                'status' => SocialConversation::STATUS_OPEN,
            ]);

            $eventRecord->update(['processed' => true, 'processed_at' => now()]);

            Log::info("[ProcessSocialWebhookJob] Successfully persisted incoming {$platform} message {$mid} for conversation #{$conversation->id}");

            // BROADCAST IN REAL-TIME TO AUTHORIZED TENANT CHANNEL
            try {
                broadcast(new SocialMessageReceived($conversation->fresh(['account', 'assignee', 'tags']), $socialMsg->fresh(['attachments', 'agentUser'])));
                Log::info("[ProcessSocialWebhookJob] Broadcasted SocialMessageReceived for conversation #{$conversation->id}");
            } catch (\Throwable $e) {
                Log::warning("[ProcessSocialWebhookJob] Broadcasting failed (non-fatal): " . $e->getMessage());
            }
        }

        // B. Delivery Event
        if (!empty($event['delivery'])) {
            $mids = $event['delivery']['mids'] ?? [];
            if (!empty($mids)) {
                SocialMessage::where('tenant_id', $tenantId)
                    ->whereIn('platform_message_id', $mids)
                    ->update([
                        'status' => SocialMessage::STATUS_DELIVERED,
                        'delivered_at' => $timestamp,
                    ]);
            }
        }

        // C. Read Event
        if (!empty($event['read'])) {
            $watermark = $event['read']['watermark'] ?? null;
            if ($watermark) {
                $readTime = date('Y-m-d H:i:s', (int) ($watermark / 1000));
                SocialMessage::where('tenant_id', $tenantId)
                    ->where('sender_id', (string) $account->account_id)
                    ->where('sent_at', '<=', $readTime)
                    ->whereNull('read_at')
                    ->update([
                        'status' => SocialMessage::STATUS_READ,
                        'read_at' => $readTime,
                    ]);
            }
        }
    }

    /**
     * Process changes: Facebook Page feed comments & Instagram media comments
     */
    protected function processChangeEvent(string $object, string $entryAccountId, array $change): void
    {
        $field = $change['field'] ?? '';
        $value = $change['value'] ?? [];

        // 1. Facebook Page feed comments
        if ($field === 'feed' && ($value['item'] ?? '') === 'comment') {
            $commentId = (string) ($value['comment_id'] ?? '');
            $postId = (string) ($value['post_id'] ?? '');
            $message = (string) ($value['message'] ?? '');
            $fromId = (string) ($value['from']['id'] ?? '');
            $fromName = (string) ($value['from']['name'] ?? 'User');

            $account = SocialAccount::active()->where('account_id', $entryAccountId)->first();
            if (!$account || $fromId === (string) $account->account_id) {
                return;
            }

            $this->persistCommentEvent($account, $commentId, $postId, $fromId, $fromName, null, $message);
        }

        // 2. Instagram media comments
        if ($field === 'comments') {
            $commentId = (string) ($value['id'] ?? '');
            $message = (string) ($value['text'] ?? '');
            $mediaId = (string) ($value['media']['id'] ?? '');
            $from = $value['from'] ?? [];
            $fromId = (string) ($from['id'] ?? '');
            $fromUsername = (string) ($from['username'] ?? 'User');

            $account = SocialAccount::active()
                ->where('platform', 'instagram')
                ->where(function ($q) use ($entryAccountId) {
                    $q->where('account_id', $entryAccountId)
                      ->orWhereJsonContains('metadata->linked_facebook_page_id', $entryAccountId);
                })
                ->first();

            if (!$account || $fromId === (string) $account->account_id) {
                return;
            }

            $this->persistCommentEvent($account, $commentId, $mediaId, $fromId, $fromUsername, $fromUsername, $message);
        }

        // 3. Meta Lead Ads Event (Facebook & Instagram Leadgen Webhook)
        if ($field === 'leadgen') {
            $leadgenId = (string) ($value['leadgen_id'] ?? '');
            $formId = (string) ($value['form_id'] ?? '');
            $pageId = (string) ($value['page_id'] ?? $entryAccountId);
            $adId = (string) ($value['ad_id'] ?? '');

            Log::info("[ProcessSocialWebhookJob] Inbound Meta Leadgen webhook detected: leadgen_id={$leadgenId}, form_id={$formId}, page_id={$pageId}");

            if (!empty($leadgenId) && !empty($pageId)) {
                try {
                    app(\App\Services\Social\MetaLeadAdsService::class)->handleInboundLeadWebhook([
                        'leadgen_id' => $leadgenId,
                        'form_id' => $formId,
                        'page_id' => $pageId,
                        'ad_id' => $adId,
                        'created_time' => $value['created_time'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    Log::error("[ProcessSocialWebhookJob] Failed to process leadgen webhook: " . $e->getMessage(), [
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }
    }

    /**
     * Persist incoming comment and broadcast real-time event
     */
    protected function persistCommentEvent(
        SocialAccount $account,
        string $commentId,
        string $postOrMediaId,
        string $fromId,
        string $fromName,
        ?string $fromUsername,
        string $message
    ): void {
        $tenantId = $account->tenant_id;

        // Deduplication
        $eventRecord = SocialWebhookEvent::firstOrCreate(
            ['event_id' => $commentId],
            [
                'tenant_id' => $tenantId,
                'platform' => $account->platform,
                'event_type' => 'comments',
                'sender_id' => $fromId,
                'recipient_id' => $account->account_id,
                'payload' => ['comment_id' => $commentId, 'post_id' => $postOrMediaId, 'message' => $message],
            ]
        );

        if ($eventRecord->processed) {
            return;
        }

        $conv = SocialConversation::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'social_account_id' => $account->id,
                'platform_conversation_id' => $commentId,
                'conversation_type' => SocialConversation::TYPE_COMMENT,
            ],
            [
                'platform' => $account->platform,
                'participant_id' => $fromId,
                'participant_name' => $fromName,
                'participant_username' => $fromUsername,
                'status' => SocialConversation::STATUS_OPEN,
                'priority' => SocialConversation::PRIORITY_NORMAL,
                'post_reference_id' => $postOrMediaId,
                'last_message_at' => now(),
                'last_message_preview' => $message,
                'last_message_sender' => 'customer',
                'is_unread' => true,
                'unread_count' => 1,
            ]
        );

        $socialMsg = SocialMessage::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform_message_id' => $commentId,
            ],
            [
                'social_conversation_id' => $conv->id,
                'social_account_id' => $account->id,
                'sender_type' => SocialMessage::SENDER_CUSTOMER,
                'sender_id' => $fromId,
                'sender_name' => $fromName,
                'message_text' => $message,
                'status' => SocialMessage::STATUS_DELIVERED,
                'sent_at' => now(),
            ]
        );

        $conv->update([
            'last_message_at' => now(),
            'last_message_preview' => $message,
            'last_message_sender' => 'customer',
            'is_unread' => true,
            'unread_count' => $conv->unread_count + 1,
        ]);

        $eventRecord->update(['processed' => true, 'processed_at' => now()]);

        // Real-time broadcast
        try {
            broadcast(new SocialMessageReceived($conv->fresh(['account', 'assignee', 'tags']), $socialMsg->fresh(['attachments', 'agentUser'])));
            Log::info("[ProcessSocialWebhookJob] Broadcasted SocialMessageReceived for comment conversation #{$conv->id}");
        } catch (\Throwable $e) {
            Log::warning("[ProcessSocialWebhookJob] Broadcasting comment failed (non-fatal): " . $e->getMessage());
        }
    }
}
