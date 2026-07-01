<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends Model
{
    protected $table = 'automation_workflows';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'status',
        'version',
        'created_by',
        'last_run_at',
        'next_run_at'
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(AutomationNode::class, 'workflow_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(AutomationConnection::class, 'workflow_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'workflow_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationWorkflowVersion::class, 'workflow_id');
    }

    /**
     * Get the currently active published version snapshot.
     */
    public function activeVersion()
    {
        return $this->versions()->where('status', 'active')->orderBy('id', 'desc')->first();
    }

    /**
     * Snapshots current visual draft canvas nodes and connections into an immutable version.
     */
    public function createVersionSnapshot(string $versionNumber): AutomationWorkflowVersion
    {
        // Deactivate previous active versions
        $this->versions()->update(['status' => 'deprecated']);

        // Snapshot current nodes
        $nodes = $this->nodes()->get()->map(function ($node) {
            return [
                'id' => $node->id,
                'type' => $node->type,
                'label' => $node->label,
                'config' => $node->config,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y
            ];
        })->toArray();

        // Snapshot current connections
        $connections = $this->connections()->get()->map(function ($conn) {
            return [
                'source_node_id' => $conn->source_node_id,
                'target_node_id' => $conn->target_node_id,
                'source_handle' => $conn->source_handle,
                'target_handle' => $conn->target_handle
            ];
        })->toArray();

        return AutomationWorkflowVersion::create([
            'workflow_id' => $this->id,
            'version_number' => $versionNumber,
            'status' => 'active',
            'nodes_data' => $nodes,
            'connections_data' => $connections
        ]);
    }
}
