<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationExecution extends Model
{
    protected $table = 'automation_executions';

    protected $fillable = [
        'workflow_id',
        'tenant_id',
        'workflow_version_id',
        'enrollment_id',
        'contact_id',
        'status',
        'current_node_id',
        'retry_count',
        'last_error',
        'completion_reason',
        'cancellation_reason',
        'started_at',
        'finished_at',
        'cancelled_at',
        'resume_at',
        'context_variables'
    ];

    protected $casts = [
        'context_variables' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'resume_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updated(function (AutomationExecution $execution): void {
            if (!$execution->wasChanged('status') || !$execution->enrollment_id) {
                return;
            }
            $attributes = ['status' => $execution->status];
            if ($execution->status === 'completed') $attributes['completed_at'] = $execution->finished_at ?: now();
            if ($execution->status === 'cancelled') {
                $attributes['cancelled_at'] = $execution->cancelled_at ?: now();
                $attributes['stop_reason'] = $execution->cancellation_reason;
            }
            if (in_array($execution->status, ['failed', 'skipped'], true)) $attributes['stop_reason'] = $execution->last_error ?: $execution->completion_reason;
            $execution->enrollment()->update($attributes);
            if ($execution->status === 'failed') app(\App\Services\Workflow\FailureAlertService::class)->send($execution);
        });
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'workflow_version_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(AutomationEnrollment::class, 'enrollment_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class, 'execution_id');
    }
}
