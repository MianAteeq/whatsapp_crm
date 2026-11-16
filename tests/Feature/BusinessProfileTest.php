<?php

namespace Tests\Feature;

use App\Models\BusinessProfileAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        app('db')->purge('sqlite');
        Event::fake();

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->string('timezone')->default('UTC');
            $table->string('customer_timezone_policy')->default('customer_when_available');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('admin');
            $table->string('status')->default('active');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('business_profile_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('user_id')->nullable();
            $table->json('changes');
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('actor_type', 20)->default('user');
            $table->string('actor_label')->nullable();
            $table->string('module', 60)->index();
            $table->string('action', 80)->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function test_admin_updates_workspace_profile_without_rewriting_history(): void
    {
        [$tenant, $user] = $this->actor('admin');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/settings/business-profile', [
            'name' => 'ThrobTech Saudi',
            'legal_name' => 'ThrobTech LLC',
            'address_line_1' => 'King Fahd Road',
            'address_line_2' => '',
            'city' => 'Riyadh',
            'region' => 'Riyadh',
            'postal_code' => '12211',
            'country_code' => 'sa',
            'currency_code' => 'sar',
            'timezone' => 'Asia/Riyadh',
            'customer_timezone_policy' => 'customer_when_available',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.legal_name', 'ThrobTech LLC')
            ->assertJsonPath('data.country_code', 'SA')
            ->assertJsonPath('data.currency_code', 'SAR')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh');

        $this->assertStringContainsString('Existing historical financial values were not converted', (string) $response->json('currency_change_notice'));
        $this->assertSame('SAR', $tenant->fresh()->currency_code);
        $this->assertSame(1, BusinessProfileAuditLog::where('tenant_id', $tenant->id)->count());
    }

    public function test_profile_is_tenant_scoped_and_non_admin_cannot_update(): void
    {
        [$tenantA, $admin] = $this->actor('admin', 'a@example.test');
        [$tenantB, $member] = $this->actor('member', 'b@example.test');
        $tenantB->update(['legal_name' => 'Private Tenant B']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/settings/business-profile')
            ->assertOk()
            ->assertJsonPath('data.name', $tenantA->name)
            ->assertJsonMissing(['legal_name' => 'Private Tenant B']);

        Sanctum::actingAs($member);
        $this->postJson('/api/settings/business-profile', [])->assertForbidden();
    }

    public function test_invalid_timezone_and_currency_are_rejected(): void
    {
        [, $user] = $this->actor('admin');
        Sanctum::actingAs($user);

        $this->postJson('/api/settings/business-profile', [
            'name' => 'Workspace',
            'legal_name' => 'Workspace LLC',
            'address_line_1' => 'Address',
            'city' => 'Riyadh',
            'country_code' => 'SA',
            'currency_code' => 'USDD',
            'timezone' => 'Mars/Olympus',
            'customer_timezone_policy' => 'customer_when_available',
        ])->assertUnprocessable()->assertJsonValidationErrors(['currency_code', 'timezone']);
    }

    private function actor(string $role, string $email = 'admin@example.test'): array
    {
        $tenant = Tenant::create(['name' => "Workspace {$email}", 'currency_code' => 'USD']);
        $user = User::withoutEvents(fn () => User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]));

        return [$tenant, $user];
    }
}
