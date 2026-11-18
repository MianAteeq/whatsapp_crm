<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Workflow\SensitiveDataRedactor;
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
        'outcome',
        'message',
        'input_snapshot',
        'output_snapshot',
        'api_response',
        'execution_time_ms',
        'errors',
        'skip_reason',
        'retry_count',
        'started_at',
        'finished_at'
    ];

    protected $casts = [
        'api_response' => 'array',
        'input_snapshot' => 'array',
        'output_snapshot' => 'array',
        'execution_time_ms' => 'integer',
        'retry_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime'
    ];

    protected static function booted(): void
    {
        static::creating(function (AutomationLog $log): void {
            $log->outcome ??= match ($log->status) {
                'success' => 'completed',
                'error' => 'failed',
                default => $log->status,
            };
            $log->started_at ??= now();
            $log->finished_at ??= now();
            $log->input_snapshot = SensitiveDataRedactor::redact($log->input_snapshot);
            $log->output_snapshot = SensitiveDataRedactor::redact($log->output_snapshot);
            $log->api_response = SensitiveDataRedactor::redact($log->api_response);
        });
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'execution_id');
    }
}
