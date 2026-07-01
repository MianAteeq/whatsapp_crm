<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use App\Services\Workflow\WorkflowContext;
use Carbon\Carbon;

class BirthdayCondition implements ConditionHandlerInterface
{
    public function evaluate(WorkflowContext $context, array $config): bool
    {
        $contact = $context->getContact();
        if (!$contact || empty($contact->birthday)) {
            return false;
        }

        $birthday = Carbon::parse($contact->birthday);
        $today = Carbon::today();

        return $birthday->month === $today->month && $birthday->day === $today->day;
    }
}
