<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Services\Social\SocialInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class SocialInboxController extends Controller
{
    public function __construct(
        protected SocialInboxService $inboxService
    ) {}

    /**
     * List conversations with filtering, search, and pagination
     */
    public function conversations(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $filters = [
            'view' => $request->input('view', 'all'),
            'status' => $request->input('status'),
            'platform' => $request->input('platform'),
            'conversation_type' => $request->input('conversation_type'),
            'account_id' => $request->input('account_id'),
            'search' => $request->input('search'),
            'per_page' => $request->input('per_page', 30),
            'current_user_id' => $request->user()->id,
        ];

        $conversations = $this->inboxService->getConversations($tenantId, $filters);

        return response()->json([
            'success' => true,
            'data' => $conversations->items(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Get single conversation details with messages, attachments, notes, tags
     */
    public function conversationShow(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        try {
            $conversation = $this->inboxService->getConversationDetails($tenantId, $id);

            return response()->json([
                'success' => true,
                'data' => $conversation,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Incremental delta synchronization endpoint for real-time fallback and catch-up
     */
    public function syncDelta(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $lastSyncTime = $request->query('last_sync');
        $activeConversationId = $request->query('conversation_id') ? (int) $request->query('conversation_id') : null;
        $sinceMessageId = $request->query('since_message_id') ? (int) $request->query('since_message_id') : null;

        $delta = $this->inboxService->getDeltaSync($tenantId, $lastSyncTime, $activeConversationId, $sinceMessageId);

        return response()->json([
            'success' => true,
            'data' => $delta,
        ]);
    }

    /**
     * Synchronize conversations from Meta Graph API
     */
    public function sync(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $accountId = $request->input('account_id') ? (int) $request->input('account_id') : null;

        try {
            $syncedCount = $this->inboxService->syncConversations($tenantId, $accountId);

            return response()->json([
                'success' => true,
                'message' => "Synced {$syncedCount} conversations and message threads from Meta.",
                'data' => [
                    'synced_count' => $syncedCount,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync conversations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send an outbound message or reply
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
            'attachment_url' => ['nullable', 'string'],
            'attachment_type' => ['nullable', 'string', 'in:image,video,audio,file'],
        ]);

        if (!$request->input('message') && !$request->input('attachment_url')) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide message text or an attachment.',
            ], 422);
        }

        try {
            $message = $this->inboxService->sendMessage(
                $tenantId,
                $id,
                $request->input('message'),
                $request->input('attachment_url'),
                $request->input('attachment_type', 'image'),
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully.',
                'data' => $message,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update conversation status (open, pending, resolved)
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'status' => ['required', 'string', 'in:open,pending,resolved'],
        ]);

        try {
            $conv = $this->inboxService->updateStatus($tenantId, $id, $request->input('status'));

            return response()->json([
                'success' => true,
                'message' => "Conversation marked as {$conv->status}.",
                'data' => $conv,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update conversation priority (urgent, high, normal, low)
     */
    public function updatePriority(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'priority' => ['required', 'string', 'in:urgent,high,normal,low'],
        ]);

        try {
            $conv = $this->inboxService->updatePriority($tenantId, $id, $request->input('priority'));

            return response()->json([
                'success' => true,
                'message' => "Priority updated to {$conv->priority}.",
                'data' => $conv,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Toggle read/unread state
     */
    public function toggleUnread(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $isUnread = $request->has('is_unread') ? $request->boolean('is_unread') : null;

        try {
            $conv = $this->inboxService->toggleUnread($tenantId, $id, $isUnread);

            return response()->json([
                'success' => true,
                'message' => $conv->is_unread ? 'Marked as unread.' : 'Marked as read.',
                'data' => $conv,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Assign conversation to a team member
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'user_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $conv = $this->inboxService->assignToUser(
                $tenantId,
                $id,
                $request->input('user_id'),
                $request->user()->id,
                $request->input('reason')
            );

            return response()->json([
                'success' => true,
                'message' => $conv->assignee ? "Assigned to {$conv->assignee->name}." : 'Conversation unassigned.',
                'data' => $conv,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Add internal note
     */
    public function addNote(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'content' => ['required', 'string', 'max:1500'],
        ]);

        try {
            $note = $this->inboxService->addNote($tenantId, $id, $request->user()->id, $request->input('content'));

            return response()->json([
                'success' => true,
                'message' => 'Internal note added.',
                'data' => $note,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete internal note
     */
    public function deleteNote(Request $request, int $noteId): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        try {
            $this->inboxService->deleteNote($tenantId, $noteId);

            return response()->json([
                'success' => true,
                'message' => 'Note deleted successfully.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Add tag to conversation
     */
    public function addTag(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'tag' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $tag = $this->inboxService->addTag(
                $tenantId,
                $id,
                $request->input('tag'),
                $request->input('color', '#10B981')
            );

            return response()->json([
                'success' => true,
                'data' => $tag,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Remove tag from conversation
     */
    public function removeTag(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'tag' => ['required', 'string'],
        ]);

        try {
            $this->inboxService->removeTag($tenantId, $id, $request->input('tag'));

            return response()->json([
                'success' => true,
                'message' => 'Tag removed.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Link an existing CRM contact
     */
    public function linkContact(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'contact_id' => ['required', 'integer'],
        ]);

        try {
            $conv = $this->inboxService->linkContact($tenantId, $id, (int) $request->input('contact_id'));

            return response()->json([
                'success' => true,
                'message' => 'Contact linked successfully.',
                'data' => $conv,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Create a new CRM contact from conversation
     */
    public function createContact(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $contact = $this->inboxService->createContactFromConversation(
                $tenantId,
                $id,
                $request->only(['name', 'email', 'phone', 'company'])
            );

            return response()->json([
                'success' => true,
                'message' => 'CRM Contact created and linked successfully.',
                'data' => $contact,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * List team members who can be assigned
     */
    public function assignableUsers(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $users = $this->inboxService->getAssignableUsers($tenantId);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Saved quick replies
     */
    public function quickReplies(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->inboxService->getQuickReplies(),
        ]);
    }
}
