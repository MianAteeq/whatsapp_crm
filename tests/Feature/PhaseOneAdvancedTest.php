<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\PhaseOne\AccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseOneAdvancedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite'); config()->set('database.connections.sqlite.database', ':memory:'); app('db')->purge('sqlite'); Event::fake();
        Schema::create('tenants', function (Blueprint $t) { $t->id(); $t->string('name'); $t->foreignId('owner_id')->nullable(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->foreignId('tenant_id')->nullable(); $t->foreignId('tenant_role_id')->nullable(); $t->string('name'); $t->string('email')->unique(); $t->string('password'); $t->string('role')->default('admin'); $t->string('status')->default('active'); $t->rememberToken(); $t->timestamps(); });
        Schema::create('tenant_roles', function (Blueprint $t) { $t->id(); $t->foreignId('tenant_id'); $t->string('name'); $t->string('slug'); $t->text('description')->nullable(); $t->json('permissions'); $t->boolean('is_system')->default(false); $t->timestamps(); $t->unique(['tenant_id','slug']); });
        Schema::create('tenant_contact_policies', function (Blueprint $t) { $t->id(); $t->foreignId('tenant_id')->unique(); $t->boolean('allow_duplicates')->default(false); $t->boolean('match_email')->default(true); $t->boolean('match_phone')->default(true); $t->string('ambiguous_action')->default('review'); $t->string('email_verification_policy')->default('first_send'); $t->boolean('unsubscribe_footer_enabled')->default(true); $t->string('unsubscribe_label')->default('Unsubscribe'); $t->timestamps(); });
        Schema::create('duplicate_review_items', function (Blueprint $t) { $t->id(); $t->foreignId('tenant_id'); $t->string('source')->default('manual'); $t->json('incoming_data'); $t->json('candidate_contact_ids'); $t->json('reasons'); $t->string('status')->default('pending'); $t->foreignId('resolved_by')->nullable(); $t->foreignId('resolved_contact_id')->nullable(); $t->timestamps(); });
        Schema::create('audit_logs', function (Blueprint $t) { $t->id(); $t->foreignId('tenant_id'); $t->foreignId('user_id')->nullable(); $t->string('actor_type'); $t->string('actor_label')->nullable(); $t->string('module'); $t->string('action'); $t->string('auditable_type')->nullable(); $t->unsignedBigInteger('auditable_id')->nullable(); $t->text('description')->nullable(); $t->json('old_values')->nullable(); $t->json('new_values')->nullable(); $t->json('metadata')->nullable(); $t->string('ip_address')->nullable(); $t->text('user_agent')->nullable(); $t->timestamp('created_at')->nullable(); });
    }

    public function test_owner_can_create_user_and_agent_cannot_manage_users(): void
    {
        $tenant = Tenant::create(['name' => 'Test Workspace']);
        $owner = User::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'email' => 'owner@test.local', 'password' => Hash::make('password')]);
        $tenant->update(['owner_id' => $owner->id]); app(AccessService::class)->ensureRoles($tenant->id);
        $owner->update(['tenant_role_id' => TenantRole::where('tenant_id',$tenant->id)->where('slug','owner')->value('id')]);
        $agentRole = TenantRole::where('tenant_id',$tenant->id)->where('slug','agent')->firstOrFail();
        Sanctum::actingAs($owner);
        $this->postJson('/api/workspace/users', ['name'=>'New Agent','email'=>'agent@test.local','tenant_role_id'=>$agentRole->id,'status'=>'active','password'=>'Secure123!','password_confirmation'=>'Secure123!'])
            ->assertCreated()->assertJsonPath('user.email','agent@test.local');
        $agent = User::where('email','agent@test.local')->firstOrFail();
        Sanctum::actingAs($agent);
        $this->postJson('/api/workspace/users', ['name'=>'Blocked','email'=>'blocked@test.local','tenant_role_id'=>$agentRole->id,'password'=>'Secure123!','password_confirmation'=>'Secure123!'])->assertForbidden();
    }

    public function test_owner_role_cannot_be_assigned_to_another_user(): void
    {
        $tenant = Tenant::create(['name' => 'Test Workspace']); $owner = User::create(['tenant_id'=>$tenant->id,'name'=>'Owner','email'=>'owner2@test.local','password'=>Hash::make('password')]); $tenant->update(['owner_id'=>$owner->id]); app(AccessService::class)->ensureRoles($tenant->id);
        Sanctum::actingAs($owner); $ownerRole = TenantRole::where('tenant_id',$tenant->id)->where('slug','owner')->firstOrFail();
        $this->postJson('/api/workspace/users', ['name'=>'Second Owner','email'=>'other@test.local','tenant_role_id'=>$ownerRole->id,'password'=>'Secure123!','password_confirmation'=>'Secure123!'])->assertUnprocessable()->assertJsonPath('message','Ownership cannot be assigned from role management.');
    }
}
