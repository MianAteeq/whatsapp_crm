<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use App\Services\Workflow\WorkflowContext;

interface ConditionHandlerInterface
{
    /**
     * Evaluate if the condition holds true based on context data and node configurations.
     */
    public function evaluate(WorkflowContext $context, array $config): bool;
}
