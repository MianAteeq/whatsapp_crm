<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    private Tenant $workspace;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        // Never run these acceptance tests against the configured application DB.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        app('db')->purge('sqlite');
        foreach ([
            '2026_04_04_174815_create_tenants_table.php',
            '2026_06_06_000000_create_users_table.php',
            '2026_06_22_182000_add_status_to_users_table.php',
            '2026_05_04_174714_create_personal_access_tokens_table.php',
            '2026_11_13_000000_create_roles_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->workspace = Tenant::create(['name' => 'Workspace A']);
        $this->superadmin = $this->user('superadmin');
        Route::middleware(['api', 'auth:sanctum', 'access'])->get('api/contacts/access-test/read', fn () => response()->json(['allowed' => true]));
        Route::middleware(['api', 'auth:sanctum', 'access'])->post('api/contacts/access-test/write', fn () => response()->json(['allowed' => true]));
    }

    private function user(string $role = 'admin', ?Tenant $tenant = null): User
    {
        return User::create([
            'name' => 'Test User', 'email' => uniqid().'@example.test',
            'password' => 'TestPassword123!', 'role' => $role, 'status' => 'active',
            'tenant_id' => ($tenant ?? $this->workspace)->id,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['name' => 'New Teammate', 'email' => 'new@example.test', 'password' => 'StrongPassword123!', 'role' => 'agent', 'status' => 'active'], $extra);
    }

    public function test_authentication_and_platform_boundaries_are_enforced(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/admin/roles')->assertForbidden();
        $this->getJson('/api/admin/email/sending-domains')->assertForbidden();
        $this->postJson('/api/admin/roles', ['name' => 'Elevated', 'permissions' => []])->assertForbidden();
    }

    public function test_superadmin_can_create_a_user_and_password_is_hashed(): void
    {
        Sanctum::actingAs($this->superadmin);
        $response = $this->postJson('/api/admin/users', $this->payload(['tenant_id' => $this->workspace->id]));
        $response->assertCreated()->assertJsonPath('user.role', 'agent')->assertJsonMissingPath('user.password');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('StrongPassword123!', $user->password));
        $this->assertContains('inbox.manage', $response->json('user.permissions'));
        $this->getJson('/api/admin/users?role=agent&search=Teammate')->assertOk()->assertJsonPath('data.total', 1);
        $this->postJson('/api/admin/users', $this->payload(['tenant_id' => $this->workspace->id]))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_invalid_roles_passwords_and_workspace_are_rejected(): void
    {
        Sanctum::actingAs($this->superadmin);
        $this->postJson('/api/admin/users', $this->payload(['role' => 'invented', 'password' => 'short']))->assertUnprocessable()->assertJsonValidationErrors(['role', 'password']);
        $this->postJson('/api/admin/users', $this->payload())->assertUnprocessable();
        $user = $this->user('agent');
        $this->putJson('/api/admin/users/'.$user->id, $this->payload(['email' => $user->email, 'tenant_id' => null]))->assertUnprocessable();
    }

    public function test_role_lifecycle_and_assigned_role_deletion_protection(): void
    {
        Sanctum::actingAs($this->superadmin);
        $response = $this->postJson('/api/admin/roles', ['name' => 'Contact Manager', 'description' => 'Manage contacts', 'permissions' => ['contacts.manage']])->assertCreated();
        $role = $response->json('role');
        $this->assertContains('contacts.view', $role['permissions']);
        $assigned = $this->user($role['key']);
        $this->deleteJson('/api/admin/roles/'.$role['id'])->assertUnprocessable();
        $this->putJson('/api/admin/roles/'.$role['id'], ['name' => 'Contact Reader', 'permissions' => ['contacts.view']])->assertOk();
        $this->assertSame(['contacts.view'], $assigned->fresh()->permissions);
        $assigned->delete();
        $this->deleteJson('/api/admin/roles/'.$role['id'])->assertOk();
        $this->getJson('/api/admin/roles')->assertOk()->assertJsonCount(4, 'roles');
    }

    public function test_system_roles_and_unknown_permissions_are_protected(): void
    {
        Sanctum::actingAs($this->superadmin);
        $admin = Role::where('key', 'admin')->firstOrFail();
        $this->putJson('/api/admin/roles/'.$admin->id, ['name' => 'Reduced Admin', 'permissions' => []])->assertUnprocessable();
        $this->deleteJson('/api/admin/roles/'.$admin->id)->assertUnprocessable();
        $this->postJson('/api/admin/roles', ['name' => 'Invalid', 'permissions' => ['platform.takeover']])->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
    }

    public function test_team_users_are_scoped_and_tenant_header_cannot_override_scope(): void
    {
        $other = Tenant::create(['name' => 'Workspace B']);
        $outsider = $this->user('agent', $other);
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/team/users?search='.$outsider->email, ['X-Tenant-Id' => $other->id])->assertOk()->assertJsonPath('data.total', 0);
        $this->putJson('/api/team/users/'.$outsider->id, $this->payload(['email' => $outsider->email]))->assertNotFound();
        $this->deleteJson('/api/team/users/'.$outsider->id)->assertNotFound();
        $this->postJson('/api/team/users', $this->payload(['tenant_id' => $other->id]))->assertUnprocessable();
        $this->postJson('/api/team/users', $this->payload())->assertCreated()->assertJsonPath('user.tenant_id', $this->workspace->id);
    }

    public function test_team_managers_cannot_escalate_or_edit_more_privileged_users(): void
    {
        $role = Role::create(['key' => 'team_manager', 'name' => 'Team Manager', 'permissions' => ['users.view', 'users.manage']]);
        $admin = $this->user('admin');
        Sanctum::actingAs($this->user($role->key));
        $this->postJson('/api/team/users', $this->payload(['role' => 'superadmin']))->assertForbidden();
        $this->postJson('/api/team/users', $this->payload(['role' => 'admin']))->assertForbidden();
        $this->putJson('/api/team/users/'.$admin->id, $this->payload(['role' => $role->key, 'email' => $admin->email]))->assertForbidden();
        $this->deleteJson('/api/team/users/'.$admin->id)->assertForbidden();
    }

    public function test_read_and_manage_permissions_apply_and_updates_take_effect(): void
    {
        $role = Role::create(['key' => 'reader', 'name' => 'Reader', 'permissions' => ['contacts.view']]);
        $user = $this->user('reader');
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/contacts/access-test/read')->assertOk();
        $this->postJson('/api/contacts/access-test/write')->assertForbidden();
        $this->postJson('/api/messages/send', [])->assertForbidden();
        $role->update(['permissions' => []]);
        // Forget guards to model a new HTTP request, rather than a cached test user.
        app('auth')->forgetGuards();
        $this->getJson('/api/contacts/access-test/read')->assertForbidden();
        $this->getJson('/api/user/me')->assertOk()->assertJsonPath('user.permissions', []);
    }

    public function test_self_and_workspace_owner_access_cannot_be_removed(): void
    {
        Sanctum::actingAs($this->superadmin);
        $own = $this->payload(['email' => $this->superadmin->email, 'role' => 'superadmin', 'status' => 'blocked']);
        $this->putJson('/api/admin/users/'.$this->superadmin->id, $own)->assertUnprocessable();
        $this->deleteJson('/api/admin/users/'.$this->superadmin->id)->assertUnprocessable();
        $owner = $this->user('admin');
        $this->workspace->update(['owner_id' => $owner->id]);
        $this->putJson('/api/admin/users/'.$owner->id, $this->payload(['email' => $owner->email, 'status' => 'blocked']))->assertUnprocessable();
        $this->deleteJson('/api/admin/users/'.$owner->id)->assertUnprocessable();
    }

    public function test_blocking_and_password_reset_revoke_tokens_and_blocked_login_fails(): void
    {
        $user = $this->user('agent');
        $user->createToken('existing-session');
        Sanctum::actingAs($this->superadmin);
        $this->putJson('/api/admin/users/'.$user->id, $this->payload(['email' => $user->email, 'status' => 'blocked']))->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'StrongPassword123!'])->assertUnprocessable();
        $user->createToken('another-session');
        $this->putJson('/api/admin/users/'.$user->id, $this->payload(['email' => $user->email, 'password' => 'Replacement123!']))->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(Hash::check('Replacement123!', $user->fresh()->password));
    }

    public function test_a_blocked_bearer_token_cannot_use_self_or_protected_endpoints(): void
    {
        $user = $this->user('admin');
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['status' => 'blocked']);
        $this->withToken($token)->getJson('/api/user/me')->assertForbidden();
        $this->getJson('/api/team/users')->assertForbidden();
    }

    public function test_realtime_channels_require_workspace_membership_and_module_access(): void
    {
        $channels = Broadcast::getChannels();
        $agent = $this->user('agent');
        $reader = $this->user('user');
        $other = Tenant::create(['name' => 'Other Workspace']);
        $this->assertTrue($channels['tenant.{id}.inbox']($agent, $this->workspace->id));
        $this->assertFalse($channels['tenant.{id}.inbox']($agent, $other->id));
        $this->assertFalse($channels['tenant.{id}.inbox']($reader, $this->workspace->id));
        $this->assertFalse($channels['tenant.{id}.campaigns']($agent, $this->workspace->id));
        $agent->update(['status' => 'blocked']);
        $this->assertFalse($channels['tenant.{id}.inbox']($agent, $this->workspace->id));
    }

    public function test_registration_returns_workspace_permissions_and_ignores_submitted_elevated_role(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New Owner', 'email' => 'owner@example.test',
            'password' => 'OwnerPassword123!', 'password_confirmation' => 'OwnerPassword123!',
            'role' => 'superadmin',
        ])->assertCreated()->assertJsonPath('user.role', 'admin');
        $this->assertContains('users.manage', $response->json('user.permissions'));
        $this->assertSame($response->json('user.id'), $response->json('tenant.owner_id'));
    }
}
