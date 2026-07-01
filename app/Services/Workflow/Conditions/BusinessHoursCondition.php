<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use App\Services\Workflow\WorkflowContext;
use Carbon\Carbon;

class BusinessHoursCondition implements ConditionHandlerInterface
{
    public function evaluate(WorkflowContext $context, array $config): bool
    {
        $timezone = $config['timezone'] ?? 'UTC';
        $now = Carbon::now($timezone);

        // Standard default: Mon-Fri, 9:00 - 18:00
        $businessDays = $config['days'] ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $startTime = $config['start_time'] ?? '09:00';
        $endTime = $config['end_time'] ?? '18:00';

        $currentDay = strtolower($now->format('l'));
        if (!in_array($currentDay, array_map('strtolower', $businessDays))) {
            return false;
        }

        $start = Carbon::createFromTimeString($startTime, $timezone);
        $end = Carbon::createFromTimeString($endTime, $timezone);

        return $now->between($start, $end);
    }
}
