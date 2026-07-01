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
                if ($nodeType === 'trigger' || str_contains(strtolower($node['label']), 'trigger') || $nodeType === 'incoming_message' || $nodeType === 'keyword' || $nodeType === 'contact_created' || $nodeType === 'birthday') {
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

                    // Create real execution record
                    $execution = AutomationExecution::create([
                        'workflow_id' => $workflow->id,
                        'workflow_version_id' => $version->id,
                        'contact_id' => $contact ? $contact->id : null,
                        'status' => 'running',
                        'current_node_id' => $triggerNode['id'],
                        'context_variables' => []
                    ]);

                    // Dispatch execution job
                    ExecuteWorkflowJob::dispatch(
                        $workflow->id,
                        $contact ? $contact->id : null,
                        null,
                        $execution->id
                    );

                    $matched = true;
                }
            } catch (\Exception $e) {
                Log::error("[WorkflowTriggerManager] Evaluation crashed for Trigger type [{$triggerType}]: " . $e->getMessage());
            }
        }

        return $matched;
    }
}
