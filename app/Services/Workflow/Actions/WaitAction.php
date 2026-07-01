<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;
use Carbon\Carbon;

class WaitAction implements ActionHandlerInterface
{
    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $amount = (int)($config['amount'] ?? ($config['duration'] ?? 1));
        $unit = strtolower((string)($config['unit'] ?? 'minute')); // minute, hour, day, seconds

        if ($amount <= 0) {
            $amount = 1;
        }

        $now = Carbon::now();
        $resumeTime = match ($unit) {
            'second', 'seconds' => $now->addSeconds($amount),
            'minute', 'minutes' => $now->addMinutes($amount),
            'hour', 'hours' => $now->addHours($amount),
            'day', 'days' => $now->addDays($amount),
            default => $now->addMinutes($amount)
        };

        $message = "Workflow execution paused. Will resume in {$amount} {$unit}(s) at {$resumeTime->toDateTimeString()}.";

        return ActionResponse::wait($resumeTime, $message);
    }
}
