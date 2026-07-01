<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\AutomationExecution;
use App\Models\AutomationWorkflow;
use App\Models\AutomationLog;
use App\Services\Workflow\Actions\ActionRegistry;
use App\Services\Workflow\Conditions\ConditionRegistry;
use App\Jobs\ExecuteWorkflowJob;
use Carbon\Carbon;
use Log;

class WorkflowEngine
{
    protected ActionRegistry $actionRegistry;
    protected ConditionRegistry $conditionRegistry;

    public function __construct(ActionRegistry $actionRegistry, ConditionRegistry $conditionRegistry)
    {
        $this->actionRegistry = $actionRegistry;
        $this->conditionRegistry = $conditionRegistry;
    }

    /**
     * Execute a workflow execution starting from a given node.
     */
    public function execute(int $executionId, ?string $startNodeId = null): void
    {
        $execution = AutomationExecution::with(['workflow', 'contact'])->find($executionId);
        if (!$execution) {
            Log::error("[WorkflowEngine] Execution [{$executionId}] not found.");
            return;
        }

        $workflow = $execution->workflow;
        $contact = $execution->contact;

        // Strict Tenant Isolation check
        if ($workflow->tenant_id && $contact && $contact->tenant_id !== $workflow->tenant_id) {
            Log::error("[WorkflowEngine] Tenant isolation violation. Workflow tenant: {$workflow->tenant_id}, Contact tenant: {$contact->tenant_id}");
            return;
        }

        // Initialize execution metrics
        if (null === $execution->started_at) {
            $execution->update([
                'started_at' => Carbon::now(),
                'status' => 'running'
            ]);
        }

        // 1. Resolve Workflow Version Snapshot
        $version = $execution->version;
        if (!$version) {
            // Load current active version, or dynamically create snapshot of current draft canvas
            $version = $workflow->activeVersion();
            if (!$version) {
                $version = $workflow->createVersionSnapshot('1.0.0');
            }
            $execution->update([
                'workflow_version_id' => $version->id
            ]);
        }

        $nodes = $version->nodes_data;
        $connections = $version->connections_data;

        Log::info('Connections', $version->connections_data);



        // Build node lookups
        $nodeMap = [];
        foreach ($nodes as $n) {
            $nodeMap[$n['id']] = $n;
        }

        // 2. Determine Starting Node
        $currentNodeId = $startNodeId ?: $execution->current_node_id;
        if (!$currentNodeId) {
            // Find trigger node
            $triggerNode = null;
            foreach ($nodes as $n) {
                if ($n['type'] === 'trigger' || str_contains(strtolower($n['label']), 'trigger') || $n['type'] === 'incoming_message' || $n['type'] === 'keyword' || $n['type'] === 'contact_created' || $n['type'] === 'birthday') {
                    $triggerNode = $n;
                    break;
                }
            }
            if (!$triggerNode) {
                $errorMsg = 'No trigger node found in the workflow version snapshot.';
                $execution->update([
                    'status' => 'failed',
                    'last_error' => $errorMsg,
                    'finished_at' => Carbon::now()
                ]);
                return;
            }
            $currentNodeId = $triggerNode['id'];
        }

        // Initialize Context
        // Resolve conversation and latest incoming message if contact exists
        $conversation = null;
        $latestMessage = null;
        if ($contact) {
            $conversation = \App\Models\Conversation::where('tenant_id', $workflow->tenant_id)
                ->where('contact_id', $contact->id)
                ->first();

            // Load the latest incoming message so condition handlers can check message content
            if ($conversation) {
                $latestMessage = \App\Models\Message::where('conversation_id', $conversation->id)
                    ->where('direction', 'incoming')
                    ->orderBy('id', 'desc')
                    ->first();
            }
        }

        $context = new WorkflowContext(
            $workflow,
            $execution,
            $contact,
            $conversation,
            $latestMessage,
            []
        );

        Log::info("[WorkflowEngine] Starting execution [{$execution->id}] on node [{$currentNodeId}]");

        // Execution loop
        while ($currentNodeId) {
            $node = $nodeMap[$currentNodeId] ?? null;
            if (!$node) {
                break;
            }

            $execution->update(['current_node_id' => $currentNodeId]);
            $startTime = microtime(true);

            // Match categories: Trigger, Condition, Action
            $nodeTypeClass = $node['type'];

            // A. Trigger Nodes (just bridge to the next connected node)
            if ($nodeTypeClass === 'trigger' || $nodeTypeClass === 'incoming_message' || $nodeTypeClass === 'keyword' || $nodeTypeClass === 'contact_created' || $nodeTypeClass === 'birthday' || $nodeTypeClass === 'schedule') {
                $currentNodeId = $this->getNextNodeForHandle($connections, $currentNodeId, 'bottom');
                continue;
            }

            // B. Condition Nodes
            // if ($nodeTypeClass === 'condition') {
            //     try {
            //         // Resolve condition handler from config.field first, then fall back to label matching
            //         $conditionType = 'condition';
            //         $configField = $node['config']['field'] ?? '';
            //         $label = strtolower($node['label']);

            //         // 1. Resolve from config.field (set by visual builder)
            //         if ($configField === 'message') {
            //             $conditionType = 'keyword_condition';
            //         } elseif ($configField === 'contact_tag') {
            //             $conditionType = 'tag_condition';
            //         } elseif ($configField === 'country') {
            //             $conditionType = 'country_condition';
            //         } elseif ($configField === 'language') {
            //             $conditionType = 'language_condition';
            //         } elseif ($configField === 'birthday') {
            //             $conditionType = 'birthday_condition';
            //         } elseif ($configField === 'business_hours') {
            //             $conditionType = 'business_hours_condition';
            //         } elseif ($configField === 'time') {
            //             $conditionType = 'time_condition';
            //         }
            //         // 2. Fallback: resolve from label text (for old seeder nodes)
            //         elseif (str_contains($label, 'tag')) {
            //             $conditionType = 'tag_condition';
            //         } elseif (str_contains($label, 'keyword')) {
            //             $conditionType = 'keyword_condition';
            //         } elseif (str_contains($label, 'time')) {
            //             $conditionType = 'time_condition';
            //         } elseif (str_contains($label, 'country')) {
            //             $conditionType = 'country_condition';
            //         } elseif (str_contains($label, 'language') || str_contains($label, 'locale')) {
            //             $conditionType = 'language_condition';
            //         } elseif (str_contains($label, 'birthday') || str_contains($label, 'birth')) {
            //             $conditionType = 'birthday_condition';
            //         } elseif (str_contains($label, 'business hour')) {
            //             $conditionType = 'business_hours_condition';
            //         }

            //         Log::info("[WorkflowEngine] Condition resolved to [{$conditionType}] for node [{$node['id']}] (field: [{$configField}], label: [{$label}])");

            //         $handler = $this->conditionRegistry->make($conditionType);
            //         $result = $handler->evaluate($context, $node['config'] ?? []);

            //         $durationMs = (int)((microtime(true) - $startTime) * 1000);
            //         $resultStr = $result ? 'true' : 'false';

            //         // Log condition step success
            //         AutomationLog::create([
            //             'execution_id' => $execution->id,
            //             'node_id' => $node['id'],
            //             'step_name' => $node['label'],
            //             'status' => 'success',
            //             'message' => "Condition evaluated to: {$resultStr}.",
            //             'execution_time_ms' => $durationMs
            //         ]);

            //         // Branch routing: find connection matching result handle
            //         $nextNodeId = $this->getNextNodeForHandle($connections, $currentNodeId, $resultStr);
            //         if (!$nextNodeId) {
            //             // End flow if branch doesn't connect anywhere
            //             break;
            //         }
            //         $currentNodeId = $nextNodeId;
            //         continue;
            //     } catch (\Exception $e) {
            //         $durationMs = (int)((microtime(true) - $startTime) * 1000);
            //         AutomationLog::create([
            //             'execution_id' => $execution->id,
            //             'node_id' => $node['id'],
            //             'step_name' => $node['label'],
            //             'status' => 'failed',
            //             'message' => 'Condition evaluation crashed.',
            //             'errors' => $e->getMessage(),
            //             'execution_time_ms' => $durationMs
            //         ]);
            //         $execution->update([
            //             'status' => 'failed',
            //             'last_error' => 'Condition crashed: ' . $e->getMessage(),
            //             'finished_at' => Carbon::now()
            //         ]);
            //         return;
            //     }
            // }

            // B. Condition Nodes
if ($nodeTypeClass === 'condition') {
    try {

        $config = $node['config'] ?? [];

        $conditionType = 'condition';

        $configField = strtolower($config['field'] ?? '');
        $operator    = strtolower($config['operator'] ?? '');
        $value       = $config['value'] ?? '';
        $label       = strtolower($node['label'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Resolve Condition Type
        |--------------------------------------------------------------------------
        */

        switch ($configField) {

            case 'message':
            case 'inbound_message':
            case 'inbound_message_body':
                $conditionType = 'keyword_condition';
                break;

            case 'contact_tag':
                $conditionType = 'tag_condition';
                break;

            case 'country':
                $conditionType = 'country_condition';
                break;

            case 'language':
                $conditionType = 'language_condition';
                break;

            case 'birthday':
                $conditionType = 'birthday_condition';
                break;

            case 'business_hours':
                $conditionType = 'business_hours_condition';
                break;

            case 'time':
                $conditionType = 'time_condition';
                break;

            default:

                /*
                 * Legacy workflows don't save "field".
                 * If operator + value exist, assume keyword condition.
                 */

                if (
                    empty($configField) &&
                    !empty($operator) &&
                    array_key_exists('value', $config)
                ) {
                    $conditionType = 'keyword_condition';
                }

                /*
                 * Old workflow label fallback
                 */

                elseif (
                    str_contains($label, 'keyword') ||
                    str_contains($label, 'message') ||
                    str_contains($label, 'price')
                ) {
                    $conditionType = 'keyword_condition';
                }

                elseif (str_contains($label, 'tag')) {
                    $conditionType = 'tag_condition';
                }

                elseif (str_contains($label, 'country')) {
                    $conditionType = 'country_condition';
                }

                elseif (str_contains($label, 'language')) {
                    $conditionType = 'language_condition';
                }

                elseif (str_contains($label, 'birthday')) {
                    $conditionType = 'birthday_condition';
                }

                elseif (str_contains($label, 'business')) {
                    $conditionType = 'business_hours_condition';
                }

                elseif (str_contains($label, 'time')) {
                    $conditionType = 'time_condition';
                }

                break;
        }

        $message = $context->getMessage();

        Log::info('[WorkflowEngine] Condition Configuration', [
            'node' => $node['id'],
            'label' => $label,
            'type' => $conditionType,
            'config' => $config,
            'incoming_message' => $message?->body,
        ]);

        $handler = $this->conditionRegistry->make($conditionType);

        $result = $handler->evaluate($context, $config);

        Log::info('[WorkflowEngine] Condition Result', [
            'node' => $node['id'],
            'result' => $result ? 'true' : 'false'
        ]);

        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        AutomationLog::create([
            'execution_id' => $execution->id,
            'node_id' => $node['id'],
            'step_name' => $node['label'],
            'status' => 'success',
            'message' => 'Condition evaluated to '.($result ? 'TRUE' : 'FALSE'),
            'execution_time_ms' => $durationMs,
        ]);

        if ($result) {
    $nextNodeId = $this->getNextNodeForHandle(
        $connections,
        $currentNodeId,
        'bottom'
    );
} else {
    $nextNodeId = null;
}

        Log::info('[WorkflowEngine] Next Node', [
            'current' => $currentNodeId,
            'next' => $nextNodeId,
            'branch' => $result ? 'true' : 'false'
        ]);

        if (!$nextNodeId) {

            Log::warning('[WorkflowEngine] No branch found', [
                'node' => $currentNodeId,
                'branch' => $result ? 'true' : 'false'
            ]);

            break;
        }

        $currentNodeId = $nextNodeId;

        continue;

    } catch (\Throwable $e) {

        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        AutomationLog::create([
            'execution_id' => $execution->id,
            'node_id' => $node['id'],
            'step_name' => $node['label'],
            'status' => 'failed',
            'message' => 'Condition evaluation failed.',
            'errors' => $e->getMessage(),
            'execution_time_ms' => $durationMs,
        ]);

        $execution->update([
            'status' => 'failed',
            'last_error' => $e->getMessage(),
            'finished_at' => now(),
        ]);

        throw $e;
    }
}

            // C. Action / Delay Nodes
            try {
                // If action node label/type is delay or wait
                $actionType = $nodeTypeClass;
                $label = strtolower($node['label']);
                if ($actionType === 'delay' || $actionType === 'wait' || str_contains($label, 'wait') || str_contains($label, 'delay')) {
                    $actionType = 'wait';
                }

                // Only fall back to label-based mapping when the type is the generic 'action' from old seeders.
                // If the type is already a specific registered action (e.g. 'send_message', 'send_template'),
                // use it directly — do NOT override based on label text.
                if ($actionType === 'action') {
                    if (str_contains($label, 'send message') || str_contains($label, 'welcome text')) {
                        $actionType = 'send_message';
                    } elseif (str_contains($label, 'send template') || str_contains($label, 'template')) {
                        $actionType = 'send_template';
                    } elseif (str_contains($label, 'assign')) {
                        $actionType = 'assign_agent';
                    } elseif (str_contains($label, 'add tag')) {
                        $actionType = 'add_tag';
                    } elseif (str_contains($label, 'remove tag')) {
                        $actionType = 'remove_tag';
                    } elseif (str_contains($label, 'webhook')) {
                        $actionType = 'webhook';
                    } elseif (str_contains($label, 'end')) {
                        $actionType = 'end_workflow';
                    }
                }

                Log::info("[WorkflowEngine] Resolved action type [{$actionType}] for node [{$node['id']}] (original type: [{$nodeTypeClass}], label: [{$node['label']}])");

                $handler = $this->actionRegistry->make($actionType);
                $response = $handler->execute($context, $node['config'] ?? []);

                $durationMs = (int)((microtime(true) - $startTime) * 1000);

                if ($response->status === 'success') {
                    AutomationLog::create([
                        'execution_id' => $execution->id,
                        'node_id' => $node['id'],
                        'step_name' => $node['label'],
                        'status' => 'success',
                        'message' => $response->message ?: 'Action completed successfully.',
                        'execution_time_ms' => $durationMs
                    ]);

                    // Check if node terminates the flow (e.g. End Workflow)
                    if ($actionType === 'end_workflow' || str_contains($label, 'end workflow') || str_contains($label, 'end campaign')) {
                        break;
                    }

                    $currentNodeId = $this->getNextNodeForHandle($connections, $currentNodeId, 'bottom');
                } elseif ($response->status === 'wait') {
                    // Halt execution and schedule resume
                    $nextNodeId = $this->getNextNodeForHandle($connections, $currentNodeId, 'bottom');
                    
                    AutomationLog::create([
                        'execution_id' => $execution->id,
                        'node_id' => $node['id'],
                        'step_name' => $node['label'],
                        'status' => 'success',
                        'message' => $response->message ?: 'Workflow paused.',
                        'execution_time_ms' => $durationMs
                    ]);

                    $execution->update([
                        'status' => 'paused',
                        'current_node_id' => $nextNodeId,
                        'resume_at' => $response->resumeTime
                    ]);

                    // Dispatch delayed queue job to resume
                    ExecuteWorkflowJob::dispatch(
                        $workflow->id,
                        $contact->id,
                        $nextNodeId,
                        $execution->id
                    )->delay($response->resumeTime);

                    return; // Pause execution loop
                } else {
                    // Failed action status - check retry configuration
                    $maxRetries = (int)($node['config']['retry_count'] ?? ($node['config']['retries'] ?? 0));
                    if ($execution->retry_count < $maxRetries) {
                        $execution->increment('retry_count');
                        $backoffMin = (int)($node['config']['backoff_minutes'] ?? 2);

                        AutomationLog::create([
                            'execution_id' => $execution->id,
                            'node_id' => $node['id'],
                            'step_name' => $node['label'],
                            'status' => 'failed',
                            'message' => "Action failed: {$response->error}. Scheduling retry #{$execution->retry_count} in {$backoffMin} minutes.",
                            'errors' => $response->error,
                            'execution_time_ms' => $durationMs,
                            'retry_count' => $execution->retry_count
                        ]);

                        // Re-queue with delay
                        ExecuteWorkflowJob::dispatch(
                            $workflow->id,
                            $contact->id,
                            $currentNodeId, // retry the SAME node!
                            $execution->id
                        )->delay(Carbon::now()->addMinutes($backoffMin));

                        return; // Halt current job run
                    }

                    // No retries left or not configured
                    AutomationLog::create([
                        'execution_id' => $execution->id,
                        'node_id' => $node['id'],
                        'step_name' => $node['label'],
                        'status' => 'failed',
                        'message' => "Action failed: {$response->error}. (Retries exhausted/unconfigured)",
                        'errors' => $response->error,
                        'execution_time_ms' => $durationMs
                    ]);

                    $execution->update([
                        'status' => 'failed',
                        'last_error' => $response->error,
                        'finished_at' => Carbon::now()
                    ]);
                    return;
                }
            } catch (\Exception $ex) {
                $durationMs = (int)((microtime(true) - $startTime) * 1000);
                AutomationLog::create([
                    'execution_id' => $execution->id,
                    'node_id' => $node['id'],
                    'step_name' => $node['label'],
                    'status' => 'failed',
                    'message' => 'Action crashed unexpectedly.',
                    'errors' => $ex->getMessage(),
                    'execution_time_ms' => $durationMs
                ]);
                $execution->update([
                    'status' => 'failed',
                    'last_error' => 'Action crash: ' . $ex->getMessage(),
                    'finished_at' => Carbon::now()
                ]);
                return;
            }
        }

        // Execution loop completed successfully
        $execution->update([
            'status' => 'completed',
            'finished_at' => Carbon::now(),
            'current_node_id' => null
        ]);

        Log::info("[WorkflowEngine] Completed execution [{$execution->id}] successfully.");
    }

    /**
     * Find target node ID connected to a source handle (bottom, true, false).
     */
protected function getNextNodeForHandle(
    array $connections,
    string $sourceNodeId,
    ?string $handle,
    bool $strict = false
): ?string {

    $outgoing = [];

    foreach ($connections as $conn) {

        $source = $conn['source_node_id']
            ?? $conn['source']
            ?? null;

        if ($source !== $sourceNodeId) {
            continue;
        }

        $outgoing[] = $conn;

        $connHandle = strtolower(
            (string)(
                $conn['source_handle']
                ?? $conn['sourceHandle']
                ?? ''
            )
        );

        if (
            $handle !== null &&
            $connHandle === strtolower($handle)
        ) {
            return $conn['target_node_id']
                ?? $conn['target']
                ?? null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | STRICT MODE
    | Used by Condition nodes.
    |--------------------------------------------------------------------------
    */

    if ($strict) {

        Log::info('[WorkflowEngine] No matching branch', [
            'source' => $sourceNodeId,
            'requested' => $handle,
        ]);

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    */

    foreach ($outgoing as $conn) {

        $connHandle = strtolower(
            (string)(
                $conn['source_handle']
                ?? $conn['sourceHandle']
                ?? ''
            )
        );

        if (
            $connHandle === '' ||
            $connHandle === 'bottom'
        ) {
            return $conn['target_node_id']
                ?? $conn['target']
                ?? null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Legacy workflows
    |--------------------------------------------------------------------------
    */

    if (count($outgoing) === 1) {
        return $outgoing[0]['target_node_id']
            ?? $outgoing[0]['target']
            ?? null;
    }

    return null;
}
}
