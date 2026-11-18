<?php

declare(strict_types=1);

namespace App\Services\Workflow\Triggers;

use App\Services\Workflow\WorkflowContext;

final class EventTrigger implements TriggerHandlerInterface
{
    public function matches(WorkflowContext $context, array $config): bool
    {
        $payload = $context->getPayload();
        foreach (($config['filters'] ?? []) as $key => $expected) {
            if (data_get($payload, $key) != $expected) return false;
        }
        return true;
    }
}
