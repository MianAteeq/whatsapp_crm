<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use App\Services\Workflow\WorkflowContext;

class LanguageCondition implements ConditionHandlerInterface
{
    public function evaluate(WorkflowContext $context, array $config): bool
    {
        $contact = $context->getContact();
        if (!$contact) {
            return false;
        }

        $expectedLang = strtolower((string)($config['language'] ?? 'en'));
        
        // Check if contact preferred_language exists on contact model, else default to context variables fallback
        $actualLang = strtolower((string)($contact->preferred_language ?? $context->getVariable('language', 'en')));

        return $actualLang === $expectedLang;
    }
}
