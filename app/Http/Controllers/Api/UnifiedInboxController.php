<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Contact, Conversation, CrmTask, InboxConversationState, InboxNote, InboxSlaEvent, InboxSlaPolicy, InboxSlaState, InboxUserState, Message, ReplySnippet, SalesActivity, SocialConversation, SocialMessage, User};
use App\Services\InboxSlaService;
use App\Services\PhaseOne\{AccessService, AuditService};
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnifiedInboxController extends Controller
{
    private const CHANNELS = ['whatsapp', 'facebook', 'instagram'];

    public function bootstrap(Request $request, AccessService $access)
    {
        $tenantId = (int) $request->user()->tenant_id;
        return response()->json([
            'current_user' => $request->user()->only(['id', 'name', 'email', 'avatar']),
            'users' => User::where('tenant_id', $tenantId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email', 'avatar']),
            'channels' => self::CHANNELS,
            'permissions' => [
                'manage_inbox' => $access->can($request->user(), 'inbox.manage'),
                'send_messages' => $access->can($request->user(), 'messages.send'),
                'view_contacts' => $access->can($request->user(), 'contacts.view'),
                'manage_snippets' => $access->can($request->user(), 'snippets.manage'),
                'manage_sla' => $access->can($request->user(), 'sla.manage'),
                'manage_tasks' => $access->can($request->user(), 'tasks.manage'),
            ],
            'sla_policies' => InboxSlaPolicy::where('tenant_id', $tenantId)->orderBy('channel')->get(),
        ]);
    }

    public function index(Request $request, InboxSlaService $sla)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;
        $query = $this->baseQuery($tenantId, $userId);
        if ($request->filled('search')) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->string('search')->toString())) . '%';
            $query->where(fn ($q) => $q->where('u.display_name', 'like', $term)->orWhere('u.external_id', 'like', $term)->orWhere('u.last_message', 'like', $term));
        }
        if ($request->filled('channel')) $query->where('u.channel', $request->string('channel'));
        if ($request->filled('status')) $query->whereRaw("COALESCE(state.status, u.source_status) = ?", [$request->string('status')]);

        $countsQuery = clone $query;
        $counts = [
            'team' => (clone $countsQuery)->count(),
            'mine' => (clone $countsQuery)->whereRaw('COALESCE(state.assigned_to_user_id, u.source_assignee_id) = ?', [$userId])->count(),
            'unread' => (clone $countsQuery)->whereRaw('COALESCE(user_state.unread_override, u.unread_count > 0) = 1')->count(),
            'starred' => (clone $countsQuery)->where('user_state.is_starred', true)->count(),
            'following' => (clone $countsQuery)->where('user_state.is_following', true)->count(),
            'recent' => (clone $countsQuery)->whereNotNull('user_state.last_viewed_at')->count(),
            'unassigned' => (clone $countsQuery)->whereRaw('COALESCE(state.assigned_to_user_id, u.source_assignee_id) IS NULL')->count(),
            'others' => (clone $countsQuery)->whereRaw('COALESCE(state.assigned_to_user_id, u.source_assignee_id) IS NOT NULL AND COALESCE(state.assigned_to_user_id, u.source_assignee_id) <> ?', [$userId])->count(),
            'sla_overdue' => (clone $countsQuery)->where('sla.status', 'overdue')->count(),
        ];

        $view = $request->input('view', 'team');
        if ($view === 'mine') $query->whereRaw('COALESCE(state.assigned_to_user_id, u.source_assignee_id) = ?', [$userId]);
        elseif ($view === 'unread') $query->whereRaw('COALESCE(user_state.unread_override, u.unread_count > 0) = 1');
        elseif ($view === 'starred') $query->where('user_state.is_starred', true);
        elseif ($view === 'following') $query->where('user_state.is_following', true);
        elseif ($view === 'recent') $query->whereNotNull('user_state.last_viewed_at');
        elseif ($view === 'unassigned') $query->whereRaw('COALESCE(state.assigned_to_user_id, u.source_assignee_id) IS NULL');
        elseif ($view === 'others') $query->whereRaw('COALESCE(state.assigned_to_user_id, u.source_assignee_id) IS NOT NULL AND COALESCE(state.assigned_to_user_id, u.source_assignee_id) <> ?', [$userId]);
        elseif ($view === 'sla_overdue') $query->where('sla.status', 'overdue');

        $view === 'recent' ? $query->orderByDesc('user_state.last_viewed_at') : $query->orderByDesc('u.last_message_at');
        $paginator = $query->paginate(min(max((int) $request->input('per_page', 30), 10), 100));

        $items = collect($paginator->items())->map(function ($row) use ($tenantId, $sla) {
            $state = $sla->reconcile($tenantId, $row->channel, (int) $row->source_id, $row->status);
            $row->sla_status = $state?->status;
            $row->sla_due_at = $state?->due_at;
            $row->sla_started_at = $state?->started_at;
            $row->is_unread = $row->unread_override !== null ? (bool) $row->unread_override : ((int) $row->unread_count > 0);
            $row->is_starred = (bool) $row->is_starred;
            $row->is_following = (bool) $row->is_following;
            $row->identity_status = $row->contact_id ? 'linked' : 'unresolved';
            return $row;
        });

        return response()->json(['data' => $items, 'meta' => $this->paginationMeta($paginator), 'counts' => $counts]);
    }

    public function show(Request $request, string $channel, int $sourceId, InboxSlaService $sla, AccessService $access)
    {
        $this->validateSource($request, $channel, $sourceId);
        $tenantId = (int) $request->user()->tenant_id;
        $conversation = $this->baseQuery($tenantId, (int) $request->user()->id)->where('u.channel', $channel)->where('u.source_id', $sourceId)->firstOrFail();
        $messages = $this->messages($tenantId, $channel, $sourceId);
        $notes = InboxNote::where('tenant_id', $tenantId)->where('channel', $channel)->where('source_id', $sourceId)->with('user:id,name,avatar')->oldest()->get();
        $userState = InboxUserState::firstOrCreate(['tenant_id' => $tenantId, 'user_id' => $request->user()->id, 'channel' => $channel, 'source_id' => $sourceId]);
        $userState->update(['last_viewed_at' => now(), 'unread_override' => false]);
        $this->setSourceReadState($tenantId, $channel, $sourceId, false);
        $slaState = $sla->reconcile($tenantId, $channel, $sourceId, $conversation->status);

        $context = null;
        if ($conversation->contact_id && $access->can($request->user(), 'contacts.view')) {
            $contact = Contact::where('tenant_id', $tenantId)->with(['business.owner:id,name', 'tags:id,name,color', 'opportunities' => fn ($q) => $q->with(['stage:id,name,color', 'owner:id,name'])->where('status', 'open')->latest()->limit(5), 'tasks' => fn ($q) => $q->with('owner:id,name')->whereNotIn('status', ['completed', 'cancelled'])->orderBy('due_at')->limit(6), 'appointments' => fn ($q) => $q->with('owner:id,name')->whereIn('status', ['scheduled', 'confirmed'])->orderBy('starts_at')->limit(5)])->find($conversation->contact_id);
            if ($contact) $context = ['contact' => $contact, 'activities' => SalesActivity::where('tenant_id', $tenantId)->where('contact_id', $contact->id)->with('user:id,name')->latest('occurred_at')->limit(10)->get()];
        }

        $conversation->is_starred = $userState->is_starred;
        $conversation->is_following = $userState->is_following;
        $conversation->is_unread = false;
        $conversation->identity_status = $conversation->contact_id ? 'linked' : 'unresolved';
        $conversation->sla_status = $slaState?->status;
        $conversation->sla_due_at = $slaState?->due_at;
        if ($channel === 'whatsapp') {
            $lastInboundAt = Message::where('tenant_id', $tenantId)->where('conversation_id', $sourceId)->where('direction', 'incoming')->latest('created_at')->value('created_at');
            $conversation->can_send_freeform = $lastInboundAt && now()->diffInHours($lastInboundAt, true) <= 24;
            $conversation->send_restriction = $conversation->can_send_freeform ? null : 'The WhatsApp customer-service window is closed. Send an approved WhatsApp template to reopen messaging.';
        } else {
            $conversation->can_send_freeform = true;
            $conversation->send_restriction = null;
        }
        return response()->json(['conversation' => $conversation, 'messages' => $messages, 'notes' => $notes, 'context' => $context]);
    }

    public function updateState(Request $request, string $channel, int $sourceId, AccessService $access, AuditService $audit, InboxSlaService $sla)
    {
        $this->validateSource($request, $channel, $sourceId);
        $tenantId = (int) $request->user()->tenant_id;
        $data = $request->validate([
            'assigned_to_user_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'status' => ['sometimes', Rule::in(['open', 'pending', 'resolved'])],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'is_starred' => 'sometimes|boolean', 'is_following' => 'sometimes|boolean', 'is_unread' => 'sometimes|boolean',
        ]);
        $state = InboxConversationState::firstOrCreate(['tenant_id' => $tenantId, 'channel' => $channel, 'source_id' => $sourceId]);
        $old = $state->toArray();
        $shared = array_intersect_key($data, array_flip(['assigned_to_user_id', 'status', 'priority']));
        if (array_key_exists('assigned_to_user_id', $shared)) {
            $target = $shared['assigned_to_user_id'];
            $isSelfAssignment = $target !== null && (int) $target === (int) $request->user()->id;
            $isOwnUnassign = $target === null && (int) $state->assigned_to_user_id === (int) $request->user()->id;
            if (!$isSelfAssignment && !$isOwnUnassign && !$access->can($request->user(), 'inbox.manage')) abort(403, 'You cannot assign this conversation to another team member.');
        }
        if ($shared) $state->update($shared);
        $personal = array_intersect_key($data, array_flip(['is_starred', 'is_following']));
        if (array_key_exists('is_unread', $data)) $personal['unread_override'] = $data['is_unread'];
        $userState = InboxUserState::firstOrCreate(['tenant_id' => $tenantId, 'user_id' => $request->user()->id, 'channel' => $channel, 'source_id' => $sourceId]);
        if ($personal) $userState->update($personal);
        if (array_key_exists('is_unread', $data)) $this->setSourceReadState($tenantId, $channel, $sourceId, $data['is_unread']);
        $audit->record($tenantId, 'inbox', 'conversation.updated', $state, $old, $state->fresh()->toArray(), "{$channel}:{$sourceId}");
        $slaState = $sla->reconcile($tenantId, $channel, $sourceId, $state->status);
        return response()->json(['message' => 'Conversation updated.', 'state' => $state->fresh('assignee:id,name,email,avatar'), 'user_state' => $userState->fresh(), 'sla' => $slaState]);
    }

    public function addNote(Request $request, string $channel, int $sourceId, AuditService $audit)
    {
        $this->validateSource($request, $channel, $sourceId);
        $data = $request->validate(['content' => 'required|string|max:3000']);
        $note = InboxNote::create(['tenant_id' => $request->user()->tenant_id, 'user_id' => $request->user()->id, 'channel' => $channel, 'source_id' => $sourceId, 'content' => trim($data['content'])]);
        $audit->record((int) $request->user()->tenant_id, 'inbox', 'note.created', $note, [], [], "{$channel}:{$sourceId}");
        return response()->json(['message' => 'Internal note added.', 'note' => $note->load('user:id,name,avatar')], 201);
    }

    public function snippets(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $query = ReplySnippet::where('tenant_id', $tenantId)->where('is_active', true)->where(fn ($q) => $q->where('visibility', 'shared')->orWhere('created_by_user_id', $request->user()->id));
        if ($request->filled('search')) { $term = '%' . $request->string('search') . '%'; $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('body', 'like', $term)->orWhere('category', 'like', $term)); }
        return response()->json(['data' => $query->with('creator:id,name')->orderBy('category')->orderBy('title')->get()]);
    }

    public function saveSnippet(Request $request, AccessService $access, AuditService $audit)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $data = $request->validate(['id' => 'nullable|integer', 'title' => 'required|string|max:120', 'category' => 'nullable|string|max:80', 'body' => 'required|string|max:5000', 'visibility' => ['required', Rule::in(['shared', 'private'])]]);
        if ($data['visibility'] === 'shared' && !$access->can($request->user(), 'snippets.manage')) abort(403, 'Shared snippets require snippet management permission.');
        $snippet = !empty($data['id']) ? ReplySnippet::where('tenant_id', $tenantId)->findOrFail($data['id']) : new ReplySnippet(['tenant_id' => $tenantId, 'created_by_user_id' => $request->user()->id]);
        if ($snippet->exists && (int) $snippet->created_by_user_id !== (int) $request->user()->id && !$access->can($request->user(), 'snippets.manage')) abort(403);
        $old = $snippet->exists ? $snippet->toArray() : [];
        $snippet->fill($data)->save();
        $audit->record($tenantId, 'inbox', $old ? 'snippet.updated' : 'snippet.created', $snippet, $old, $snippet->toArray(), $snippet->title);
        return response()->json(['message' => 'Reply snippet saved.', 'snippet' => $snippet]);
    }

    public function deleteSnippet(Request $request, int $snippet, AccessService $access, AuditService $audit)
    {
        $record = ReplySnippet::where('tenant_id', $request->user()->tenant_id)->findOrFail($snippet);
        if ((int) $record->created_by_user_id !== (int) $request->user()->id && !$access->can($request->user(), 'snippets.manage')) abort(403);
        $audit->record((int) $request->user()->tenant_id, 'inbox', 'snippet.deleted', $record, $record->toArray(), [], $record->title);
        $record->delete();
        return response()->json(['message' => 'Reply snippet deleted.']);
    }

    public function renderSnippet(Request $request, string $channel, int $sourceId)
    {
        $this->validateSource($request, $channel, $sourceId);
        $data = $request->validate(['snippet_id' => 'required|integer']);
        $snippet = ReplySnippet::where('tenant_id', $request->user()->tenant_id)->where(fn ($q) => $q->where('visibility', 'shared')->orWhere('created_by_user_id', $request->user()->id))->findOrFail($data['snippet_id']);
        $conversation = $this->baseQuery((int) $request->user()->tenant_id, (int) $request->user()->id)->where('u.channel', $channel)->where('u.source_id', $sourceId)->firstOrFail();
        $contact = $conversation->contact_id ? Contact::where('tenant_id', $request->user()->tenant_id)->with('business')->find($conversation->contact_id) : null;
        $values = ['contact.first_name' => $contact?->first_name ?: strtok((string) ($contact?->name ?: $conversation->display_name), ' '), 'contact.company' => $contact?->business?->business_name ?: $contact?->company, 'agent.name' => $request->user()->name];
        $warnings = [];
        $rendered = preg_replace_callback('/{{\s*([a-zA-Z0-9_.]+)\s*}}/', function ($match) use ($values, &$warnings) {
            if (!array_key_exists($match[1], $values)) { $warnings[] = "Unsupported variable: {$match[1]}"; return $match[0]; }
            if (blank($values[$match[1]])) { $warnings[] = "Missing value: {$match[1]}"; return $match[0]; }
            return e((string) $values[$match[1]]);
        }, $snippet->body);
        return response()->json(['body' => html_entity_decode($rendered, ENT_QUOTES, 'UTF-8'), 'warnings' => array_values(array_unique($warnings))]);
    }

    public function saveSlaPolicy(Request $request, AuditService $audit, InboxSlaService $sla)
    {
        $data = $request->validate(['channel' => ['required', Rule::in(self::CHANNELS)], 'response_minutes' => 'required|integer|min:1|max:10080', 'warning_minutes' => 'required|integer|min:0|max:10080', 'is_active' => 'required|boolean', 'ai_reply_stops_clock' => 'sometimes|boolean', 'workflow_reply_stops_clock' => 'sometimes|boolean']);
        if ($data['warning_minutes'] > $data['response_minutes']) return response()->json(['message' => 'Warning time cannot exceed the response threshold.'], 422);
        $tenantId = (int) $request->user()->tenant_id;
        $policy = InboxSlaPolicy::firstOrNew(['tenant_id' => $tenantId, 'channel' => $data['channel']]);
        $old = $policy->exists ? $policy->toArray() : [];
        $policy->fill($data + ['continuous_clock' => true, 'human_reply_stops_clock' => true])->save();
        $sla->reconcileTenant($tenantId);
        $audit->record($tenantId, 'inbox', 'sla_policy.saved', $policy, $old, $policy->toArray(), $policy->channel);
        return response()->json(['message' => 'SLA policy saved.', 'policy' => $policy]);
    }

    public function slaReport(Request $request)
    {
        $query = InboxSlaState::where('tenant_id', $request->user()->tenant_id);
        if ($request->filled('channel')) $query->where('channel', $request->string('channel'));
        $counts = (clone $query)->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');
        $average = (clone $query)->whereNotNull('responded_at')->whereNotNull('started_at')->get()->avg(fn ($state) => $state->started_at->diffInMinutes($state->responded_at));
        return response()->json(['counts' => $counts, 'average_response_minutes' => $average ? round($average, 1) : null, 'recent_events' => InboxSlaEvent::where('tenant_id', $request->user()->tenant_id)->latest('created_at')->limit(50)->get()]);
    }

    public function createLinkedTask(Request $request, string $channel, int $sourceId, AuditService $audit)
    {
        $this->validateSource($request, $channel, $sourceId);
        $tenantId = (int) $request->user()->tenant_id;
        $conversation = $this->baseQuery($tenantId, (int) $request->user()->id)->where('u.channel', $channel)->where('u.source_id', $sourceId)->firstOrFail();
        $data = $request->validate(['title' => 'required|string|max:191', 'description' => 'nullable|string|max:3000', 'owner_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tenantId)], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'due_at' => 'nullable|date']);
        $task = CrmTask::create($data + ['tenant_id' => $tenantId, 'contact_id' => $conversation->contact_id, 'conversation_channel' => $channel, 'conversation_source_id' => $sourceId, 'status' => 'pending']);
        $audit->record($tenantId, 'tasks', 'task.created_from_inbox', $task, [], $task->toArray(), $task->title);
        return response()->json(['message' => 'Follow-up task created.', 'task' => $task->load('owner:id,name')], 201);
    }

    private function baseQuery(int $tenantId, int $userId)
    {
        $whatsapp = DB::table('conversations as c')->leftJoin('contacts as ct', fn ($join) => $join->on('ct.id', '=', 'c.contact_id')->on('ct.tenant_id', '=', 'c.tenant_id'))->where('c.tenant_id', $tenantId)->selectRaw("c.tenant_id, 'whatsapp' channel, c.id source_id, c.contact_id, COALESCE(ct.name, c.wa_id) display_name, c.wa_id external_id, NULL avatar_url, c.last_message, c.last_message_at, c.unread_count, 'open' source_status, 'normal' source_priority, NULL source_assignee_id");
        $social = DB::table('social_conversations as sc')->where('sc.tenant_id', $tenantId)->whereNull('sc.deleted_at')->selectRaw('sc.tenant_id, sc.platform channel, sc.id source_id, sc.contact_id, sc.participant_name display_name, COALESCE(sc.participant_username, sc.participant_id) external_id, sc.participant_avatar_url avatar_url, sc.last_message_preview last_message, sc.last_message_at, sc.unread_count, sc.status source_status, sc.priority source_priority, sc.assigned_to_user_id source_assignee_id');
        $union = $whatsapp->unionAll($social);
        return DB::query()->fromSub($union, 'u')
            ->leftJoin('inbox_conversation_states as state', fn ($join) => $join->on('state.tenant_id', '=', 'u.tenant_id')->on('state.channel', '=', 'u.channel')->on('state.source_id', '=', 'u.source_id'))
            ->leftJoin('inbox_user_states as user_state', fn ($join) => $join->on('user_state.tenant_id', '=', 'u.tenant_id')->on('user_state.channel', '=', 'u.channel')->on('user_state.source_id', '=', 'u.source_id')->where('user_state.user_id', $userId))
            ->leftJoin('inbox_sla_states as sla', fn ($join) => $join->on('sla.tenant_id', '=', 'u.tenant_id')->on('sla.channel', '=', 'u.channel')->on('sla.source_id', '=', 'u.source_id'))
            ->leftJoin('users as assignee', function ($join) { $join->on('assignee.tenant_id', '=', 'u.tenant_id')->whereRaw('assignee.id = COALESCE(state.assigned_to_user_id, u.source_assignee_id)'); })
            ->selectRaw('u.*, COALESCE(state.status, u.source_status) status, COALESCE(state.priority, u.source_priority) priority, COALESCE(state.assigned_to_user_id, u.source_assignee_id) assigned_to_user_id, assignee.name assigned_to_name, user_state.is_starred, user_state.is_following, user_state.unread_override, user_state.last_viewed_at, sla.status sla_status, sla.due_at sla_due_at, sla.started_at sla_started_at');
    }

    private function messages(int $tenantId, string $channel, int $sourceId): array
    {
        if ($channel === 'whatsapp') return Message::where('tenant_id', $tenantId)->where('conversation_id', $sourceId)->orderBy('created_at')->limit(200)->get()->map(fn ($m) => ['id' => $m->id, 'provider_id' => $m->message_id, 'direction' => $m->direction === 'incoming' ? 'inbound' : 'outbound', 'body' => $m->message, 'type' => $m->type, 'status' => $m->status, 'timestamp' => $m->created_at, 'attachment' => $m->media_url ? ['url' => $m->media_url, 'mime_type' => $m->mime_type, 'file_name' => $m->file_name] : null])->all();
        return SocialMessage::where('tenant_id', $tenantId)->where('social_conversation_id', $sourceId)->with('attachments')->orderBy('sent_at')->limit(200)->get()->map(fn ($m) => ['id' => $m->id, 'provider_id' => $m->platform_message_id, 'direction' => $m->sender_type === 'customer' ? 'inbound' : 'outbound', 'body' => $m->message_text, 'type' => $m->attachments->first()?->attachment_type ?: 'text', 'status' => $m->status, 'timestamp' => $m->sent_at, 'attachment' => $m->attachments->first() ? ['url' => $m->attachments->first()->url, 'mime_type' => $m->attachments->first()->mime_type, 'file_name' => $m->attachments->first()->file_name] : null])->all();
    }

    private function validateSource(Request $request, string $channel, int $sourceId): void
    {
        abort_unless(in_array($channel, self::CHANNELS, true), 404);
        $tenantId = (int) $request->user()->tenant_id;
        $exists = $channel === 'whatsapp' ? Conversation::where('tenant_id', $tenantId)->whereKey($sourceId)->exists() : SocialConversation::where('tenant_id', $tenantId)->where('platform', $channel)->whereKey($sourceId)->exists();
        abort_unless($exists, 404);
    }

    private function setSourceReadState(int $tenantId, string $channel, int $sourceId, bool $unread): void
    {
        if ($channel === 'whatsapp') Conversation::where('tenant_id', $tenantId)->whereKey($sourceId)->update(['unread_count' => $unread ? 1 : 0]);
        else SocialConversation::where('tenant_id', $tenantId)->whereKey($sourceId)->update(['is_unread' => $unread, 'unread_count' => $unread ? 1 : 0]);
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()];
    }
}

