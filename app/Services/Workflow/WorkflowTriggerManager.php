<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\AutomationWorkflow;
use App\Models\AutomationExecution;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Workflow\Triggers\TriggerRegistry;
use App\Jobs\ExecuteWorkflowJob;
use Log;

class WorkflowTriggerManager
{
    /**
     * Dispatch an event to look for matching active workflows and start background executions.
     * Returns true if at least one workflow matched and was dispatched.
     */
    public static function dispatchEvent(string $eventType, $tenant, array $payload = []): bool
    {
        $tenantId = is_numeric($tenant) ? (int)$tenant : ($tenant->id ?? null);
        if (!$tenantId) {
            Log::error("[WorkflowTriggerManager] Trigger failed: No valid Tenant identifier provided.");
            return false;
        }

        // Load active workflows for this tenant
        $workflows = AutomationWorkflow::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get();

        if ($workflows->isEmpty()) {
            return false;
        }

        $contact = $payload['contact'] ?? null;
        $message = $payload['message'] ?? null;
        self::resumeMatchingWaits($eventType, $tenantId, $contact?->id, $payload);
        $enrollmentService = app(EnrollmentService::class);
        if ($eventType === 'incoming_message' && $contact) {
            $enrollmentService->stopOnResponse($tenantId, (int) $contact->id);
            \DB::table('email_sequence_enrollments as enrollments')->join('email_sequences as sequences','sequences.id','=','enrollments.sequence_id')->where('enrollments.tenant_id',$tenantId)->where('enrollments.contact_id',$contact->id)->where('enrollments.status','active')->where('sequences.stop_on_response',true)->update(['enrollments.status'=>'stopped','enrollments.stop_reason'=>'Stopped because the customer responded.','enrollments.updated_at'=>now()]);
        }

        // Resolve context dependencies dynamically for evaluation
        $triggerRegistry = app(TriggerRegistry::class);

        $matched = false;

        foreach ($workflows as $workflow) {
            $version = $workflow->activeVersion();
            if (!$version) {
                // Skip if no snapshot has been published yet
                continue;
            }

            // Find trigger node in the version nodes snapshot
            $triggerNode = null;
            foreach ($version->nodes_data as $node) {
                $nodeType = strtolower($node['type']);
                if ($nodeType === 'trigger' || str_contains(strtolower($node['label']), 'trigger') || $triggerRegistry->has($nodeType)) {
                    $triggerNode = $node;
                    break;
                }
            }

            if (!$triggerNode) {
                continue;
            }

            // Map generic database types to registry trigger keys
            $triggerType = $triggerNode['type'];
            $label = strtolower($triggerNode['label']);

            // Map seeder trigger label values to registry types
            if ($triggerType === 'trigger') {
                if (str_contains($label, 'new contact') || str_contains($label, 'new customer')) {
                    $triggerType = 'contact_created';
                } elseif (str_contains($label, 'incoming') || str_contains($label, 'message')) {
                    $triggerType = 'incoming_message';
                } elseif (str_contains($label, 'keyword')) {
                    $triggerType = 'keyword';
                } elseif (str_contains($label, 'birthday') || str_contains($label, 'daily') || str_contains($label, 'schedule')) {
                    $triggerType = 'birthday';
                }
            }

            if (!$triggerRegistry->has($triggerType)) {
                continue;
            }

            // Only evaluate workflows whose trigger type matches the dispatched event.
            // e.g. when event is 'incoming_message', skip birthday/schedule workflows.
            if ($triggerType !== $eventType) {
                continue;
            }

            // Create temporary context to evaluate trigger criteria
            $tempExecution = new AutomationExecution([
                'workflow_id' => $workflow->id,
                'workflow_version_id' => $version->id,
                'contact_id' => $contact ? $contact->id : null,
                'status' => 'running'
            ]);

            $context = new WorkflowContext(
                $workflow,
                $tempExecution,
                $contact,
                null,
                $message,
                $payload
            );

            try {
                $handler = $triggerRegistry->make($triggerType);
                if ($handler->matches($context, $triggerNode['config'] ?? [])) {
                    Log::info("[WorkflowTriggerManager] Workflow [{$workflow->id}] matched trigger type [{$triggerType}]. Firing execution.");

                    $sourceEventId = isset($payload['event_id']) ? (string) $payload['event_id'] : null;
                    $execution = $enrollmentService->create(
                        $workflow, $version, $contact?->id, $eventType, $sourceEventId, $triggerNode['id']
                    );

                    // Dispatch execution job
                    ExecuteWorkflowJob::dispatch(
                        $workflow->id,
                        $contact ? $contact->id : null,
                        null,
                        $execution->id
                    );

                    $matched = true;
                }
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                Log::info("[WorkflowTriggerManager] Duplicate event ignored for workflow [{$workflow->id}].");
            } catch (\RuntimeException $e) {
                Log::info("[WorkflowTriggerManager] Enrollment skipped for workflow [{$workflow->id}]: {$e->getMessage()}");
            } catch (\Exception $e) {
                Log::error("[WorkflowTriggerManager] Evaluation crashed for Trigger type [{$triggerType}]: " . $e->getMessage());
            }
        }

        return $matched;
    }

    private static function resumeMatchingWaits(string $eventType, int $tenantId, ?int $contactId, array $payload): void
    {
        if (!$contactId) return;
        $waits = \DB::table('automation_event_waits as waits')
            ->join('automation_executions as executions','executions.id','=','waits.execution_id')
            ->where('waits.tenant_id',$tenantId)->where('waits.event_type',$eventType)->where('waits.status','waiting')
            ->where('executions.contact_id',$contactId)->select('waits.*','executions.workflow_id','executions.current_node_id')->get();
        foreach ($waits as $wait) {
            $match=(array)json_decode($wait->match_config ?: '{}',true);$matched=true;
            foreach($match as $key=>$expected) if(data_get($payload,$key)!=$expected){$matched=false;break;}
            if(!$matched) continue;
            \DB::transaction(function()use($wait,$contactId){$updated=\DB::table('automation_event_waits')->where('id',$wait->id)->where('status','waiting')->update(['status'=>'matched','matched_at'=>now(),'updated_at'=>now()]);if(!$updated)return;\App\Models\AutomationExecution::whereKey($wait->execution_id)->whereNotIn('status',['cancelled','completed','failed'])->update(['status'=>'pending','resume_at'=>null]);ExecuteWorkflowJob::dispatch($wait->workflow_id,$contactId,$wait->current_node_id,$wait->execution_id);});
        }
    }
}
