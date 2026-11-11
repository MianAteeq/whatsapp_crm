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
    public array $backoff = [10, 30, 60];

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
            Log::info("[ProcessSocialWebhookJob] Incoming entry id={$entryAccountId}, keys=" . implode(',', array_keys($entry)) . ", payload=" . json_encode($entry));

            // 1. Process messaging events (Facebook Messenger & Instagram Direct via entry.messaging)
            if (!empty($entry['messaging'])) {
                foreach ($entry['messaging'] as $event) {
                    $this->processMessagingEvent($object, $entryAccountId, $event, $metaApi);
                }
            }

            // 2. Process changes (Feed comments, Instagram comments, Leadgen, and Instagram direct messages via entry.changes)
            if (!empty($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    $field = $change['field'] ?? '';
                    if (in_array($field, ['messages', 'messaging', 'messaging_postbacks', 'messaging_seen', 'message_deliveries', 'message_reads'])) {
                        $val = $change['value'] ?? [];
                        if (isset($val['sender']) || isset($val['message']) || isset($val['postback']) || isset($val['delivery']) || isset($val['read'])) {
                            $this->processMessagingEvent($object, $entryAccountId, $val, $metaApi);
                        } elseif (is_array($val)) {
                            foreach ($val as $subEvent) {
                                if (is_array($subEvent)) {
                                    $this->processMessagingEvent($object, $entryAccountId, $subEvent, $metaApi);
                                }
                            }
                        }
                    } else {
                        $this->processChangeEvent($object, $entryAccountId, $change);
                    }
                }
            }
        }
    }

    /**
     * Resolve connected SocialAccount matching recipient, entry ID, or sender ID
     */
    protected function resolveSocialAccount(string $object, string $entryAccountId, string $recipientId, string $senderId): ?SocialAccount
    {
        // 1. Direct match on recipient_id (inbound message delivered to our Page or Instagram account)
        if (!empty($recipientId)) {
            $account = SocialAccount::active()
                ->where('account_id', $recipientId)
                ->latest('connected_at')
                ->first();
            if ($account) {
                return $account;
            }
        }

        // 2. Direct match on sender_id (outbound / echo message sent by our Page or Instagram account)
        if (!empty($senderId)) {
            $account = SocialAccount::active()
                ->where('account_id', $senderId)
                ->latest('connected_at')
                ->first();
            if ($account) {
                return $account;
            }
        }

        // 3. Match by entryAccountId with platform awareness
        if (!empty($entryAccountId)) {
            if ($object === 'instagram') {
                $account = SocialAccount::active()
                    ->where('platform', 'instagram')
                    ->where(function ($q) use ($entryAccountId) {
                        $q->where('account_id', $entryAccountId)
                          ->orWhereJsonContains('metadata->linked_facebook_page_id', $entryAccountId)
                          ->orWhere('metadata->linked_facebook_page_id', $entryAccountId);
                    })
                    ->latest('connected_at')
                    ->first();
                if ($account) {
                    return $account;
                }
            } else {
                // Object is 'page' or generic - check if recipient or sender is an Instagram account connected to this page
                $igAccount = SocialAccount::active()
                    ->where('platform', 'instagram')
                    ->where(function ($q) use ($recipientId, $senderId, $entryAccountId) {
                        $q->whereIn('account_id', array_filter([$recipientId, $senderId]))
                          ->orWhereJsonContains('metadata->linked_facebook_page_id', $entryAccountId);
                    })
                    ->latest('connected_at')
                    ->first();

                if ($igAccount && (!empty($recipientId) && $igAccount->account_id === $recipientId || !empty($senderId) && $igAccount->account_id === $senderId)) {
                    return $igAccount;
                }

                $fbAccount = SocialAccount::active()
                    ->where('platform', 'facebook')
                    ->where('account_id', $entryAccountId)
                    ->latest('connected_at')
                    ->first();
                if ($fbAccount) {
                    return $fbAccount;
                }

                if ($igAccount) {
                    return $igAccount;
                }
            }
        }

        // 4. Fallback: match any active account matching any of the candidate IDs
        return SocialAccount::active()
            ->whereIn('account_id', array_filter([$recipientId, $entryAccountId, $senderId]))
            ->latest('connected_at')
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

        Log::info("[ProcessSocialWebhookJob] Processing messaging event: object={$object}, sender={$senderId}, recipient={$recipientId}, entry={$entryAccountId}");

        $account = $this->resolveSocialAccount($object, $entryAccountId, $recipientId, $senderId);

        if (!$account) {
            Log::warning("[ProcessSocialWebhookJob] No active social account found matching recipient '{$recipientId}', entry '{$entryAccountId}', or sender '{$senderId}'");
            return;
        }

        $tenantId = $account->tenant_id;
        $platform = $account->platform; // 'facebook' or 'instagram'
        Log::info("[ProcessSocialWebhookJob] Resolved to {$platform} account ID #{$account->id} ('{$account->name}') for tenant {$tenantId}");

        // Record last webhook received time for this tenant's social account
        $account->update(['last_webhook_received_at' => now()]);

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

            // TRIGGER CRM WORKFLOW AUTOMATION FOR THIS TENANT
            try {
                if (class_exists(\App\Services\Workflow\WorkflowTriggerManager::class)) {
                    \App\Services\Workflow\WorkflowTriggerManager::dispatchEvent(
                        'incoming_message',
                        $tenantId,
                        [
                            'message' => $socialMsg,
                            'contact' => $conversation->contact,
                            'platform' => $platform,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning("[ProcessSocialWebhookJob] Workflow trigger dispatch failed (non-fatal): " . $e->getMessage());
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
        if ($field === 'feed') {
            $verb = (string) ($value['verb'] ?? 'add');
            $commentId = (string) ($value['comment_id'] ?? '');

            // Ignore deletions, reactions or events without a comment_id
            if ($verb === 'remove' || $verb === 'hide' || empty($commentId)) {
                return;
            }

            $postId = (string) ($value['post_id'] ?? '');
            $parentId = (string) ($value['parent_id'] ?? '');
            $message = (string) ($value['message'] ?? '');
            $from = $value['from'] ?? [];
            $fromId = (string) ($from['id'] ?? '');
            $fromName = (string) ($from['name'] ?? 'User');

            $account = SocialAccount::active()
                ->where('platform', 'facebook')
                ->where('account_id', $entryAccountId)
                ->latest('connected_at')
                ->first();

            if (!$account) {
                $account = SocialAccount::active()
                    ->where('platform', 'facebook')
                    ->latest('connected_at')
                    ->first();
            }

            if (!$account) {
                Log::warning("[ProcessSocialWebhookJob] Facebook comment event dropped: no active Facebook account matching {$entryAccountId}");
                return;
            }

            $isAgent = ($fromId === (string) $account->account_id);

            $this->persistCommentEvent(
                account: $account,
                commentId: $commentId,
                postOrMediaId: $postId,
                parentId: $parentId,
                fromId: $fromId,
                fromName: $fromName,
                fromUsername: null,
                message: $message,
                isAgent: $isAgent
            );
        }

        // 2. Instagram media comments
        if ($field === 'comments') {
            $commentId = (string) ($value['id'] ?? '');
            if (empty($commentId)) {
                return;
            }

            $message = (string) ($value['text'] ?? '');
            $mediaId = (string) ($value['media']['id'] ?? '');
            $parentId = (string) ($value['parent_id'] ?? '');
            $from = $value['from'] ?? [];
            $fromId = (string) ($from['id'] ?? '');
            $fromUsername = (string) ($from['username'] ?? 'User');

            $account = SocialAccount::active()
                ->where('platform', 'instagram')
                ->where(function ($q) use ($entryAccountId) {
                    $q->where('account_id', $entryAccountId)
                      ->orWhereJsonContains('metadata->linked_facebook_page_id', $entryAccountId)
                      ->orWhere('metadata->linked_facebook_page_id', $entryAccountId);
                })
                ->latest('connected_at')
                ->first();

            if (!$account) {
                $account = SocialAccount::active()
                    ->where('platform', 'instagram')
                    ->latest('connected_at')
                    ->first();
            }

            if (!$account) {
                Log::warning("[ProcessSocialWebhookJob] Instagram comment event dropped: no active Instagram account matching {$entryAccountId}");
                return;
            }

            $isAgent = ($fromId === (string) $account->account_id);

            $this->persistCommentEvent(
                account: $account,
                commentId: $commentId,
                postOrMediaId: $mediaId,
                parentId: $parentId,
                fromId: $fromId,
                fromName: $fromUsername,
                fromUsername: $fromUsername,
                message: $message,
                isAgent: $isAgent
            );
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
        ?string $parentId,
        string $fromId,
        string $fromName,
        ?string $fromUsername,
        string $message,
        bool $isAgent = false
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
                'payload' => [
                    'comment_id' => $commentId,
                    'post_id' => $postOrMediaId,
                    'parent_id' => $parentId,
                    'message' => $message,
                    'is_agent' => $isAgent,
                ],
            ]
        );

        if ($eventRecord->processed) {
            return;
        }

        // Determine if this is a reply to an existing comment
        $conv = null;
        if (!empty($parentId) && $parentId !== $postOrMediaId) {
            // 1. Try finding conversation where platform_conversation_id = $parentId
            $conv = SocialConversation::where('tenant_id', $tenantId)
                ->where('social_account_id', $account->id)
                ->where('platform_conversation_id', $parentId)
                ->first();

            // 2. Or matching a conversation that contains a message with platform_message_id = $parentId
            if (!$conv) {
                $parentMsg = SocialMessage::where('tenant_id', $tenantId)
                    ->where('platform_message_id', $parentId)
                    ->first();
                if ($parentMsg && $parentMsg->conversation) {
                    $conv = $parentMsg->conversation;
                }
            }
        }

        // If no parent conversation found, create or find root comment conversation
        if (!$conv) {
            $conv = SocialConversation::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'social_account_id' => $account->id,
                    'platform_conversation_id' => $commentId,
                    'conversation_type' => SocialConversation::TYPE_COMMENT,
                ],
                [
                    'platform' => $account->platform,
                    'participant_id' => $fromId ?: $commentId,
                    'participant_name' => $fromName,
                    'participant_username' => $fromUsername,
                    'status' => SocialConversation::STATUS_OPEN,
                    'priority' => SocialConversation::PRIORITY_NORMAL,
                    'post_reference_id' => $postOrMediaId,
                    'last_message_at' => now(),
                    'last_message_preview' => $message,
                    'last_message_sender' => $isAgent ? 'agent' : 'customer',
                    'is_unread' => !$isAgent,
                    'unread_count' => $isAgent ? 0 : 1,
                ]
            );
        }

        $senderType = $isAgent ? SocialMessage::SENDER_AGENT : SocialMessage::SENDER_CUSTOMER;

        $socialMsg = SocialMessage::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform_message_id' => $commentId,
            ],
            [
                'social_conversation_id' => $conv->id,
                'social_account_id' => $account->id,
                'sender_type' => $senderType,
                'sender_id' => $fromId ?: (string) $account->account_id,
                'sender_name' => $fromName,
                'message_text' => $message,
                'status' => SocialMessage::STATUS_DELIVERED,
                'sent_at' => now(),
            ]
        );

        $conv->update([
            'last_message_at' => now(),
            'last_message_preview' => $message,
            'last_message_sender' => $isAgent ? 'agent' : 'customer',
            'is_unread' => $isAgent ? $conv->is_unread : true,
            'unread_count' => $isAgent ? $conv->unread_count : ($conv->unread_count + 1),
        ]);

        $eventRecord->update(['processed' => true, 'processed_at' => now()]);

        // Real-time broadcast
        try {
            broadcast(new SocialMessageReceived(
                $conv->fresh(['account', 'assignee', 'tags']),
                $socialMsg->fresh(['attachments', 'agentUser'])
            ));
            Log::info("[ProcessSocialWebhookJob] Broadcasted SocialMessageReceived for comment conversation #{$conv->id}");
        } catch (\Throwable $e) {
            Log::warning("[ProcessSocialWebhookJob] Broadcasting comment failed (non-fatal): " . $e->getMessage());
        }
    }

    /**
     * Handle failed job execution after max retries
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("[ProcessSocialWebhookJob] Job failed permanently after retries: " . $exception->getMessage(), [
            'exception' => $exception,
            'payload' => $this->payload,
        ]);
    }
}
