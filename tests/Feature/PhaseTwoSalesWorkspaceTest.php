<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseTwoSalesWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'sales-owner@test.local'): User
    {
        $tenant = Tenant::create(['name'=>'Sales Test','currency_code'=>'USD','timezone'=>'UTC']);
        $user = User::create(['tenant_id'=>$tenant->id,'name'=>'Owner','email'=>$email,'password'=>Hash::make('password'),'status'=>'active']);
        $tenant->update(['owner_id'=>$user->id]);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_owner_creates_and_moves_an_opportunity_through_the_pipeline(): void
    {
        $this->owner();
        $bootstrap=$this->getJson('/api/sales/bootstrap')->assertOk()->json();
        $pipeline=$bootstrap['pipelines'][0]; $first=$pipeline['stages'][0]; $won=collect($pipeline['stages'])->firstWhere('is_won',true);
        $created=$this->postJson('/api/sales/opportunities',['pipeline_id'=>$pipeline['id'],'stage_id'=>$first['id'],'name'=>'Annual CRM Contract','value'=>25000,'currency_code'=>'USD'])->assertOk()->assertJsonPath('opportunity.status','open')->json('opportunity');
        $this->patchJson("/api/sales/opportunities/{$created['id']}/stage",['stage_id'=>$won['id']])->assertOk()->assertJsonPath('opportunity.status','won');
        $this->assertDatabaseHas('opportunities',['id'=>$created['id'],'status'=>'won']);
    }

    public function test_company_task_and_appointment_keep_tenant_relationships(): void
    {
        $owner=$this->owner();
        $company=$this->postJson('/api/sales/companies',['business_name'=>'Apex Clinic','owner_id'=>$owner->id,'email'=>'hello@apex.test','status'=>'active'])->assertOk()->json('company');
        $this->postJson('/api/sales/tasks',['title'=>'Call decision maker','owner_id'=>$owner->id,'business_id'=>$company['id'],'status'=>'pending','priority'=>'high','due_at'=>now()->addDay()->toISOString()])->assertOk();
        $this->postJson('/api/sales/appointments',['title'=>'Discovery call','business_id'=>$company['id'],'owner_id'=>$owner->id,'starts_at'=>now()->addDays(2)->toISOString(),'ends_at'=>now()->addDays(2)->addHour()->toISOString(),'timezone'=>'UTC','status'=>'scheduled'])->assertOk();
        $this->assertDatabaseHas('crm_tasks',['tenant_id'=>$owner->tenant_id,'business_id'=>$company['id']]);
        $this->assertDatabaseHas('appointments',['tenant_id'=>$owner->tenant_id,'business_id'=>$company['id']]);
    }

    public function test_sales_records_are_isolated_between_tenants(): void
    {
        $ownerA=$this->owner('owner-a@test.local');
        $company=$this->postJson('/api/sales/companies',['business_name'=>'Tenant A Private','status'=>'active'])->assertOk()->json('company');
        $this->owner('owner-b@test.local');
        $this->getJson("/api/sales/companies/{$company['id']}")->assertNotFound();
        $this->deleteJson("/api/sales/companies/{$company['id']}")->assertNotFound();
        $this->assertDatabaseHas('businesses',['id'=>$company['id'],'tenant_id'=>$ownerA->tenant_id]);
    }

    public function test_overview_uses_real_lifecycle_data_and_records_task_activity(): void
    {
        $owner=$this->owner();
        $bootstrap=$this->getJson('/api/sales/bootstrap')->assertOk()->json();
        $pipeline=$bootstrap['pipelines'][0];$stage=$pipeline['stages'][0];
        $opportunity=$this->postJson('/api/sales/opportunities',['pipeline_id'=>$pipeline['id'],'stage_id'=>$stage['id'],'name'=>'Measured pipeline deal','value'=>18000,'currency_code'=>'USD'])->assertOk()->json('opportunity');
        $task=$this->postJson('/api/sales/tasks',['title'=>'Complete customer follow-up','owner_id'=>$owner->id,'opportunity_id'=>$opportunity['id'],'status'=>'pending','priority'=>'urgent','due_at'=>now()->subHour()->toISOString()])->assertOk()->json('task');

        $this->getJson('/api/sales/overview')->assertOk()->assertJsonPath('kpis.open_count',1)->assertJsonPath('kpis.pipeline_value',18000)->assertJsonPath('kpis.overdue_tasks',1);
        $this->postJson("/api/sales/tasks/{$task['id']}/complete")->assertOk()->assertJsonPath('task.status','completed');
        $this->assertDatabaseHas('sales_activities',['tenant_id'=>$owner->tenant_id,'activity_type'=>'task_completed','subject_id'=>$task['id']]);
    }

    public function test_stage_required_fields_are_enforced_before_transition(): void
    {
        $this->owner();$bootstrap=$this->getJson('/api/sales/bootstrap')->assertOk()->json();$pipeline=$bootstrap['pipelines'][0];$first=$pipeline['stages'][0];$target=$pipeline['stages'][1];
        $this->postJson('/api/sales/stages',[...$target,'pipeline_id'=>$pipeline['id'],'required_fields'=>['contact_id','next_activity_at'],'allowed_role_slugs'=>[]])->assertOk();
        $opportunity=$this->postJson('/api/sales/opportunities',['pipeline_id'=>$pipeline['id'],'stage_id'=>$first['id'],'name'=>'Incomplete qualification','value'=>5000,'currency_code'=>'USD'])->assertOk()->json('opportunity');
        $this->patchJson("/api/sales/opportunities/{$opportunity['id']}/stage",['stage_id'=>$target['id']])->assertStatus(422)->assertJsonPath('missing_fields.0','contact_id');
        $this->assertDatabaseHas('opportunities',['id'=>$opportunity['id'],'stage_id'=>$first['id']]);
    }

    public function test_rescheduling_preserves_the_original_appointment_history(): void
    {
        $owner=$this->owner();$start=now()->addDay()->startOfHour();
        $appointment=$this->postJson('/api/sales/appointments',['title'=>'Customer demo','owner_id'=>$owner->id,'starts_at'=>$start->toISOString(),'ends_at'=>$start->copy()->addHour()->toISOString(),'timezone'=>'UTC','status'=>'scheduled'])->assertOk()->json('appointment');
        $next=$start->copy()->addDays(2);
        $rescheduled=$this->postJson("/api/sales/appointments/{$appointment['id']}/reschedule",['starts_at'=>$next->toISOString(),'ends_at'=>$next->copy()->addHour()->toISOString()])->assertOk()->json('appointment');
        $this->assertNotSame($appointment['id'],$rescheduled['id']);
        $this->assertDatabaseHas('appointments',['id'=>$appointment['id'],'status'=>'rescheduled']);
        $this->assertDatabaseHas('appointments',['id'=>$rescheduled['id'],'rescheduled_from_id'=>$appointment['id'],'status'=>'scheduled']);
    }
}
