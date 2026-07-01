<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationLog extends Model
{
    protected $table = 'automation_logs';

    protected $fillable = [
        'execution_id',
        'node_id',
        'step_name',
        'status',
        'message',
        'api_response',
        'execution_time_ms',
        'errors',
        'retry_count'
    ];

    protected $casts = [
        'api_response' => 'array',
        'execution_time_ms' => 'integer',
        'retry_count' => 'integer'
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'execution_id');
    }
}
