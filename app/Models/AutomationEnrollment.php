<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationEnrollment extends Model
{
    protected $fillable = [
        'tenant_id', 'workflow_id', 'workflow_version_id', 'contact_id', 'enrollment_key',
        'status', 'source', 'source_event_id', 'stop_reason', 'started_at', 'completed_at', 'cancelled_at',
    ];

    protected $casts = [
        'started_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function workflow(): BelongsTo { return $this->belongsTo(AutomationWorkflow::class); }
    public function version(): BelongsTo { return $this->belongsTo(AutomationWorkflowVersion::class, 'workflow_version_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function executions(): HasMany { return $this->hasMany(AutomationExecution::class, 'enrollment_id'); }
}
