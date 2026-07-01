<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\AutomationWorkflow;
use App\Models\AutomationExecution;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;

class WorkflowContext
{
    protected AutomationWorkflow $workflow;
    protected AutomationExecution $execution;
    protected ?Contact $contact;
    protected ?Conversation $conversation;
    protected ?Message $message;
    protected array $payload;
    protected array $variables;

    public function __construct(
        AutomationWorkflow $workflow,
        AutomationExecution $execution,
        ?Contact $contact = null,
        ?Conversation $conversation = null,
        ?Message $message = null,
        array $payload = []
    ) {
        $this->workflow = $workflow;
        $this->execution = $execution;
        $this->contact = $contact;
        $this->conversation = $conversation;
        $this->message = $message;
        $this->payload = $payload;
        $this->variables = $execution->context_variables ?? [];
    }

    public function getWorkflow(): AutomationWorkflow
    {
        return $this->workflow;
    }

    public function getExecution(): AutomationExecution
    {
        return $this->execution;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getTenant(): ?Tenant
    {
        if ($this->workflow->tenant_id) {
            return Tenant::find($this->workflow->tenant_id);
        }
        return null;
    }

    public function getVariable(string $key, $default = null)
    {
        return $this->variables[$key] ?? $default;
    }

    public function setVariable(string $key, $value): void
    {
        $this->variables[$key] = $value;
        $this->execution->update([
            'context_variables' => $this->variables
        ]);
    }

    public function getVariables(): array
    {
        return $this->variables;
    }
}
