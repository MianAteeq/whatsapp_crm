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
        'workflow_version_id',
        'contact_id',
        'status',
        'current_node_id',
        'retry_count',
        'last_error',
        'started_at',
        'finished_at',
        'resume_at',
        'context_variables'
    ];

    protected $casts = [
        'context_variables' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'resume_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'workflow_version_id');
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
