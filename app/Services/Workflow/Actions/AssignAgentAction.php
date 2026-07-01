<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;
use App\Models\User;

class AssignAgentAction implements ActionHandlerInterface
{
    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $agentId = $config['agent_id'] ?? ($config['agentId'] ?? null);
        if (!$agentId) {
            return ActionResponse::failed('No Agent ID specified in action configuration.');
        }

        $agent = User::find($agentId);
        $agentName = $agent ? $agent->name : "Agent #{$agentId}";

        // Save assigned agent to execution context variables
        $context->setVariable('assigned_agent_id', $agentId);
        $context->setVariable('assigned_agent_name', $agentName);

        return ActionResponse::success("Conversation assigned to agent: {$agentName}.");
    }
}
