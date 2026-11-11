<?php

namespace App\Services\Social;

use App\Events\SocialMessageReceived;
use App\Events\SocialConversationUpdated;
use App\Models\Contact;
use App\Models\SocialAccount;
use App\Models\SocialComment;
use App\Models\SocialConversation;
use App\Models\SocialConversationAssignment;
use App\Models\SocialConversationNote;
use App\Models\SocialConversationTag;
use App\Models\SocialMessage;
use App\Models\SocialMessageAttachment;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SocialInboxService
{
    public function __construct(
        protected MetaGraphApiService $metaApi
    ) {}

    /**
     * Get conversations list with filtering, searching, and pagination
     */
    public function getConversations(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        $query = SocialConversation::forTenant($tenantId)
            ->with([
                'account:id,platform,name,username,profile_picture_url',
                'assignee:id,name,email,avatar',
                'contact:id,name,phone,email',
                'tags',
            ])
            ->orderByDesc('last_message_at');

        // Filter by platform (facebook / instagram)
        if (!empty($filters['platform']) && in_array($filters['platform'], ['facebook', 'instagram'])) {
            $query->where('platform', $filters['platform']);
        }

        // Filter by conversation type (direct_message / comment)
        if (!empty($filters['conversation_type']) && in_array($filters['conversation_type'], ['direct_message', 'comment'])) {
            $query->where('conversation_type', $filters['conversation_type']);
        }

        // Filter by account ID
        if (!empty($filters['account_id'])) {
            $query->where('social_account_id', (int) $filters['account_id']);
        }

        // Status or view filter
        $view = $filters['view'] ?? $filters['status'] ?? 'all';
        switch ($view) {
            case 'unread':
                $query->where('is_unread', true);
                break;
            case 'assigned_me':
                if (!empty($filters['current_user_id'])) {
                    $query->where('assigned_to_user_id', (int) $filters['current_user_id']);
                }
                break;
            case 'unassigned':
                $query->whereNull('assigned_to_user_id');
                break;
            case 'open':
                $query->where('status', SocialConversation::STATUS_OPEN);
                break;
            case 'pending':
                $query->where('status', SocialConversation::STATUS_PENDING);
                break;
            case 'resolved':
                $query->where('status', SocialConversation::STATUS_RESOLVED);
                break;
            default:
                break;
        }

        // Search in participant name, username, or message preview
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $query->where(function ($q) use ($term) {
                $q->where('participant_name', 'like', "%{$term}%")
                  ->orWhere('participant_username', 'like', "%{$term}%")
                  ->orWhere('last_message_preview', 'like', "%{$term}%");
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        return $query->paginate($perPage);
    }

    /**
     * Get a specific conversation with all its messages, attachments, notes, and tags
     */
    public function getConversationDetails(int $tenantId, int $conversationId): SocialConversation
    {
        $conversation = SocialConversation::forTenant($tenantId)
            ->with([
                'account:id,platform,name,username,profile_picture_url',
                'assignee:id,name,email,avatar',
                'contact:id,name,phone,email,company,job_title,website,notes',
                'tags',
                'notes.user:id,name,avatar',
                'assignments.assignedBy:id,name',
                'assignments.assignedTo:id,name',
                'messages.attachments',
                'messages.agentUser:id,name,avatar',
            ])
            ->findOrFail($conversationId);

        // Mark as read when opened
        if ($conversation->is_unread) {
            $conversation->update([
                'is_unread' => false,
                'unread_count' => 0,
            ]);
        }

        return $conversation;
    }

    /**
     * Send an outbound direct message or comment reply
     */
    public function sendMessage(
        int $tenantId,
        int $conversationId,
        ?string $text,
        ?string $attachmentUrl = null,
        ?string $attachmentType = 'image',
        ?int $userId = null
    ): SocialMessage {
        $conversation = SocialConversation::forTenant($tenantId)
            ->with('account')
            ->findOrFail($conversationId);

        $account = $conversation->account;
        if (!$account || !$account->is_active) {
            throw new Exception("The connected social account is inactive or disconnected.");
        }

        $accessToken = $account->access_token;
        $platformMessageId = null;

        // 1. Direct Message send via Meta Graph API
        if ($conversation->conversation_type === SocialConversation::TYPE_DIRECT_MESSAGE) {
            $metaRes = $this->metaApi->sendMessage(
                $conversation->platform,
                $account->account_id,
                $conversation->participant_id,
                $text,
                $attachmentUrl,
                $attachmentType,
                $accessToken
            );

            $platformMessageId = $metaRes['message_id'] ?? $metaRes['id'] ?? null;
        } else {
            // 2. Comment reply send via Meta Graph API
            $targetCommentId = $conversation->platform_conversation_id ?: $conversation->participant_id;
            $metaRes = $this->metaApi->replyToComment(
                $conversation->platform,
                $targetCommentId,
                $text ?? '',
                $accessToken
            );

            $platformMessageId = $metaRes['id'] ?? null;
        }

        // 3. Persist outbound message in database
        $crmUser = $userId ? User::find($userId) : null;
        $preview = $text ?: ($attachmentUrl ? "[{$attachmentType} Attachment]" : '');

        $message = DB::transaction(function () use (
            $tenantId,
            $conversation,
            $account,
            $userId,
            $crmUser,
            $text,
            $attachmentUrl,
            $attachmentType,
            $platformMessageId,
            $preview
        ) {
            $msg = SocialMessage::create([
                'tenant_id' => $tenantId,
                'social_conversation_id' => $conversation->id,
                'social_account_id' => $account->id,
                'sender_type' => SocialMessage::SENDER_AGENT,
                'sender_user_id' => $userId,
                'sender_id' => (string) $account->account_id,
                'sender_name' => $crmUser ? $crmUser->name : $account->name,
                'sender_avatar_url' => $crmUser ? $crmUser->avatar : $account->profile_picture_url,
                'message_text' => $text,
                'platform_message_id' => $platformMessageId,
                'status' => SocialMessage::STATUS_SENT,
                'sent_at' => now(),
            ]);

            if ($attachmentUrl) {
                SocialMessageAttachment::create([
                    'tenant_id' => $tenantId,
                    'social_message_id' => $msg->id,
                    'attachment_type' => $attachmentType ?? 'image',
                    'url' => $attachmentUrl,
                    'file_name' => basename(parse_url($attachmentUrl, PHP_URL_PATH)),
                ]);
            }

            // Update conversation last message & timestamp
            $conversation->update([
                'last_message_at' => now(),
                'last_message_preview' => $preview,
                'last_message_sender' => 'agent',
                'is_unread' => false,
                'unread_count' => 0,
            ]);

            return $msg;
        });

        $loadedMessage = $message->load('attachments', 'agentUser:id,name,avatar');

        // Broadcast outbound message to other open client windows/team members
        try {
            broadcast(new SocialMessageReceived($conversation->fresh(['account', 'assignee', 'tags']), $loadedMessage));
        } catch (\Throwable $e) {
            Log::debug("[SocialInboxService] Outbound broadcast skipped: " . $e->getMessage());
        }

        return $loadedMessage;
    }

    /**
     * Synchronize conversations from Meta Graph API for connected accounts
     */
    public function syncConversations(int $tenantId, ?int $accountId = null): int
    {
        $accountsQuery = SocialAccount::forTenant($tenantId)->active();
        if ($accountId) {
            $accountsQuery->where('id', $accountId);
        }

        $accounts = $accountsQuery->get();
        $totalSynced = 0;

        foreach ($accounts as $account) {
            try {
                // Ensure page webhook is subscribed
                if ($account->platform === 'facebook') {
                    $this->metaApi->subscribePageToWebhooks($account->account_id, $account->access_token);
                }

                $linkedPageId = $account->metadata['linked_facebook_page_id'] ?? null;

                // 1. Fetch Direct Message conversations from Graph API
                $metaConversations = $this->metaApi->getConversations(
                    $account->platform,
                    $account->account_id,
                    $account->access_token,
                    30,
                    $linkedPageId
                );

                foreach ($metaConversations as $metaConv) {
                    $convId = (string) $metaConv['id'];
                    $updatedTime = isset($metaConv['updated_time'])
                        ? date('Y-m-d H:i:s', strtotime($metaConv['updated_time']))
                        : now();
                    $snippet = $metaConv['snippet'] ?? '';
                    $unreadCount = (int) ($metaConv['unread_count'] ?? 0);

                    // Determine customer participant (the one who is not the connected account or linked page)
                    $participants = $metaConv['participants']['data'] ?? [];
                    $customerPart = null;
                    $selfIds = array_filter([(string) $account->account_id, (string) $linkedPageId]);

                    foreach ($participants as $p) {
                        if (!in_array((string) ($p['id'] ?? ''), $selfIds, true)) {
                            $customerPart = $p;
                            break;
                        }
                    }

                    if (!$customerPart && !empty($participants)) {
                        $customerPart = $participants[0];
                    }

                    $participantId = (string) ($customerPart['id'] ?? 'unknown');
                    $participantName = (string) ($customerPart['name'] ?? $customerPart['username'] ?? 'Customer');
                    $participantUsername = (string) ($customerPart['username'] ?? null);
                    $participantAvatar = null;

                    // Query customer profile info if missing
                    if ($participantName === 'Customer' || !$participantUsername) {
                        $profile = $this->metaApi->getSenderProfile($account->platform, $participantId, $account->access_token);
                        if ($profile) {
                            if (!empty($profile['name'])) $participantName = $profile['name'];
                            if (!empty($profile['username'])) $participantUsername = $profile['username'];
                            if (!empty($profile['profile_pic'])) $participantAvatar = $profile['profile_pic'];
                        }
                    }

                    // Auto-match existing CRM Contact
                    $matchedContact = $this->matchContact($tenantId, $participantName, $participantUsername);

                    // Find or create conversation
                    $conversation = SocialConversation::updateOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'social_account_id' => $account->id,
                            'platform_conversation_id' => $convId,
                        ],
                        [
                            'contact_id' => $matchedContact ? $matchedContact->id : null,
                            'platform' => $account->platform,
                            'conversation_type' => SocialConversation::TYPE_DIRECT_MESSAGE,
                            'participant_id' => $participantId,
                            'participant_name' => $participantName,
                            'participant_username' => $participantUsername,
                            'participant_avatar_url' => $participantAvatar,
                            'is_unread' => $unreadCount > 0,
                            'unread_count' => $unreadCount,
                            'last_message_at' => $updatedTime,
                            'last_message_preview' => $snippet,
                        ]
                    );

                    // Ingest messages within this conversation
                    $messagesList = $metaConv['messages']['data'] ?? [];
                    foreach ($messagesList as $m) {
                        $this->ingestMetaMessage($tenantId, $conversation, $account, $m);
                    }

                    $totalSynced++;
                }

                // 2. Also sync recent post/media comments into comment-type conversations
                $this->syncCommentsIntoConversations($tenantId, $account);

            } catch (Exception $e) {
                Log::warning("[SocialInboxService] Failed to sync conversations for account {$account->id} ({$account->name}): " . $e->getMessage());
            }
        }

        return $totalSynced;
    }

    /**
     * Ingest an individual message object from Meta into a conversation
     */
    public function ingestMetaMessage(
        int $tenantId,
        SocialConversation $conversation,
        SocialAccount $account,
        array $metaMessage
    ): SocialMessage {
        $mid = (string) ($metaMessage['id'] ?? '');
        $text = (string) ($metaMessage['message'] ?? $metaMessage['text'] ?? '');
        $createdTime = isset($metaMessage['created_time'])
            ? date('Y-m-d H:i:s', strtotime($metaMessage['created_time']))
            : now();

        $from = $metaMessage['from'] ?? [];
        $fromId = (string) ($from['id'] ?? '');
        $fromName = (string) ($from['name'] ?? $from['username'] ?? '');

        $linkedPageId = $account->metadata['linked_facebook_page_id'] ?? null;
        $isFromAccount = ($fromId === (string) $account->account_id || ($linkedPageId && $fromId === (string) $linkedPageId));
        $senderType = $isFromAccount ? SocialMessage::SENDER_AGENT : SocialMessage::SENDER_CUSTOMER;

        $message = SocialMessage::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'platform_message_id' => $mid,
            ],
            [
                'social_conversation_id' => $conversation->id,
                'social_account_id' => $account->id,
                'sender_type' => $senderType,
                'sender_id' => $fromId ?: $conversation->participant_id,
                'sender_name' => $fromName ?: ($isFromAccount ? $account->name : $conversation->participant_name),
                'sender_avatar_url' => $isFromAccount ? $account->profile_picture_url : $conversation->participant_avatar_url,
                'message_text' => $text,
                'status' => SocialMessage::STATUS_DELIVERED,
                'sent_at' => $createdTime,
            ]
        );

        // Attachments
        $attachments = $metaMessage['attachments']['data'] ?? [];
        foreach ($attachments as $att) {
            $attType = 'image';
            $url = $att['file_url'] ?? $att['image_data']['url'] ?? $att['video_data']['url'] ?? null;
            if (isset($att['image_data'])) $attType = 'image';
            elseif (isset($att['video_data'])) $attType = 'video';
            elseif (isset($att['mime_type']) && str_starts_with($att['mime_type'], 'audio/')) $attType = 'audio';

            if ($url) {
                SocialMessageAttachment::firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'social_message_id' => $message->id,
                        'url' => $url,
                    ],
                    [
                        'attachment_type' => $attType,
                        'mime_type' => $att['mime_type'] ?? null,
                        'file_name' => $att['name'] ?? null,
                    ]
                );
            }
        }

        return $message;
    }

    /**
     * Sync comments into comment-type conversations
     */
    protected function syncCommentsIntoConversations(int $tenantId, SocialAccount $account): void
    {
        $targets = SocialPostTarget::where('social_account_id', $account->id)
            ->where('status', SocialPostTarget::STATUS_PUBLISHED)
            ->whereNotNull('platform_post_id')
            ->latest()
            ->take(10)
            ->get();

        foreach ($targets as $target) {
            $rawComments = $this->metaApi->getComments(
                $account->platform,
                $target->platform_post_id,
                $account->access_token
            );

            // Handle deleted / non-existent posts on Meta (Error 100/33)
            if (isset($rawComments['__deleted__'])) {
                Log::info("[SocialInboxService] Target {$target->id} (platform_post_id: {$target->platform_post_id}) does not exist on {$account->platform}. Clearing stale ID.");
                $target->update(['platform_post_id' => null, 'error_message' => 'Post was deleted or removed on platform']);
                continue;
            }

            foreach ($rawComments as $c) {
                $commentId = (string) $c['id'];
                $messageText = (string) ($c['message'] ?? $c['text'] ?? '');
                $createdTime = isset($c['created_time']) || isset($c['timestamp'])
                    ? date('Y-m-d H:i:s', strtotime($c['created_time'] ?? $c['timestamp']))
                    : now();

                $from = $c['from'] ?? [];
                $commenterId = (string) ($from['id'] ?? '');
                $commenterName = (string) ($from['name'] ?? $from['username'] ?? 'User');
                $commenterAvatar = $from['picture']['data']['url'] ?? null;

                // Create or find comment conversation
                $conv = SocialConversation::firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'social_account_id' => $account->id,
                        'platform_conversation_id' => $commentId,
                        'conversation_type' => SocialConversation::TYPE_COMMENT,
                    ],
                    [
                        'platform' => $account->platform,
                        'participant_id' => $commenterId ?: $commentId,
                        'participant_name' => $commenterName,
                        'participant_username' => $from['username'] ?? null,
                        'participant_avatar_url' => $commenterAvatar,
                        'status' => SocialConversation::STATUS_OPEN,
                        'priority' => SocialConversation::PRIORITY_NORMAL,
                        'post_reference_id' => $target->platform_post_id,
                        'post_reference_url' => $target->platform_post_url,
                        'last_message_at' => $createdTime,
                        'last_message_preview' => $messageText,
                        'last_message_sender' => 'customer',
                    ]
                );

                // Ingest the customer comment as first message
                SocialMessage::firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'platform_message_id' => $commentId,
                    ],
                    [
                        'social_conversation_id' => $conv->id,
                        'social_account_id' => $account->id,
                        'sender_type' => SocialMessage::SENDER_CUSTOMER,
                        'sender_id' => $commenterId ?: $commentId,
                        'sender_name' => $commenterName,
                        'sender_avatar_url' => $commenterAvatar,
                        'message_text' => $messageText,
                        'status' => SocialMessage::STATUS_DELIVERED,
                        'sent_at' => $createdTime,
                    ]
                );

                // If comment has replies in metadata, ingest them
                $replies = $c['comments']['data'] ?? $c['replies']['data'] ?? [];
                foreach ($replies as $rep) {
                    $repId = (string) $rep['id'];
                    $repText = (string) ($rep['message'] ?? $rep['text'] ?? '');
                    $repFrom = $rep['from'] ?? [];
                    $isPageReply = ((string) ($repFrom['id'] ?? '') === (string) $account->account_id);

                    SocialMessage::firstOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'platform_message_id' => $repId,
                        ],
                        [
                            'social_conversation_id' => $conv->id,
                            'social_account_id' => $account->id,
                            'sender_type' => $isPageReply ? SocialMessage::SENDER_AGENT : SocialMessage::SENDER_CUSTOMER,
                            'sender_id' => (string) ($repFrom['id'] ?? ''),
                            'sender_name' => (string) ($repFrom['name'] ?? $repFrom['username'] ?? ''),
                            'message_text' => $repText,
                            'status' => SocialMessage::STATUS_DELIVERED,
                            'sent_at' => isset($rep['created_time']) ? date('Y-m-d H:i:s', strtotime($rep['created_time'])) : now(),
                        ]
                    );
                }
            }
        }
    }

    /**
     * Match customer name / username to existing CRM Contact
     */
    protected function matchContact(int $tenantId, string $name, ?string $username = null): ?Contact
    {
        if (empty($name) && empty($username)) {
            return null;
        }

        // 1. Try matching by exact name
        if ($name && $name !== 'Customer' && $name !== 'User') {
            $contact = Contact::where('tenant_id', $tenantId)
                ->where('name', $name)
                ->first();
            if ($contact) return $contact;
        }

        // 2. Try matching notes or website
        if ($username) {
            $contact = Contact::where('tenant_id', $tenantId)
                ->where(function ($q) use ($username) {
                    $q->where('notes', 'like', "%@{$username}%")
                      ->orWhere('name', 'like', "%{$username}%");
                })
                ->first();
            if ($contact) return $contact;
        }

        return null;
    }

    /**
     * Incremental delta synchronization for real-time fallback and reconnection catch-up
     */
    public function getDeltaSync(
        int $tenantId,
        ?string $lastSyncTime = null,
        ?int $activeConversationId = null,
        ?int $sinceMessageId = null
    ): array {
        $serverTime = now()->toISOString();
        $updatedConversations = [];
        $newMessages = [];

        // 1. Fetch conversations updated since last_sync
        $convQuery = SocialConversation::forTenant($tenantId)
            ->with([
                'account:id,platform,name,username,profile_picture_url',
                'assignee:id,name,email,avatar',
                'tags',
            ])
            ->orderByDesc('last_message_at');

        if ($lastSyncTime) {
            $parsedSync = date('Y-m-d H:i:s', strtotime($lastSyncTime));
            $convQuery->where(function ($q) use ($parsedSync) {
                $q->where('updated_at', '>=', $parsedSync)
                  ->orWhere('last_message_at', '>=', $parsedSync);
            });
        } else {
            $convQuery->take(40);
        }

        $updatedConversations = $convQuery->get();

        // 2. Fetch new messages for the active conversation if requested
        if ($activeConversationId) {
            $msgQuery = SocialMessage::forTenant($tenantId)
                ->where('social_conversation_id', $activeConversationId)
                ->with(['attachments', 'agentUser:id,name,avatar'])
                ->orderBy('sent_at', 'asc');

            if ($sinceMessageId) {
                $msgQuery->where('id', '>', $sinceMessageId);
            } elseif ($lastSyncTime) {
                $msgQuery->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($lastSyncTime)));
            }

            $newMessages = $msgQuery->get();
        }

        return [
            'server_time' => $serverTime,
            'conversations' => $updatedConversations,
            'messages' => $newMessages,
        ];
    }

    /**
     * Link an existing contact to a conversation
     */
    public function linkContact(int $tenantId, int $conversationId, int $contactId): SocialConversation
    {
        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);
        $contact = Contact::where('tenant_id', $tenantId)->findOrFail($contactId);

        $conversation->update(['contact_id' => $contact->id]);
        return $conversation->load('contact');
    }

    /**
     * Create a new CRM contact from a conversation's customer data
     */
    public function createContactFromConversation(int $tenantId, int $conversationId, array $data = []): Contact
    {
        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);

        $name = !empty($data['name']) ? $data['name'] : $conversation->participant_name;
        $phone = !empty($data['phone']) ? $data['phone'] : '+0000000000'; // Default placeholder if phone not provided by Meta
        $email = !empty($data['email']) ? $data['email'] : null;
        $company = $data['company'] ?? null;
        $notes = "Created from Social Inbox ({$conversation->platform} - {$conversation->participant_name})";
        if ($conversation->participant_username) {
            $notes .= "\nUsername: @{$conversation->participant_username}";
        }

        $contact = Contact::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'company' => $company,
            'notes' => $notes,
            'status' => 'active',
        ]);

        $conversation->update(['contact_id' => $contact->id]);

        return $contact;
    }

    /**
     * Update conversation status (open, pending, resolved)
     */
    public function updateStatus(int $tenantId, int $conversationId, string $status): SocialConversation
    {
        if (!in_array($status, [SocialConversation::STATUS_OPEN, SocialConversation::STATUS_PENDING, SocialConversation::STATUS_RESOLVED])) {
            throw new Exception("Invalid status '{$status}'.");
        }

        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);
        $conversation->update(['status' => $status]);

        return $conversation;
    }

    /**
     * Update conversation priority (urgent, high, normal, low)
     */
    public function updatePriority(int $tenantId, int $conversationId, string $priority): SocialConversation
    {
        if (!in_array($priority, [
            SocialConversation::PRIORITY_URGENT,
            SocialConversation::PRIORITY_HIGH,
            SocialConversation::PRIORITY_NORMAL,
            SocialConversation::PRIORITY_LOW,
        ])) {
            throw new Exception("Invalid priority '{$priority}'.");
        }

        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);
        $conversation->update(['priority' => $priority]);

        return $conversation;
    }

    /**
     * Toggle read/unread state
     */
    public function toggleUnread(int $tenantId, int $conversationId, ?bool $isUnread = null): SocialConversation
    {
        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);
        $newUnread = $isUnread !== null ? $isUnread : !$conversation->is_unread;

        $conversation->update([
            'is_unread' => $newUnread,
            'unread_count' => $newUnread ? max(1, $conversation->unread_count) : 0,
        ]);

        return $conversation;
    }

    /**
     * Assign conversation to a team member
     */
    public function assignToUser(
        int $tenantId,
        int $conversationId,
        ?int $assignedToUserId,
        ?int $assignedByUserId = null,
        ?string $reason = null
    ): SocialConversation {
        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);

        if ($assignedToUserId) {
            $user = User::where('tenant_id', $tenantId)->findOrFail($assignedToUserId);
        }

        $conversation->update(['assigned_to_user_id' => $assignedToUserId]);

        SocialConversationAssignment::create([
            'tenant_id' => $tenantId,
            'social_conversation_id' => $conversation->id,
            'assigned_by_user_id' => $assignedByUserId,
            'assigned_to_user_id' => $assignedToUserId,
            'reason' => $reason,
        ]);

        return $conversation->load('assignee:id,name,email,avatar');
    }

    /**
     * Add an internal note to the conversation
     */
    public function addNote(int $tenantId, int $conversationId, int $userId, string $content): SocialConversationNote
    {
        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);

        return SocialConversationNote::create([
            'tenant_id' => $tenantId,
            'social_conversation_id' => $conversation->id,
            'user_id' => $userId,
            'content' => trim($content),
        ])->load('user:id,name,avatar');
    }

    /**
     * Delete an internal note
     */
    public function deleteNote(int $tenantId, int $noteId): bool
    {
        $note = SocialConversationNote::where('tenant_id', $tenantId)->findOrFail($noteId);
        return (bool) $note->delete();
    }

    /**
     * Add a tag to the conversation
     */
    public function addTag(int $tenantId, int $conversationId, string $tag, ?string $color = '#10B981'): SocialConversationTag
    {
        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);

        return SocialConversationTag::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'social_conversation_id' => $conversation->id,
                'tag' => trim($tag),
            ],
            [
                'color' => $color ?: '#10B981',
            ]
        );
    }

    /**
     * Remove a tag from the conversation
     */
    public function removeTag(int $tenantId, int $conversationId, string $tag): bool
    {
        $conversation = SocialConversation::forTenant($tenantId)->findOrFail($conversationId);

        return (bool) SocialConversationTag::where('tenant_id', $tenantId)
            ->where('social_conversation_id', $conversation->id)
            ->where('tag', $tag)
            ->delete();
    }

    /**
     * Get team members who can be assigned conversations
     */
    public function getAssignableUsers(int $tenantId): Collection
    {
        return User::where('tenant_id', $tenantId)
            ->select('id', 'name', 'email', 'avatar', 'role')
            ->orderBy('name')
            ->get();
    }

    /**
     * Return quick saved replies for agent convenience
     */
    public function getQuickReplies(): array
    {
        return [
            ['title' => 'Greeting', 'text' => 'Hi there! Thanks for reaching out. How can I help you today?'],
            ['title' => 'Order Status', 'text' => 'Could you please share your order number or registered email so I can check that for you right away?'],
            ['title' => 'Hours of Operation', 'text' => 'Our support team is available Monday to Friday from 9:00 AM to 6:00 PM.'],
            ['title' => 'Call Request', 'text' => 'I would be happy to have one of our specialists give you a call. What is the best phone number and time to reach you?'],
            ['title' => 'Closing', 'text' => 'Thank you for contacting us! Let us know if you need anything else. Have a wonderful day!'],
        ];
    }
}
