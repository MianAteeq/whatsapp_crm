<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflowVersion extends Model
{
    protected $table = 'automation_workflow_versions';

    protected $fillable = [
        'workflow_id',
        'version_number',
        'status',
        'nodes_data',
        'connections_data'
    ];

    protected $casts = [
        'nodes_data' => 'array',
        'connections_data' => 'array'
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'workflow_version_id');
    }
}
