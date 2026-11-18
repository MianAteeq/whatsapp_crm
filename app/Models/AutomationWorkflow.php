<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationWorkflow extends Model
{
    protected $table = 'automation_workflows';

    protected $fillable = [
        'tenant_id',
        'folder_id',
        'name',
        'description',
        'internal_notes',
        'status',
        'reentry_policy',
        'reentry_cooldown_minutes',
        'stop_on_response',
        'max_active_enrollments_per_contact',
        'timezone_mode',
        'execution_days',
        'execution_start_time',
        'execution_end_time',
        'sender_type',
        'sender_id',
        'read_state_behavior',
        'missed_execution_behavior',
        'version',
        'lock_version',
        'draft_saved_at',
        'published_version_id',
        'created_by',
        'last_run_at',
        'next_run_at'
    ];

    protected $casts = [
        'stop_on_response' => 'boolean',
        'reentry_cooldown_minutes' => 'integer',
        'max_active_enrollments_per_contact' => 'integer',
        'lock_version' => 'integer',
        'draft_saved_at' => 'datetime',
        'execution_days' => 'array',
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

    public function pauseWindows(): HasMany
    {
        return $this->hasMany(AutomationPauseWindow::class, 'workflow_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(AutomationFolder::class, 'folder_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(AutomationEnrollment::class, 'workflow_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the currently active published version snapshot.
     */
    public function activeVersion()
    {
        if ($this->published_version_id) {
            return $this->versions()->whereKey($this->published_version_id)->first();
        }
        return $this->versions()->whereIn('status', ['published', 'active'])->orderBy('id', 'desc')->first();
    }

    /**
     * Snapshots current visual draft canvas nodes and connections into an immutable version.
     */
    public function createVersionSnapshot(string $versionNumber, ?int $createdBy = null, ?string $summary = null): AutomationWorkflowVersion
    {
        // Deactivate previous active versions
        $this->versions()->whereIn('status', ['active', 'published'])->update(['status' => 'superseded']);

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

        $version = AutomationWorkflowVersion::create([
            'workflow_id' => $this->id,
            'version_number' => $versionNumber,
            'status' => 'published',
            'created_by' => $createdBy,
            'change_summary' => $summary,
            'published_at' => now(),
            'nodes_data' => $nodes,
            'connections_data' => $connections
        ]);
        $this->forceFill(['published_version_id' => $version->id, 'version' => $versionNumber])->save();
        return $version;
    }
}
