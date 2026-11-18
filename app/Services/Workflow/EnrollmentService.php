<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\AutomationEnrollment;
use App\Models\AutomationExecution;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class EnrollmentService
{
    private const ACTIVE_STATUSES = ['pending', 'running', 'paused', 'retrying'];

    public function create(
        AutomationWorkflow $workflow,
        AutomationWorkflowVersion $version,
        ?int $contactId,
        string $source,
        ?string $sourceEventId,
        string $startNodeId
    ): AutomationExecution {
        return DB::transaction(function () use ($workflow, $version, $contactId, $source, $sourceEventId, $startNodeId) {
            $locked = AutomationWorkflow::whereKey($workflow->id)->lockForUpdate()->firstOrFail();
            $active = AutomationEnrollment::where('tenant_id', $locked->tenant_id)
                ->where('workflow_id', $locked->id)
                ->where('contact_id', $contactId)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->count();

            if ($active >= max(1, (int) $locked->max_active_enrollments_per_contact)) {
                throw new RuntimeException('Contact already has the maximum active enrollments for this workflow.');
            }

            $latest = AutomationEnrollment::where('tenant_id', $locked->tenant_id)
                ->where('workflow_id', $locked->id)
                ->where('contact_id', $contactId)
                ->latest('id')->first();

            if ($latest && $locked->reentry_policy === 'never') {
                throw new RuntimeException('Workflow re-entry is disabled.');
            }
            if ($latest && $locked->reentry_policy === 'after_completion' && !in_array($latest->status, ['completed', 'cancelled', 'failed'], true)) {
                throw new RuntimeException('Workflow re-entry is allowed only after completion.');
            }
            if ($latest && $locked->reentry_cooldown_minutes > 0 && $latest->created_at->addMinutes($locked->reentry_cooldown_minutes)->isFuture()) {
                throw new RuntimeException('Workflow re-entry cooldown is still active.');
            }

            $enrollment = AutomationEnrollment::create([
                'tenant_id' => $locked->tenant_id,
                'workflow_id' => $locked->id,
                'workflow_version_id' => $version->id,
                'contact_id' => $contactId,
                'enrollment_key' => (string) Str::uuid(),
                'status' => 'pending',
                'source' => $source,
                'source_event_id' => $sourceEventId,
            ]);

            return AutomationExecution::create([
                'tenant_id' => $locked->tenant_id,
                'workflow_id' => $locked->id,
                'workflow_version_id' => $version->id,
                'enrollment_id' => $enrollment->id,
                'contact_id' => $contactId,
                'status' => 'pending',
                'current_node_id' => $startNodeId,
                'context_variables' => [],
            ]);
        });
    }

    public function stopOnResponse(int $tenantId, int $contactId): int
    {
        return DB::transaction(function () use ($tenantId, $contactId) {
            $enrollments = AutomationEnrollment::query()
                ->where('tenant_id', $tenantId)->where('contact_id', $contactId)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->whereHas('workflow', fn ($q) => $q->where('stop_on_response', true))
                ->lockForUpdate()->get();

            foreach ($enrollments as $enrollment) {
                $reason = 'Stopped because the customer responded.';
                $enrollment->update(['status' => 'cancelled', 'stop_reason' => $reason, 'cancelled_at' => now()]);
                $enrollment->executions()->whereIn('status', self::ACTIVE_STATUSES)->update([
                    'status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_at' => now(), 'finished_at' => now(),
                ]);
            }
            return $enrollments->count();
        });
    }
}
