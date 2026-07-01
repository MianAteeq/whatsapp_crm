<?php

declare(strict_types=1);

namespace App\Services\Workflow\Conditions;

use App\Services\Workflow\WorkflowContext;
use Carbon\Carbon;

class TimeCondition implements ConditionHandlerInterface
{
    public function evaluate(WorkflowContext $context, array $config): bool
    {
        $timezone = $config['timezone'] ?? 'UTC';
        $now = Carbon::now($timezone);

        $days = $config['days'] ?? []; // e.g. ['monday', 'tuesday']
        if (!empty($days)) {
            $currentDay = strtolower($now->format('l'));
            if (!in_array($currentDay, array_map('strtolower', $days))) {
                return false;
            }
        }

        $startTime = $config['start_time'] ?? null; // e.g. "09:00"
        $endTime = $config['end_time'] ?? null;     // e.g. "17:00"

        if ($startTime && $endTime) {
            $start = Carbon::createFromTimeString($startTime, $timezone);
            $end = Carbon::createFromTimeString($endTime, $timezone);
            return $now->between($start, $end);
        }

        return true;
    }
}
