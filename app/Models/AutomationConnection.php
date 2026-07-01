<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationConnection extends Model
{
    protected $table = 'automation_connections';

    protected $fillable = [
        'workflow_id',
        'source_node_id',
        'target_node_id',
        'source_handle',
        'target_handle'
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }
}
