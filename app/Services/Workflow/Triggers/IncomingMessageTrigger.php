<?php

declare(strict_types=1);

namespace App\Services\Workflow\Triggers;

use App\Services\Workflow\WorkflowContext;

class IncomingMessageTrigger implements TriggerHandlerInterface
{
    public function matches(WorkflowContext $context, array $config): bool
    {
        // Triggers on any incoming message
        return $context->getMessage() !== null;
    }
}
