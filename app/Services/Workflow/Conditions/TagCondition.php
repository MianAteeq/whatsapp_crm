<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use App\Services\Workflow\WorkflowContext;

class TagCondition implements ConditionHandlerInterface
{
    public function evaluate(WorkflowContext $context, array $config): bool
    {
        $contact = $context->getContact();
        if (!$contact) {
            return false;
        }

        $tagName = $config['tag'] ?? ($config['tag_name'] ?? ($config['tagName'] ?? ''));
        if (empty($tagName)) {
            return false;
        }

        $operator = $config['operator'] ?? 'exists'; // exists, not_exists

        $exists = $contact->tags()->where('name', $tagName)->exists();

        return ($operator === 'not_exists') ? !$exists : $exists;
    }
}
