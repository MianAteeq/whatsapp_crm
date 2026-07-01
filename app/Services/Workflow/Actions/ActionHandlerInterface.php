<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;

interface ActionHandlerInterface
{
    /**
     * Execute the visual workflow node logic.
     */
    public function execute(WorkflowContext $context, array $config): ActionResponse;
}
