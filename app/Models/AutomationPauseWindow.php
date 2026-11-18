<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationPauseWindow extends Model
{
    protected $fillable = ['tenant_id', 'workflow_id', 'name', 'starts_at', 'ends_at', 'recurrence', 'recurrence_limit', 'pause_enrollment', 'pause_execution'];
    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'pause_enrollment' => 'boolean', 'pause_execution' => 'boolean'];
    public function workflow() { return $this->belongsTo(AutomationWorkflow::class, 'workflow_id'); }
}
