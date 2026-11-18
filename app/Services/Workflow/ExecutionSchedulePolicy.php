<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\AutomationPauseWindow;
use App\Models\AutomationWorkflow;
use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

final class ExecutionSchedulePolicy
{
    public function nextAllowedAt(AutomationWorkflow $workflow, ?Contact $contact, ?CarbonImmutable $utcNow = null): CarbonImmutable
    {
        $utcNow ??= CarbonImmutable::now('UTC');
        $tenant = Tenant::find($workflow->tenant_id);
        $timezone = $workflow->timezone_mode === 'contact' && $contact?->timezone
            ? $contact->timezone
            : ($tenant?->timezone ?: 'UTC');
        if (!in_array($timezone, timezone_identifiers_list(), true)) $timezone = 'UTC';

        $candidate = $utcNow->setTimezone($timezone);
        $days = $workflow->execution_days ?: [1, 2, 3, 4, 5, 6, 7];
        $start = $workflow->execution_start_time;
        $end = $workflow->execution_end_time;

        for ($guard = 0; $guard < 370; $guard++) {
            if (!in_array($candidate->isoWeekday(), array_map('intval', $days), true)) {
                $candidate = $candidate->addDay()->startOfDay();
                continue;
            }
            if ($start) {
                [$hour, $minute] = array_map('intval', explode(':', $start));
                $windowStart = $candidate->setTime($hour, $minute);
                if ($candidate->lessThan($windowStart)) $candidate = $windowStart;
            }
            if ($end) {
                [$hour, $minute] = array_map('intval', explode(':', $end));
                if ($candidate->greaterThan($candidate->setTime($hour, $minute))) {
                    $candidate = $candidate->addDay()->startOfDay();
                    continue;
                }
            }
            $pauseEnd = $this->activePauseEnd($workflow, $candidate->utc());
            if ($pauseEnd) {
                $candidate = $pauseEnd->setTimezone($timezone);
                continue;
            }
            return $candidate->utc();
        }
        return $utcNow->addYear();
    }

    private function activePauseEnd(AutomationWorkflow $workflow, CarbonImmutable $utc): ?CarbonImmutable
    {
        $windows = AutomationPauseWindow::where('tenant_id', $workflow->tenant_id)->where('workflow_id', $workflow->id)->where('pause_execution', true)->get();
        foreach ($windows as $window) {
            $start = CarbonImmutable::parse($window->starts_at)->utc();
            $end = CarbonImmutable::parse($window->ends_at)->utc();
            if ($window->recurrence === 'annual') {
                $start = $start->setYear($utc->year);
                $end = $end->setYear($utc->year + ($end->lessThan($start) ? 1 : 0));
            }
            if ($utc->betweenIncluded($start, $end)) return $end;
        }
        return null;
    }
}
