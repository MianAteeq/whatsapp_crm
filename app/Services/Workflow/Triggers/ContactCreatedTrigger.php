<?php

declare(strict_types=1);

namespace App\Services\Workflow\Triggers;

use App\Services\Workflow\WorkflowContext;

class ContactCreatedTrigger implements TriggerHandlerInterface
{
    public function matches(WorkflowContext $context, array $config): bool
    {
        return $context->getContact() !== null;
    }
}
