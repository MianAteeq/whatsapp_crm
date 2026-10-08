<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCampaignAgentAction extends Model
{
    public $timestamps = false;
    protected $table = 'ai_campaign_agent_actions';

    protected $fillable = [
        'run_id',
        'tenant_id',
        'action_type',
        'tool_name',
        'input_payload',
        'output_payload',
        'status',
        'message',
        'created_at',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiCampaignAgentRun::class, 'run_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
