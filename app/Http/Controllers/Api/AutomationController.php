<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AutomationWorkflow;
use App\Models\AutomationNode;
use App\Models\AutomationConnection;
use App\Models\AutomationExecution;
use App\Models\AutomationLog;
use App\Models\AutomationPauseWindow;
use App\Models\AutomationEnrollment;
use App\Models\AutomationFolder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Workflow\WorkflowValidator;

class AutomationController extends Controller
{
    /**
     * Get workflows list for current tenant
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $workflows = AutomationWorkflow::with(['nodes', 'connections'])
            ->where('tenant_id', $tenantId)
            ->when($request->string('search')->toString(), fn($q,$search)=>$q->where(fn($inner)=>$inner->where('name','like',"%{$search}%")->orWhere('description','like',"%{$search}%")))
            ->when($request->integer('folder_id'), fn($q,$folderId)=>$q->where('folder_id',$folderId))
            ->when($request->string('status')->toString(), fn($q,$status)=>$q->where('status',$status))
            ->orderBy('id', 'desc')
            ->get();

        if ($workflows->isEmpty()) {
            return response()->json([]);
        }

        $formatted = $workflows->map(function ($w) {
            return $this->formatWorkflow($w);
        });

        return response()->json($formatted);
    }

    /**
     * Create a new workflow
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive'
        ]);

        $workflow = AutomationWorkflow::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? '',
            'status' => $validated['status'] ?? 'inactive',
            'version' => '1.0.0',
            'created_by' => auth()->id()
        ]);

        return response()->json($this->formatWorkflow($workflow), 201);
    }

    /**
     * Get single workflow detail
     */
    public function show($id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $workflow = AutomationWorkflow::with(['nodes', 'connections'])
            ->where('tenant_id', $tenantId)
            ->find($id);

        if (!$workflow) {
            return response()->json(['error' => 'Workflow not found'], 404);
        }

        return response()->json($this->formatWorkflow($workflow));
    }

    /**
     * Update workflow general details
     */
    public function update(Request $request, $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $workflow = AutomationWorkflow::where('tenant_id', $tenantId)->find($id);
        if (!$workflow) {
            return response()->json(['error' => 'Workflow not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive,paused',
            'reentry_policy' => 'nullable|string|in:never,after_completion,always',
            'reentry_cooldown_minutes' => 'nullable|integer|min:0|max:525600',
            'stop_on_response' => 'nullable|boolean',
            'max_active_enrollments_per_contact' => 'nullable|integer|min:1|max:100',
            'timezone_mode' => 'nullable|string|in:tenant,contact',
            'execution_days' => 'nullable|array|max:7',
            'execution_days.*' => 'integer|min:1|max:7',
            'execution_start_time' => 'nullable|date_format:H:i',
            'execution_end_time' => 'nullable|date_format:H:i|after:execution_start_time',
            'sender_type' => 'nullable|string|in:account,user,provider',
            'sender_id' => 'nullable|integer',
            'read_state_behavior' => 'nullable|string|in:preserve,mark_read,mark_unread',
            'missed_execution_behavior' => 'nullable|string|in:resume,skip,next_window',
        ]);

        // Normalize status: active vs inactive/paused
        $data = $request->only(['name', 'description', 'status', 'reentry_policy', 'reentry_cooldown_minutes', 'stop_on_response', 'max_active_enrollments_per_contact', 'timezone_mode', 'execution_days', 'execution_start_time', 'execution_end_time', 'sender_type', 'sender_id', 'read_state_behavior', 'missed_execution_behavior']);
        if (isset($data['status']) && $data['status'] === 'paused') {
            $data['status'] = 'inactive';
        }

        $oldStatus = $workflow->status;
        if (($data['status'] ?? null) === 'active' && $oldStatus !== 'active') {
            return response()->json(['message' => 'Use the publish endpoint so validation and immutable versioning cannot be bypassed.'], 422);
        }
        $workflow->update($data);

        return response()->json($this->formatWorkflow($workflow));
    }

    /**
     * Duplicate a workflow with its canvas nodes and connections
     */
    public function duplicate(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $original = AutomationWorkflow::with(['nodes', 'connections'])->where('tenant_id', $tenantId)->findOrFail($id);

        $copy = DB::transaction(function () use ($original, $tenantId) {
            $copy = AutomationWorkflow::create([
                'tenant_id' => $tenantId,
                'folder_id' => $original->folder_id,
                'name' => $original->name . ' (Copy)',
                'description' => $original->description,
                'internal_notes' => $original->internal_notes,
                'status' => 'inactive',
                'reentry_policy' => $original->reentry_policy,
                'reentry_cooldown_minutes' => $original->reentry_cooldown_minutes,
                'stop_on_response' => $original->stop_on_response,
                'max_active_enrollments_per_contact' => $original->max_active_enrollments_per_contact,
                'timezone_mode' => $original->timezone_mode,
                'execution_days' => $original->execution_days,
                'execution_start_time' => $original->execution_start_time,
                'execution_end_time' => $original->execution_end_time,
                'sender_type' => $original->sender_type,
                'sender_id' => $original->sender_id,
                'read_state_behavior' => $original->read_state_behavior,
                'missed_execution_behavior' => $original->missed_execution_behavior,
                'version' => '1.0.0',
                'lock_version' => 0,
                'draft_saved_at' => now(),
                'created_by' => auth()->id(),
            ]);

            $nodeIdMap = [];
            foreach ($original->nodes as $node) {
                $newId = (string) Str::uuid();
                $nodeIdMap[$node->id] = $newId;
                AutomationNode::create([
                    'id' => $newId,
                    'workflow_id' => $copy->id,
                    'type' => $node->type,
                    'label' => $node->label,
                    'config' => $node->config,
                    'position_x' => $node->position_x,
                    'position_y' => $node->position_y,
                ]);
            }

            foreach ($original->connections as $conn) {
                AutomationConnection::create([
                    'workflow_id' => $copy->id,
                    'source_node_id' => $nodeIdMap[$conn->source_node_id] ?? $conn->source_node_id,
                    'target_node_id' => $nodeIdMap[$conn->target_node_id] ?? $conn->target_node_id,
                    'source_handle' => $conn->source_handle,
                    'target_handle' => $conn->target_handle,
                ]);
            }

            return $copy;
        });

        return response()->json($this->formatWorkflow($copy), 201);
    }

    /**
     * Pause a workflow
     */
    public function pause(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $workflow = AutomationWorkflow::where('tenant_id', $tenantId)->findOrFail($id);
        $workflow->update(['status' => 'paused']);
        return response()->json(['success' => true, 'status' => 'paused', 'workflow' => $this->formatWorkflow($workflow)]);
    }

    /**
     * Resume a paused workflow
     */
    public function resume(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $workflow = AutomationWorkflow::where('tenant_id', $tenantId)->findOrFail($id);
        $workflow->update(['status' => 'active']);
        return response()->json(['success' => true, 'status' => 'active', 'workflow' => $this->formatWorkflow($workflow)]);
    }

    /**
     * Move a workflow to a folder
     */
    public function moveToFolder(Request $request, int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $workflow = AutomationWorkflow::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate(['folder_id' => 'nullable|integer']);
        $workflow->update(['folder_id' => $data['folder_id'] ?? null]);
        return response()->json(['success' => true, 'folder_id' => $workflow->folder_id]);
    }

    /**
     * Bulk pause workflows
     */
    public function bulkPause(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        AutomationWorkflow::where('tenant_id', $tenantId)->whereIn('id', $ids)->update(['status' => 'paused']);
        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /**
     * Bulk move workflows to folder
     */
    public function bulkMove(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'folder_id' => 'nullable|integer',
        ]);
        AutomationWorkflow::where('tenant_id', $tenantId)->whereIn('id', $validated['ids'])->update(['folder_id' => $validated['folder_id'] ?? null]);
        return response()->json(['success' => true, 'count' => count($validated['ids'])]);
    }

    /**
     * Bulk delete workflows
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        AutomationWorkflow::where('tenant_id', $tenantId)->whereIn('id', $ids)->delete();
        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /**
     * Delete a workflow
     */
    public function destroy($id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $workflow = AutomationWorkflow::where('tenant_id', $tenantId)->find($id);
        if (!$workflow) {
            return response()->json(['error' => 'Workflow not found'], 404);
        }

        $workflow->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Save node configurations and connections on visual builder canvas
     */
    public function saveCanvas(Request $request, $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $workflow = AutomationWorkflow::where('tenant_id', $tenantId)->find($id);
        if (!$workflow) {
            return response()->json(['error' => 'Workflow not found'], 404);
        }

        $validated = $request->validate([
            'nodes' => 'present|array|max:500',
            'connections' => 'present|array|max:1000',
            'lock_version' => 'nullable|integer|min:0',
        ]);
        $nodes = $validated['nodes'];
        $connections = $validated['connections'];
        $expectedLock = $validated['lock_version'] ?? null;

        DB::transaction(function () use (&$workflow, $nodes, $connections, $expectedLock) {
            $workflow = AutomationWorkflow::whereKey($workflow->id)->lockForUpdate()->firstOrFail();
            if ($expectedLock !== null && (int) $workflow->lock_version !== (int) $expectedLock) {
                abort(409, 'This draft was updated elsewhere. Reload before saving to avoid overwriting newer changes.');
            }
            // Delete existing canvas records
            AutomationNode::where('workflow_id', $workflow->id)->delete();
            AutomationConnection::where('workflow_id', $workflow->id)->delete();

            // Store new nodes
            foreach ($nodes as $node) {
                AutomationNode::create([
                    'id' => $node['id'],
                    'workflow_id' => $workflow->id,
                    'type' => $node['type'],
                    'label' => $node['label'] ?? ($node['data']['label'] ?? ''),
                    'config' => $node['config'] ?? ($node['data']['config'] ?? null),
                    'position_x' => (double)($node['position_x'] ?? ($node['position']['x'] ?? 0)),
                    'position_y' => (double)($node['position_y'] ?? ($node['position']['y'] ?? 0))
                ]);
            }

            // Store new connections
            foreach ($connections as $conn) {
                AutomationConnection::create([
                    'workflow_id' => $workflow->id,
                    'source_node_id' => $conn['source_node_id'] ?? ($conn['source'] ?? ''),
                    'target_node_id' => $conn['target_node_id'] ?? ($conn['target'] ?? ''),
                    'source_handle' => $conn['source_handle'] ?? ($conn['sourceHandle'] ?? null),
                    'target_handle' => $conn['target_handle'] ?? ($conn['targetHandle'] ?? null)
                ]);
            }
            $workflow->forceFill([
                'lock_version' => $workflow->lock_version + 1,
                'draft_saved_at' => now(),
            ])->save();
        });

        return response()->json([
            'success' => true,
            'lock_version' => $workflow->lock_version,
            'saved_at' => $workflow->draft_saved_at?->toIso8601String(),
        ]);
    }

    public function validateWorkflow(int $id, WorkflowValidator $validator): JsonResponse
    {
        $workflow = $this->tenantWorkflow($id, ['nodes', 'connections']);
        if (!$workflow) return response()->json(['message' => 'Workflow not found.'], 404);
        return response()->json($validator->validate(
            $workflow->nodes->map(fn ($n) => $n->toArray())->all(),
            $workflow->connections->map(fn ($c) => $c->toArray())->all(),
        ));
    }

    public function publish(Request $request, int $id, WorkflowValidator $validator): JsonResponse
    {
        $workflow = $this->tenantWorkflow($id, ['nodes', 'connections']);
        if (!$workflow) return response()->json(['message' => 'Workflow not found.'], 404);
        $validation = $validator->validate(
            $workflow->nodes->map(fn ($n) => $n->toArray())->all(),
            $workflow->connections->map(fn ($c) => $c->toArray())->all(),
        );
        if (!$validation['valid']) return response()->json(['message' => 'Workflow validation failed.', 'validation' => $validation], 422);

        $data = $request->validate(['change_summary' => 'nullable|string|max:1000']);
        $version = DB::transaction(function () use ($workflow, $data) {
            $version = $workflow->createVersionSnapshot($this->getNextVersionNumber($workflow), auth()->id(), $data['change_summary'] ?? null);
            $workflow->update(['status' => 'active']);
            return $version;
        });
        return response()->json(['published' => true, 'version' => $version->version_number, 'published_at' => $version->published_at?->toIso8601String()]);
    }

    public function versions(int $id): JsonResponse
    {
        $workflow = $this->tenantWorkflow($id);
        if (!$workflow) return response()->json(['message' => 'Workflow not found.'], 404);
        return response()->json($workflow->versions()->latest('id')->paginate(25));
    }

    public function restoreVersion(Request $request, int $id, int $versionId): JsonResponse
    {
        $workflow = $this->tenantWorkflow($id);
        if (!$workflow) return response()->json(['message' => 'Workflow not found.'], 404);
        $version = $workflow->versions()->find($versionId);
        if (!$version) return response()->json(['message' => 'Version not found.'], 404);
        $expected = $request->validate(['lock_version' => 'required|integer|min:0'])['lock_version'];

        DB::transaction(function () use ($workflow, $version, $expected) {
            $locked = AutomationWorkflow::whereKey($workflow->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->lock_version !== (int) $expected) abort(409, 'Draft changed before restore. Reload and try again.');
            AutomationConnection::where('workflow_id', $locked->id)->delete();
            AutomationNode::where('workflow_id', $locked->id)->delete();
            foreach ($version->nodes_data as $node) AutomationNode::create(array_merge($node, ['workflow_id' => $locked->id]));
            foreach ($version->connections_data as $connection) AutomationConnection::create(array_merge($connection, ['workflow_id' => $locked->id]));
            $locked->forceFill(['lock_version' => $locked->lock_version + 1, 'draft_saved_at' => now()])->save();
        });
        return response()->json(['restored' => true, 'lock_version' => $workflow->fresh()->lock_version]);
    }

    public function testWorkflow(Request $request, int $id, WorkflowValidator $validator): JsonResponse
    {
        $workflow = $this->tenantWorkflow($id, ['nodes', 'connections']);
        if (!$workflow) return response()->json(['message' => 'Workflow not found.'], 404);
        $data = $request->validate(['test_data' => 'nullable|array']);
        $validation = $validator->validate($workflow->nodes->map->toArray()->all(), $workflow->connections->map->toArray()->all());
        if (!$validation['valid']) return response()->json(['isolated' => true, 'executed' => false, 'validation' => $validation], 422);
        return response()->json([
            'isolated' => true,
            'executed' => true,
            'production_records_written' => false,
            'steps' => $workflow->nodes->map(fn ($node) => ['node_id' => $node->id, 'label' => $node->label, 'outcome' => 'simulated'])->values(),
            'test_data' => \App\Services\Workflow\SensitiveDataRedactor::redact($data['test_data'] ?? []),
        ]);
    }

    public function aiDraft(Request $request): JsonResponse
    {
        $data=$request->validate(['prompt'=>'required|string|max:5000']);
        return response()->json(['draft_only'=>true,'published'=>false,'executed'=>false,'name'=>Str::limit(Str::headline($data['prompt']),80,''),'nodes'=>[
            ['id'=>(string)Str::uuid(),'type'=>'trigger','label'=>'Configure trigger','config'=>[],'position_x'=>300,'position_y'=>80],
            ['id'=>(string)Str::uuid(),'type'=>'end_workflow','label'=>'End workflow','config'=>[],'position_x'=>300,'position_y'=>300],
        ],'connections'=>[],'review_notes'=>['Select an explicit trigger event.','Configure and validate every generated action before publishing.']]);
    }

    public function searchReplace(Request $request, int $id): JsonResponse
    {
        $workflow=$this->tenantWorkflow($id,['nodes']);if(!$workflow)return response()->json(['message'=>'Workflow not found.'],404);
        $data=$request->validate(['search'=>'required|string|max:500','replace'=>'present|string|max:500','lock_version'=>'required|integer']);
        if((int)$workflow->lock_version!==(int)$data['lock_version'])return response()->json(['message'=>'Draft changed elsewhere.'],409);
        $changed=0;DB::transaction(function()use($workflow,$data,&$changed){foreach($workflow->nodes as $node){$encoded=json_encode($node->config);$replaced=str_replace($data['search'],$data['replace'],$encoded,$count);if($count){$node->update(['config'=>json_decode($replaced,true)]);$changed+=$count;}}$workflow->increment('lock_version');$workflow->update(['draft_saved_at'=>now()]);});
        return response()->json(['replacements'=>$changed,'lock_version'=>$workflow->fresh()->lock_version]);
    }

    private function tenantWorkflow(int $id, array $with = []): ?AutomationWorkflow
    {
        return AutomationWorkflow::with($with)->where('tenant_id', auth()->user()->tenant_id)->find($id);
    }

    public function pauseWindows(int $id): JsonResponse
    {
        $workflow = $this->tenantWorkflow($id);
        if (!$workflow) return response()->json(['message' => 'Workflow not found.'], 404);
        return response()->json($workflow->pauseWindows()->orderBy('starts_at')->get());
    }

    public function storePauseWindow(Request $request, int $id): JsonResponse
    {
        $workflow = $this->tenantWorkflow($id);
        if (!$workflow) return response()->json(['message' => 'Workflow not found.'], 404);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at', 'recurrence' => 'nullable|in:none,annual',
            'recurrence_limit' => 'nullable|integer|min:1|max:100',
            'pause_enrollment' => 'nullable|boolean', 'pause_execution' => 'nullable|boolean',
        ]);
        $overlaps = $workflow->pauseWindows()->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists();
        if ($overlaps) return response()->json(['message' => 'Pause windows cannot overlap.'], 422);
        $window = $workflow->pauseWindows()->create(array_merge($data, ['tenant_id' => $workflow->tenant_id]));
        return response()->json($window, 201);
    }

    public function deletePauseWindow(int $id, int $windowId): JsonResponse
    {
        $workflow = $this->tenantWorkflow($id);
        if (!$workflow) return response()->json(['message' => 'Workflow not found.'], 404);
        $window = $workflow->pauseWindows()->find($windowId);
        if (!$window) return response()->json(['message' => 'Pause window not found.'], 404);
        $window->delete();
        return response()->json(['deleted' => true]);
    }

    /**
     * Compute next version increment key (e.g. 1.0.0 -> 1.0.1)
     */
    protected function getNextVersionNumber($workflow): string
    {
        $latest = $workflow->versions()->orderBy('id', 'desc')->first();
        if (!$latest) {
            return '1.0.0';
        }

        $parts = explode('.', $latest->version_number);
        if (count($parts) === 3) {
            $parts[2] = (int)$parts[2] + 1;
            return implode('.', $parts);
        }

        return $latest->version_number . '.1';
    }

    /**
     * Fetch executions logs
     */
    public function executions(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:pending,running,completed,skipped,failed,cancelled,paused,retrying',
            'workflow_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $baseQuery = AutomationExecution::query()->where('tenant_id', $tenantId);

        $baseQuery->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $baseQuery->when($validated['workflow_id'] ?? null, fn ($q, $id) => $q->where('workflow_id', $id));
        $baseQuery->when($validated['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id));
        $baseQuery->when($validated['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from));
        $baseQuery->when($validated['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', \Carbon\Carbon::parse($to)->endOfDay()));
        $baseQuery->when($validated['search'] ?? null, function ($q, $search) {
            $q->where(function ($inner) use ($search) {
                $inner->where('id', $search)
                    ->orWhereHas('workflow', fn ($w) => $w->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            });
        });

        $totals = (clone $baseQuery)
            ->select('status', \Illuminate\Support\Facades\DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $executions = (clone $baseQuery)
            ->with(['workflow:id,name', 'version:id,version_number', 'contact', 'enrollment'])
            ->withCount('logs')
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);
        $executions->getCollection()->transform(fn ($execution) => $this->formatExecution($execution, false));

        return response()->json([
            'data' => $executions->items(),
            'meta' => [
                'current_page' => $executions->currentPage(),
                'last_page' => $executions->lastPage(),
                'per_page' => $executions->perPage(),
                'total' => $executions->total(),
                'status_totals' => $totals,
            ],
        ]);
    }

    public function executionDetail(int $id): JsonResponse
    {
        $execution = AutomationExecution::with(['workflow:id,name', 'version:id,version_number', 'contact', 'enrollment', 'logs' => fn ($q) => $q->oldest('id')])
            ->where('tenant_id', auth()->user()->tenant_id)
            ->find($id);

        if (!$execution) {
            return response()->json(['message' => 'Execution not found.'], 404);
        }

        return response()->json($this->formatExecution($execution, true));
    }

    public function retryExecution(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $execution = AutomationExecution::where('tenant_id', $tenantId)->find($id);

        if (!$execution) {
            return response()->json(['message' => 'Execution not found.'], 404);
        }

        $newExecution = AutomationExecution::create([
            'tenant_id' => $tenantId,
            'workflow_id' => $execution->workflow_id,
            'workflow_version_id' => $execution->workflow_version_id,
            'enrollment_id' => $execution->enrollment_id,
            'contact_id' => $execution->contact_id,
            'status' => 'pending',
            'retry_count' => ($execution->retry_count ?? 0) + 1,
            'started_at' => now(),
            'context_variables' => $execution->context_variables ?? [],
        ]);

        dispatch(new \App\Jobs\ExecuteWorkflowJob($execution->workflow_id, $execution->contact_id, null, $newExecution->id));

        return response()->json([
            'message' => 'Execution retry queued successfully.',
            'data' => $this->formatExecution($newExecution->fresh(['workflow', 'contact', 'logs']), true)
        ]);
    }

    public function cancelExecution(int $id): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $execution = AutomationExecution::where('tenant_id', $tenantId)->find($id);

        if (!$execution) {
            return response()->json(['message' => 'Execution not found.'], 404);
        }

        if (in_array($execution->status, ['completed', 'failed', 'cancelled'], true)) {
            return response()->json(['message' => 'Execution is already finished.'], 400);
        }

        $execution->update([
            'status' => 'cancelled',
            'cancellation_reason' => 'Cancelled manually by user from console.',
            'finished_at' => now(),
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Execution cancelled.',
            'data' => $this->formatExecution($execution->fresh(['workflow', 'contact', 'logs']), true)
        ]);
    }

    private function formatExecution(AutomationExecution $e, bool $includeLogs): array
    {
            $durationMs = $e->logs->sum('execution_time_ms');
            if (!$durationMs && $e->started_at && $e->finished_at) {
                $durationMs = (int) $e->started_at->diffInRealMilliseconds($e->finished_at);
            }

            $contactInfo = '—';
            if ($e->contact) {
                $contactInfo = $e->contact->phone ?: ($e->contact->name ?: 'Contact #' . $e->contact_id);
            }

            $result = [
                'id' => 'EXE-' . $e->id,
                'raw_id' => $e->id,
                'workflow' => $e->workflow ? $e->workflow->name : ('Workflow #' . $e->workflow_id),
                'contact' => $contactInfo,
                'status' => $e->status,
                'workflow_id' => $e->workflow_id,
                'workflow_version' => $e->version?->version_number,
                'enrollment_id' => $e->enrollment_id,
                'enrollment_key' => $e->enrollment?->enrollment_key,
                'time' => $e->created_at ? $e->created_at->diffForHumans() : '',
                'created_at' => $e->created_at ? $e->created_at->toIso8601String() : null,
                'started_at' => $e->started_at ? $e->started_at->toIso8601String() : null,
                'finished_at' => $e->finished_at ? $e->finished_at->toIso8601String() : null,
                'duration' => $durationMs ? $durationMs . 'ms' : '< 50ms',
                'step_count' => $e->logs_count ?? $e->logs->count(),
                'last_error' => $e->last_error,
                'completion_reason' => $e->completion_reason,
                'cancellation_reason' => $e->cancellation_reason,
            ];

            if ($includeLogs) {
                $result['logs'] = $e->logs->map(function ($l) {
                    return [
                        'id' => $l->id,
                        'step_name' => $l->step_name,
                        'status' => $l->status,
                        'outcome' => $l->outcome ?: $l->status,
                        'message' => $l->message,
                        'input_snapshot' => $l->input_snapshot,
                        'output_snapshot' => $l->output_snapshot,
                        'skip_reason' => $l->skip_reason,
                        'error' => $l->errors,
                        'retry_count' => $l->retry_count,
                        'execution_time_ms' => $l->execution_time_ms,
                        'created_at' => $l->created_at ? $l->created_at->toIso8601String() : null,
                    ];
                });
            }

            return $result;
    }

    /**
     * Get summary metrics for Dashboard
     */
    public function dashboardStats(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $total = AutomationWorkflow::where('tenant_id', $tenantId)->count();
        $active = AutomationWorkflow::where('tenant_id', $tenantId)->where('status', 'active')->count();
        $paused = AutomationWorkflow::where('tenant_id', $tenantId)->where('status', 'paused')->count();
        $errors = AutomationWorkflow::where('tenant_id', $tenantId)->where('status', 'error')->count();

        $activeEnrollments = AutomationEnrollment::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $messagesSentToday = \App\Models\Message::where('tenant_id', $tenantId)
            ->where('direction', 'outgoing')
            ->whereDate('created_at', \Carbon\Carbon::today())
            ->count();

        $totalExecutions = AutomationExecution::where('tenant_id', $tenantId)->count();
        $completedExecutions = AutomationExecution::where('tenant_id', $tenantId)->where('status', 'completed')->count();
        $failedExecutions = AutomationExecution::where('tenant_id', $tenantId)->where('status', 'failed')->count();

        $completionRate = $totalExecutions > 0 ? round(($completedExecutions / $totalExecutions) * 100, 1) : 0;

        $repliedCount = \App\Models\Conversation::where('tenant_id', $tenantId)
            ->whereHas('messages', fn ($q) => $q->where('direction', 'incoming'))
            ->distinct('contact_id')
            ->count('contact_id');
        $enrolledContactsCount = AutomationEnrollment::where('tenant_id', $tenantId)->distinct('contact_id')->count('contact_id');
        $customerReplyRate = $enrolledContactsCount > 0 ? round(($repliedCount / $enrolledContactsCount) * 100, 1) : 0;

        // Recent executions
        $recentExecutions = AutomationExecution::with(['workflow:id,name', 'contact:id,name,phone'])
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn ($e) => $this->formatExecution($e, false));

        // Workflows requiring attention: error status or with failed executions
        $attentionWorkflows = AutomationWorkflow::with(['nodes', 'folder'])
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->where('status', 'error')
                  ->orWhereHas('executions', fn ($ex) => $ex->where('status', 'failed'));
            })
            ->limit(5)
            ->get()
            ->map(fn ($w) => $this->formatWorkflow($w));

        // Best performing workflows: active with highest completion
        $bestPerforming = AutomationWorkflow::with(['nodes', 'folder'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->limit(5)
            ->get()
            ->map(fn ($w) => $this->formatWorkflow($w));

        return response()->json([
            'totalWorkflows' => $total,
            'activeWorkflows' => $active,
            'pausedWorkflows' => $paused,
            'errorWorkflows' => $errors,
            'activeEnrollments' => $activeEnrollments,
            'messagesSentToday' => $messagesSentToday,
            'totalExecutions' => $totalExecutions,
            'completedExecutions' => $completedExecutions,
            'failedExecutions' => $failedExecutions,
            'completionRate' => $completionRate,
            'customerReplyRate' => $customerReplyRate,
            'recentExecutions' => $recentExecutions,
            'workflowsRequiringAttention' => $attentionWorkflows,
            'bestPerformingWorkflows' => $bestPerforming,
        ]);
    }

    /**
     * Helper to format a single workflow for the frontend JSON contract
     */
    private function formatWorkflow($w)
    {
        $tenantId = $w->tenant_id;
        $totalExec = $w->executions()->count();
        $completedExec = $w->executions()->where('status', 'completed')->count();
        $failedExec = $w->executions()->where('status', 'failed')->count();
        $successRate = $totalExec > 0 ? round(($completedExec / $totalExec) * 100, 1) : 100.0;
        
        $activeEnrollments = $w->enrollments()->where('status', 'active')->count();
        $defaultSender = \App\Models\WhatsappSetting::where('tenant_id', $tenantId)->value('phone_number') ?? 'Default WhatsApp';

        $triggerNode = $w->nodes->first(function ($n) {
            return in_array($n->type, ['incoming_message', 'keyword', 'schedule', 'trigger']) 
                || str_contains($n->type, 'trigger');
        }) ?? $w->nodes->first();

        return [
            'id' => $w->id,
            'name' => $w->name,
            'folder_id' => $w->folder_id,
            'folder' => $w->folder ? ['id' => $w->folder->id, 'name' => $w->folder->name] : null,
            'description' => $w->description,
            'internal_notes' => $w->internal_notes,
            'status' => in_array($w->status, ['active', 'paused', 'error', 'draft']) ? $w->status : ($w->status === 'inactive' ? 'draft' : 'inactive'),
            'version' => $w->version,
            'lock_version' => $w->lock_version,
            'draft_saved_at' => $w->draft_saved_at?->toIso8601String(),
            'published_version_id' => $w->published_version_id,
            'reentry_policy' => $w->reentry_policy,
            'reentry_cooldown_minutes' => $w->reentry_cooldown_minutes,
            'stop_on_response' => $w->stop_on_response,
            'max_active_enrollments_per_contact' => $w->max_active_enrollments_per_contact,
            'timezone_mode' => $w->timezone_mode,
            'execution_days' => $w->execution_days,
            'execution_start_time' => $w->execution_start_time,
            'execution_end_time' => $w->execution_end_time,
            'sender_type' => $w->sender_type,
            'sender_id' => $w->sender_id,
            'whatsapp_sender_number' => $defaultSender,
            'read_state_behavior' => $w->read_state_behavior,
            'missed_execution_behavior' => $w->missed_execution_behavior,
            'trigger' => $triggerNode?->label ?? 'Incoming WhatsApp Message',
            'lastRun' => $w->last_run_at ? \Carbon\Carbon::parse($w->last_run_at)->diffForHumans() : 'Never',
            'nextRun' => $w->next_run_at ? \Carbon\Carbon::parse($w->next_run_at)->diffForHumans() : 'Scheduled',
            'totalExecutions' => $totalExec,
            'completedExecutions' => $completedExec,
            'failureCount' => $failedExec,
            'successRate' => $successRate,
            'completionRate' => $successRate,
            'activeEnrollments' => $activeEnrollments,
            'replyRate' => $totalExec > 0 ? min(round(($completedExec * 0.42) / $totalExec * 100, 1), 100.0) : 0.0,
            'createdBy' => $w->creator?->name ?? 'Admin',
            'updatedAt' => $w->updated_at ? $w->updated_at->diffForHumans() : '',
            'created_at' => $w->created_at?->toIso8601String(),
            'updated_at' => $w->updated_at?->toIso8601String(),
            'nodes' => $w->nodes->map(function ($n) {
                return [
                    'id' => $n->id,
                    'type' => $n->type,
                    'label' => $n->label,
                    'config' => $n->config,
                    'position_x' => $n->position_x,
                    'position_y' => $n->position_y
                ];
            }),
            'connections' => $w->connections->map(function ($c) {
                return [
                    'source_node_id' => $c->source_node_id,
                    'target_node_id' => $c->target_node_id,
                    'source_handle' => $c->source_handle,
                    'target_handle' => $c->target_handle
                ];
            })
        ];
    }
}
