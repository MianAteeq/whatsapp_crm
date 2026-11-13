<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\AccessControl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        return response()->json([
            'roles' => Role::withCount('users')->orderByDesc('is_system')->orderBy('name')->get(),
            'permissions' => AccessControl::permissions(),
        ]);
    }

    public function options(Request $request)
    {
        return response()->json(['roles' => Role::where('key', '!=', 'superadmin')->orderBy('name')->get()]);
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(AccessControl::keys())],
        ]);

        foreach ($data['permissions'] as $permission) {
            if (str_ends_with($permission, '.manage')) {
                $data['permissions'][] = str_replace('.manage', '.view', $permission);
            }
        }
        $data['permissions'] = array_values(array_unique($data['permissions']));

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        // Keep the immutable key separate from the editable display name.
        $data['key'] = 'custom_'.Str::uuid();
        $role = Role::create($data);

        return response()->json(['message' => 'Role created successfully.', 'role' => $role], 201);
    }

    public function update(Request $request, Role $role)
    {
        abort_if(in_array($role->key, ['superadmin', 'admin'], true), 422, 'Administrator roles are protected.');
        $role->update($this->validated($request, $role));

        return response()->json(['message' => 'Role updated successfully.', 'role' => $role]);
    }

    public function destroy(Role $role)
    {
        abort_if($role->is_system, 422, 'Built-in roles cannot be deleted.');
        abort_if($role->users()->exists(), 422, 'Reassign users before deleting this role.');
        $role->delete();

        return response()->json(['message' => 'Role deleted successfully.']);
    }
}
