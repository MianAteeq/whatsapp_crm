<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserAccessController extends Controller
{
    private function isGlobal(Request $request): bool
    {
        return $request->is('api/admin/*');
    }

    private function query(Request $request)
    {
        $query = User::with(['tenant:id,name,owner_id', 'accessRole']);
        if (! $this->isGlobal($request)) {
            abort_unless($request->user()->tenant_id, 403, 'A workspace is required.');
            $query->where('tenant_id', $request->user()->tenant_id)->where('role', '!=', 'superadmin');
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->query($request);
        if ($request->filled('search')) {
            $query->where(function ($query) use ($request) {
                $query->where('name', 'like', '%'.$request->string('search').'%')
                    ->orWhere('email', 'like', '%'.$request->string('search').'%');
            });
        }
        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        return response()->json(['success' => true, 'data' => $query->latest('id')->paginate(20)]);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $global = $this->isGlobal($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [$user ? 'sometimes' : 'required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:128'],
            'role' => ['required', 'string', Rule::exists('roles', 'key')],
            'status' => ['required', Rule::in(['active', 'blocked'])],
            'tenant_id' => [$global ? 'nullable' : 'prohibited', 'integer', Rule::exists('tenants', 'id')],
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if (! $global) {
            $role = Role::where('key', $data['role'])->firstOrFail();
            abort_if($role->key === 'superadmin', 403, 'Only a Super Admin can assign this role.');
            abort_if(array_diff($role->permissions, $request->user()->permissions), 403, 'You cannot assign permissions you do not have.');
            $data['tenant_id'] = $request->user()->tenant_id;
        }
        if ($data['role'] !== 'superadmin') {
            $tenantId = array_key_exists('tenant_id', $data) ? $data['tenant_id'] : $user?->tenant_id;
            abort_unless($tenantId, 422, 'Select a workspace for this user.');
        }

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if (! $this->isGlobal($request)) {
            abort_unless($request->user()->tenant_id, 403, 'A workspace is required.');
        }
        $user = User::create($data);

        return response()->json(['success' => true, 'message' => 'User created successfully.', 'user' => $user->load(['tenant', 'accessRole'])], 201);
    }

    public function update(Request $request, int $id)
    {
        $user = $this->query($request)->findOrFail($id);
        $data = $this->validated($request, $user);
        $this->protectAccount($request, $user, $data);
        $revoke = isset($data['password']) || $data['status'] === 'blocked';
        $user->update($data);
        if ($revoke) {
            $user->tokens()->delete();
        }

        return response()->json(['success' => true, 'message' => 'User updated successfully.', 'user' => $user->fresh(['tenant', 'accessRole'])]);
    }

    private function protectAccount(Request $request, User $user, array $data = [], bool $deleting = false): void
    {
        if ($user->id === $request->user()->id) {
            abort_if($deleting || ($data['status'] ?? 'active') === 'blocked' || ($data['role'] ?? $user->role) !== $user->role || (array_key_exists('tenant_id', $data) && $data['tenant_id'] !== $user->tenant_id), 422, 'You cannot delete, block, move, or change the role of your own account.');
        }
        if (Tenant::where('owner_id', $user->id)->exists()) {
            abort_if($deleting || ($data['status'] ?? 'active') === 'blocked' || ($data['role'] ?? $user->role) !== $user->role || (array_key_exists('tenant_id', $data) && $data['tenant_id'] !== $user->tenant_id), 422, 'Workspace owner access is protected.');
        }
        if (! $this->isGlobal($request)) {
            abort_if(array_diff($user->permissions, $request->user()->permissions), 403, 'You cannot modify a user with greater permissions.');
        }
        if ($user->role === 'superadmin' && ($deleting || ($data['status'] ?? $user->status) === 'blocked' || ($data['role'] ?? $user->role) !== 'superadmin')) {
            abort_unless(User::where('role', 'superadmin')->where('status', 'active')->whereKeyNot($user->id)->exists(), 422, 'At least one active Super Admin is required.');
        }
    }

    public function destroy(Request $request, int $id)
    {
        $user = $this->query($request)->findOrFail($id);
        $this->protectAccount($request, $user, deleting: true);
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['success' => true, 'message' => 'User deleted successfully.']);
    }

    public function workspaces()
    {
        return response()->json(['workspaces' => Tenant::orderBy('name')->get(['id', 'name'])]);
    }
}
