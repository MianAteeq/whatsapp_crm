<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AutomationWorkflow;
use App\Models\AutomationNode;
use App\Models\AutomationConnection;
use App\Models\Tenant;
use App\Models\User;

class AutomationSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first() ?: Tenant::create(['name' => 'Demo Workspace', 'plan' => 'pro']);
        $user = User::where('email', 'admin@throbtech.com')->first();
        $userId = $user ? $user->id : 1;

        // Clean existing records to prevent duplicates
        AutomationWorkflow::where('tenant_id', $tenant->id)->delete();

        // 1. Birthday Workflow
        $birthdayWorkflow = AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'name' => 'Birthday Discount Campaign',
            'description' => 'Checks daily if today is contact birthday, then automatically sends a WhatsApp coupon code template.',
            'status' => 'active',
            'version' => '1.0.0',
            'created_by' => $userId,
            'last_run_at' => now()->subHours(4),
            'next_run_at' => now()->addHours(20)
        ]);

        $bNode1 = AutomationNode::create([
            'id' => 'b-node-1',
            'workflow_id' => $birthdayWorkflow->id,
            'type' => 'trigger',
            'label' => 'Scheduled Time (Daily)',
            'config' => ['time' => '09:00', 'timezone' => 'UTC'],
            'position_x' => 150.0,
            'position_y' => 100.0
        ]);

        $bNode2 = AutomationNode::create([
            'id' => 'b-node-2',
            'workflow_id' => $birthdayWorkflow->id,
            'type' => 'condition',
            'label' => 'Is Contact Birthday Today?',
            'config' => ['field' => 'birthday', 'operator' => 'today'],
            'position_x' => 150.0,
            'position_y' => 220.0
        ]);

        $bNode3 = AutomationNode::create([
            'id' => 'b-node-3',
            'workflow_id' => $birthdayWorkflow->id,
            'type' => 'action',
            'label' => 'Send Template (Birthday Promo)',
            'config' => ['template_id' => 'birthday_promo_v1', 'language' => 'en'],
            'position_x' => 150.0,
            'position_y' => 340.0
        ]);

        $bNode4 = AutomationNode::create([
            'id' => 'b-node-4',
            'workflow_id' => $birthdayWorkflow->id,
            'type' => 'action',
            'label' => 'End Workflow',
            'config' => [],
            'position_x' => 150.0,
            'position_y' => 460.0
        ]);

        AutomationConnection::create([
            'workflow_id' => $birthdayWorkflow->id,
            'source_node_id' => 'b-node-1',
            'target_node_id' => 'b-node-2',
            'source_handle' => 'bottom',
            'target_handle' => 'top'
        ]);

        AutomationConnection::create([
            'workflow_id' => $birthdayWorkflow->id,
            'source_node_id' => 'b-node-2',
            'target_node_id' => 'b-node-3',
            'source_handle' => 'bottom',
            'target_handle' => 'top'
        ]);

        AutomationConnection::create([
            'workflow_id' => $birthdayWorkflow->id,
            'source_node_id' => 'b-node-3',
            'target_node_id' => 'b-node-4',
            'source_handle' => 'bottom',
            'target_handle' => 'top'
        ]);

        $birthdayWorkflow->createVersionSnapshot('1.0.0');

        // 2. New Customer Workflow
        $newCustomerWorkflow = AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'name' => 'New Customer Onboarding Flow',
            'description' => 'Triggered automatically when a new contact is created. Sends an immediate welcome message, waits 1 day, then assigns to account manager.',
            'status' => 'active',
            'version' => '1.0.0',
            'created_by' => $userId,
            'last_run_at' => now()->subMinutes(12),
            'next_run_at' => null
        ]);

        $cNode1 = AutomationNode::create([
            'id' => 'c-node-1',
            'workflow_id' => $newCustomerWorkflow->id,
            'type' => 'trigger',
            'label' => 'New Contact Created',
            'config' => [],
            'position_x' => 150.0,
            'position_y' => 100.0
        ]);

        $cNode2 = AutomationNode::create([
            'id' => 'c-node-2',
            'workflow_id' => $newCustomerWorkflow->id,
            'type' => 'action',
            'label' => 'Send Message (Welcome Text)',
            'config' => ['message' => 'Hello! Welcome to our store. We are thrilled to have you with us.'],
            'position_x' => 150.0,
            'position_y' => 220.0
        ]);

        $cNode3 = AutomationNode::create([
            'id' => 'c-node-3',
            'workflow_id' => $newCustomerWorkflow->id,
            'type' => 'delay',
            'label' => 'Wait 1 Day',
            'config' => ['duration' => 1, 'unit' => 'day'],
            'position_x' => 150.0,
            'position_y' => 340.0
        ]);

        $cNode4 = AutomationNode::create([
            'id' => 'c-node-4',
            'workflow_id' => $newCustomerWorkflow->id,
            'type' => 'action',
            'label' => 'Assign Agent',
            'config' => ['agent_id' => $userId],
            'position_x' => 150.0,
            'position_y' => 460.0
        ]);

        $cNode5 = AutomationNode::create([
            'id' => 'c-node-5',
            'workflow_id' => $newCustomerWorkflow->id,
            'type' => 'action',
            'label' => 'End Workflow',
            'config' => [],
            'position_x' => 150.0,
            'position_y' => 580.0
        ]);

        AutomationConnection::create([
            'workflow_id' => $newCustomerWorkflow->id,
            'source_node_id' => 'c-node-1',
            'target_node_id' => 'c-node-2',
            'source_handle' => 'bottom',
            'target_handle' => 'top'
        ]);

        AutomationConnection::create([
            'workflow_id' => $newCustomerWorkflow->id,
            'source_node_id' => 'c-node-2',
            'target_node_id' => 'c-node-3',
            'source_handle' => 'bottom',
            'target_handle' => 'top'
        ]);

        AutomationConnection::create([
            'workflow_id' => $newCustomerWorkflow->id,
            'source_node_id' => 'c-node-3',
            'target_node_id' => 'c-node-4',
            'source_handle' => 'bottom',
            'target_handle' => 'top'
        ]);

        AutomationConnection::create([
            'workflow_id' => $newCustomerWorkflow->id,
            'source_node_id' => 'c-node-4',
            'target_node_id' => 'c-node-5',
            'source_handle' => 'bottom',
            'target_handle' => 'top'
        ]);

        $newCustomerWorkflow->createVersionSnapshot('1.0.0');
    }
}
