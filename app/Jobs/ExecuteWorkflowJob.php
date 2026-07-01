<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Workflow\WorkflowEngine;

class ExecuteWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $workflowId;
    public ?int $contactId;
    public ?string $currentNodeId;
    public int $executionId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $workflowId,
        ?int $contactId = null,
        ?string $currentNodeId = null,
        int $executionId = 0
    ) {
        $this->workflowId = $workflowId;
        $this->contactId = $contactId;
        $this->currentNodeId = $currentNodeId;
        $this->executionId = $executionId;
    }

    /**
     * Execute the job.
     */
    public function handle(WorkflowEngine $engine): void
    {
        $engine->execute($this->executionId, $this->currentNodeId);
    }
}
