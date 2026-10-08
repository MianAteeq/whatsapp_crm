<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCampaignAgentRun extends Model
{
    protected $table = 'ai_campaign_agent_runs';

    const STATUS_PLANNING = 'planning';
    const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    const STATUS_APPROVED = 'approved';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'campaign_id',
        'user_request',
        'interpreted_intent',
        'approval_mode',
        'status',
        'actions_taken',
        'recipients_selected',
        'recipients_excluded',
        'audience_summary',
        'campaign_plan',
        'performance_analysis',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'interpreted_intent' => 'array',
        'actions_taken' => 'array',
        'audience_summary' => 'array',
        'campaign_plan' => 'array',
        'performance_analysis' => 'array',
        'recipients_selected' => 'integer',
        'recipients_excluded' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AiCampaignAgentAction::class, 'run_id')->oldest('created_at');
    }

    /**
     * Append an action log item to this run and record in actions table
     */
    public function appendActionLog(string $step, string $label, string $status = 'success', ?array $details = null): void
    {
        $logList = $this->actions_taken ?? [];
        $entry = [
            'step' => $step,
            'label' => $label,
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'details' => $details,
        ];
        $logList[] = $entry;

        $this->update(['actions_taken' => $logList]);

        AiCampaignAgentAction::create([
            'run_id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'action_type' => $step,
            'tool_name' => $details['tool'] ?? null,
            'input_payload' => $details['input'] ?? null,
            'output_payload' => $details['output'] ?? null,
            'status' => $status,
            'message' => $label,
        ]);
    }
}
