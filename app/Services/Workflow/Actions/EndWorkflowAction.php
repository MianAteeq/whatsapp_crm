<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;

class EndWorkflowAction implements ActionHandlerInterface
{
    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        return ActionResponse::success('Workflow execution reached the end state.');
    }
}
