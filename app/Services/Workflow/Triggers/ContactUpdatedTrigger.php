<?php

declare(strict_types=1);

namespace App\Services\Workflow\Triggers;

use App\Services\Workflow\WorkflowContext;

class ContactUpdatedTrigger implements TriggerHandlerInterface
{
    public function matches(WorkflowContext $context, array $config): bool
    {
        // For contact updates, check if a target parameter got modified
        $contact = $context->getContact();
        if (!$contact) {
            return false;
        }

        $targetField = $config['field'] ?? null;
        if ($targetField) {
            // Check dirty fields in payload if provided
            $dirtyFields = $context->getPayload()['dirty'] ?? [];
            return in_array($targetField, $dirtyFields);
        }

        return true;
    }
}
