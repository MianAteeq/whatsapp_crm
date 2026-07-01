<?php

declare(strict_types=1);

namespace App\Services\Workflow\Triggers;

use App\Services\Workflow\WorkflowContext;

interface TriggerHandlerInterface
{
    /**
     * Check if the incoming system event matches the trigger criteria.
     */
    public function matches(WorkflowContext $context, array $config): bool;
}
