<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{AuditLog, Contact, ContactCommunicationPreference, DuplicateReviewItem, TenantContactPolicy, TenantRole, User};
use App\Services\PhaseOne\{AccessService, AuditService};
use App\Services\Scraper\PhoneNormalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\Rule;

class WorkspaceGovernanceController extends Controller
{
    private function tenant(Request $request): int { return (int) $request->user()->tenant_id; }

    public function overview(Request $request, AccessService $access)
    {
        $tenantId = $this->tenant($request);
        $access->ensureRoles($tenantId);
        return response()->json([
            'roles' => TenantRole::where('tenant_id', $tenantId)->withCount('users')->orderBy('id')->get(),
            'users' => User::where('tenant_id', $tenantId)->with('tenantRole')->orderBy('name')->get(),
            'policy' => TenantContactPolicy::firstOrCreate(['tenant_id' => $tenantId]),
            'pending_duplicate_reviews' => DuplicateReviewItem::where('tenant_id', $tenantId)->where('status', 'pending')->count(),
            'permissions' => AccessService::CATALOG,
            'current_permissions' => array_values(array_filter(AccessService::CATALOG, fn ($permission) => $access->can($request->user(), $permission))),
            'current_user_id' => $request->user()->id,
            'owner_id' => $request->user()->tenant?->owner_id,
        ]);
    }

    public function saveRole(Request $request, AuditService $audit)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['id' => 'nullable|integer', 'name' => 'required|string|max:80', 'description' => 'nullable|string|max:500', 'permissions' => 'required|array', 'permissions.*' => Rule::in(AccessService::CATALOG)]);
        $role = isset($data['id']) ? TenantRole::where('tenant_id', $tenantId)->findOrFail($data['id']) : new TenantRole(['tenant_id' => $tenantId, 'slug' => str()->slug($data['name'])]);
        if ($role->exists && $role->slug === 'owner') return response()->json(['message' => 'The Owner role is protected and always has full access.'], 422);
        if (! $role->exists && TenantRole::where('tenant_id', $tenantId)->where('slug', str()->slug($data['name']))->exists()) return response()->json(['message' => 'A role with this name already exists.'], 422);
        $old = $role->exists ? $role->toArray() : [];
        $role->fill($data)->save();
        $audit->record($tenantId, 'access', $old ? 'role.updated' : 'role.created', $role, $old, $role->fresh()->toArray(), "Role {$role->name} saved");
        return response()->json(['message' => 'Role saved successfully', 'role' => $role]);
    }

    public function deleteRole(Request $request, int $role, AuditService $audit)
    {
        $record = TenantRole::where('tenant_id', $this->tenant($request))->findOrFail($role);
        if ($record->is_system || $record->users()->exists()) return response()->json(['message' => $record->is_system ? 'Built-in roles cannot be deleted.' : 'Move all users out of this role before deleting it.'], 422);
        $old = $record->toArray(); $record->delete();
        $audit->record($this->tenant($request), 'access', 'role.deleted', null, $old, [], "Role {$record->name} deleted");
        return response()->json(['message' => 'Role deleted successfully']);
    }

    public function saveUser(Request $request, AuditService $audit)
    {
        $tenantId = $this->tenant($request);
        $existing = $request->filled('id') ? User::where('tenant_id', $tenantId)->findOrFail((int) $request->id) : null;
        $data = $request->validate([
            'id' => 'nullable|integer', 'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($existing?->id)],
            'tenant_role_id' => ['required', Rule::exists('tenant_roles', 'id')->where('tenant_id', $tenantId)],
            'password' => [$existing ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'], 'status' => 'nullable|in:active,blocked',
        ]);
        $selectedRole = TenantRole::where('tenant_id', $tenantId)->findOrFail($data['tenant_role_id']);
        if ($selectedRole->slug === 'owner' && (int) $request->user()->tenant->owner_id !== (int) ($existing?->id)) {
            return response()->json(['message' => 'Ownership cannot be assigned from role management.'], 422);
        }
        $user = $existing ?: new User(['tenant_id' => $tenantId, 'status' => 'active']);
        if ($existing && (int) $request->user()->tenant->owner_id === (int) $existing->id) {
            $data['tenant_role_id'] = TenantRole::where('tenant_id', $tenantId)->where('slug', 'owner')->value('id'); $data['status'] = 'active';
        }
        $old = $user->exists ? $user->only(['name', 'email', 'tenant_role_id', 'status']) : [];
        if (! empty($data['password'])) $data['password'] = Hash::make($data['password']); else unset($data['password']);
        $user->fill($data)->save();
        if ($existing && ($old['status'] ?? null) !== ($data['status'] ?? $old['status'] ?? null)) $user->tokens()->delete();
        $audit->record($tenantId, 'access', $old ? 'user.updated' : 'user.created', $user, $old, $user->only(['name', 'email', 'tenant_role_id', 'status']), "Team member {$user->email} saved");
        return response()->json(['message' => $old ? 'Team member updated successfully' : 'User created successfully', 'user' => $user->load('tenantRole')], $old ? 200 : 201);
    }

    public function deleteUser(Request $request, int $user, AuditService $audit)
    {
        $record = User::where('tenant_id', $this->tenant($request))->findOrFail($user);
        if ((int) $request->user()->tenant->owner_id === $record->id || $record->id === $request->user()->id) return response()->json(['message' => 'The workspace owner and your own account cannot be deleted here.'], 422);
        $old = $record->only(['id', 'name', 'email', 'tenant_role_id']); $record->tokens()->delete(); $record->delete();
        $audit->record($this->tenant($request), 'access', 'user.deleted', null, $old, [], "Team member {$old['email']} deleted");
        return response()->json(['message' => 'User deleted successfully']);
    }

    public function audit(Request $request)
    {
        $query = AuditLog::where('tenant_id', $this->tenant($request))->latest('created_at');
        foreach (['module', 'action', 'user_id'] as $field) if ($request->filled($field)) $query->where($field, $request->input($field));
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('created_at', '<=', $request->date_to);
        if ($request->filled('search')) { $search = $request->search; $query->where(fn ($q) => $q->where('description', 'like', "%{$search}%")->orWhere('actor_label', 'like', "%{$search}%")->orWhere('action', 'like', "%{$search}%")); }
        return response()->json($query->paginate(min((int) $request->input('per_page', 25), 100)));
    }

    public function exportAudit(Request $request)
    {
        $rows = AuditLog::where('tenant_id', $this->tenant($request))->latest('created_at')->limit(10000)->get();
        return response()->streamDownload(function () use ($rows) { $file = fopen('php://output', 'w'); fputcsv($file, ['Date', 'Actor', 'Module', 'Action', 'Description']); foreach ($rows as $row) fputcsv($file, [$row->created_at, $row->actor_label, $row->module, $row->action, $row->description]); fclose($file); }, 'workspace-audit.csv', ['Content-Type' => 'text/csv']);
    }

    public function savePolicy(Request $request, AuditService $audit)
    {
        $tenantId = $this->tenant($request);
        $data = $request->validate(['allow_duplicates' => 'required|boolean', 'match_email' => 'required|boolean', 'match_phone' => 'required|boolean', 'ambiguous_action' => 'required|in:review,reject', 'email_verification_policy' => 'required|in:first_send,disabled', 'unsubscribe_footer_enabled' => 'required|boolean', 'unsubscribe_label' => 'required|string|max:120']);
        $policy = TenantContactPolicy::firstOrCreate(['tenant_id' => $tenantId]); $old = $policy->toArray(); $policy->update($data);
        $audit->record($tenantId, 'settings', 'communication_policy.updated', $policy, $old, $policy->fresh()->toArray(), 'Communication policy updated');
        return response()->json(['message' => 'Communication and matching policy saved', 'policy' => $policy]);
    }

    public function contactPreferences(Request $request, int $contact)
    {
        $record = Contact::where('tenant_id', $this->tenant($request))->findOrFail($contact);
        return response()->json(ContactCommunicationPreference::firstOrCreate(['tenant_id' => $record->tenant_id, 'contact_id' => $record->id]));
    }

    public function saveContactPreferences(Request $request, int $contact, AuditService $audit)
    {
        $record = Contact::where('tenant_id', $this->tenant($request))->findOrFail($contact);
        $data = $request->validate(['email_allowed' => 'required|boolean', 'whatsapp_allowed' => 'required|boolean', 'sms_allowed' => 'required|boolean', 'phone_allowed' => 'required|boolean', 'reason' => 'nullable|string|max:500']);
        $preference = ContactCommunicationPreference::firstOrCreate(['tenant_id' => $record->tenant_id, 'contact_id' => $record->id]); $old = $preference->toArray();
        $data['source'] = 'workspace'; $data['email_unsubscribed_at'] = $data['email_allowed'] ? null : ($preference->email_unsubscribed_at ?? now()); $data['whatsapp_opted_out_at'] = $data['whatsapp_allowed'] ? null : ($preference->whatsapp_opted_out_at ?? now());
        $preference->update($data);
        $audit->record($record->tenant_id, 'contacts', 'communication_preferences.updated', $record, $old, $preference->fresh()->toArray(), "Communication preferences changed for {$record->name}");
        return response()->json(['message' => 'Contact communication preferences saved', 'preferences' => $preference]);
    }

    public function duplicateReviews(Request $request)
    {
        $query = DuplicateReviewItem::where('tenant_id', $this->tenant($request)); if ($request->filled('status')) $query->where('status', $request->status);
        $page = $query->latest()->paginate(25); $candidateIds = collect($page->items())->flatMap->candidate_contact_ids->unique();
        $candidates = Contact::where('tenant_id', $this->tenant($request))->whereIn('id', $candidateIds)->get()->keyBy('id');
        $page->getCollection()->transform(function ($item) use ($candidates) { $item->candidates = collect($item->candidate_contact_ids)->map(fn ($id) => $candidates->get($id))->filter()->values(); return $item; });
        return response()->json($page);
    }

    public function resolveDuplicate(Request $request, int $review, AuditService $audit, PhoneNormalizationService $phones)
    {
        $tenantId = $this->tenant($request); $item = DuplicateReviewItem::where('tenant_id', $tenantId)->where('status', 'pending')->findOrFail($review);
        $data = $request->validate(['action' => 'required|in:use_existing,create_new,dismiss', 'contact_id' => 'nullable|integer']); $resolved = null;
        DB::transaction(function () use ($data, $item, $tenantId, $request, $phones, &$resolved) {
            if ($data['action'] === 'use_existing') { if (! in_array((int) $data['contact_id'], array_map('intval', $item->candidate_contact_ids), true)) abort(422, 'Select one of the matched contacts.'); $resolved = Contact::where('tenant_id', $tenantId)->findOrFail($data['contact_id']); }
            elseif ($data['action'] === 'create_new') { $incoming = $item->incoming_data; $name = trim((string) ($incoming['name'] ?? '')); $phone = trim((string) ($incoming['phone'] ?? '')); if ($name === '' || $phone === '') abort(422, 'The queued record does not contain a name and phone number.'); $resolved = Contact::create(['tenant_id' => $tenantId, 'name' => $name, 'phone' => $phone, 'phone_original' => $phone, 'phone_normalized' => $phones->normalizeE164($phone), 'email' => $incoming['email'] ?? null, 'email_normalized' => ! empty($incoming['email']) ? strtolower(trim($incoming['email'])) : null, 'company' => $incoming['company'] ?? null, 'status' => 'active', 'source' => 'Duplicate Review']); }
            $item->update(['status' => $data['action'] === 'dismiss' ? 'dismissed' : 'resolved', 'resolved_by' => $request->user()->id, 'resolved_contact_id' => $resolved?->id]);
        });
        $audit->record($tenantId, 'contacts', 'duplicate_review.resolved', $item, [], ['action' => $data['action'], 'resolved_contact_id' => $resolved?->id], 'Duplicate review resolved');
        return response()->json(['message' => 'Duplicate review resolved successfully', 'review' => $item->fresh(), 'contact' => $resolved]);
    }
}
