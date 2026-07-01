<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use App\Services\Workflow\WorkflowContext;

class CountryCondition implements ConditionHandlerInterface
{
    public function evaluate(WorkflowContext $context, array $config): bool
    {
        $contact = $context->getContact();
        if (!$contact) {
            return false;
        }

        $phone = preg_replace('/\D/', '', $contact->phone);
        $countries = $config['countries'] ?? ($config['country_codes'] ?? []);
        if (is_string($countries)) {
            $countries = array_map('trim', explode(',', $countries));
        }

        foreach ($countries as $code) {
            $codeClean = preg_replace('/\D/', '', (string)$code);
            if (str_starts_with($phone, $codeClean)) {
                return true;
            }
        }

        return false;
    }
}
