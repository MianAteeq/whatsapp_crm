<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Models\AutomationWorkflow;
use App\Models\Contact;
use App\Models\Opportunity;
use App\Models\Tag;
use App\Services\Workflow\EnrollmentService;
use App\Services\Workflow\WorkflowContext;

final class CrmRecordAction implements ActionHandlerInterface
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $tenantId = (int) $context->getWorkflow()->tenant_id;
        $operation = $config['operation'] ?? '';
        $contact = $context->getContact();

        return match ($operation) {
            'create_contact' => $this->createContact($tenantId, $config),
            'update_contact', 'update_contact_fields' => $this->updateContact($tenantId, $contact, $config),
            'create_opportunity' => $this->createOpportunity($tenantId, $contact, $config),
            'update_opportunity', 'change_opportunity_stage' => $this->updateOpportunity($tenantId, $config),
            'add_tag', 'remove_tag' => $this->changeTag($tenantId, $contact, $config, $operation),
            'enroll_workflow' => $this->enroll($tenantId, $contact, $config),
            'remove_from_workflow' => $this->remove($tenantId, $contact, $config),
            default => ActionResponse::failed('Unsupported CRM operation.'),
        };
    }

    private function createContact(int $tenantId, array $config): ActionResponse
    {
        $fields = array_intersect_key($config['fields'] ?? [], array_flip((new Contact)->getFillable()));
        $contact = Contact::create(array_merge($fields, ['tenant_id' => $tenantId]));
        return ActionResponse::success('Contact created.', ['contact_id' => $contact->id]);
    }

    private function updateContact(int $tenantId, ?Contact $contact, array $config): ActionResponse
    {
        if (!$contact || (int) $contact->tenant_id !== $tenantId) return ActionResponse::failed('Contact lookup failed.');
        $contact->update(array_intersect_key($config['fields'] ?? [], array_flip($contact->getFillable())));
        return ActionResponse::success('Contact updated.', ['contact_id' => $contact->id, 'lookup' => 'success']);
    }

    private function createOpportunity(int $tenantId, ?Contact $contact, array $config): ActionResponse
    {
        if (!$contact) return ActionResponse::failed('Contact lookup failed.');
        $fields = array_intersect_key($config['fields'] ?? [], array_flip((new Opportunity)->getFillable()));
        $opportunity = Opportunity::create(array_merge($fields, ['tenant_id' => $tenantId, 'contact_id' => $contact->id]));
        return ActionResponse::success('Opportunity created.', ['opportunity_id' => $opportunity->id]);
    }

    private function updateOpportunity(int $tenantId, array $config): ActionResponse
    {
        $opportunity = Opportunity::where('tenant_id', $tenantId)->find($config['opportunity_id'] ?? 0);
        if (!$opportunity) return ActionResponse::failed('Opportunity lookup failed.');
        $fields = $config['fields'] ?? [];
        if (isset($config['stage_id'])) $fields['stage_id'] = $config['stage_id'];
        $opportunity->update(array_intersect_key($fields, array_flip($opportunity->getFillable())));
        return ActionResponse::success('Opportunity updated.', ['opportunity_id' => $opportunity->id, 'lookup' => 'success']);
    }

    private function changeTag(int $tenantId, ?Contact $contact, array $config, string $operation): ActionResponse
    {
        if (!$contact) return ActionResponse::failed('Contact lookup failed.');
        $tag = Tag::where('tenant_id', $tenantId)->find($config['tag_id'] ?? 0);
        if (!$tag) return ActionResponse::failed('Tag lookup failed.');
        $operation === 'add_tag' ? $contact->tags()->syncWithoutDetaching([$tag->id]) : $contact->tags()->detach($tag->id);
        return ActionResponse::success('Contact tags updated.', ['tag_id' => $tag->id]);
    }

    private function enroll(int $tenantId, ?Contact $contact, array $config): ActionResponse
    {
        $workflow = AutomationWorkflow::where('tenant_id', $tenantId)->where('status', 'active')->find($config['workflow_id'] ?? 0);
        $version = $workflow?->activeVersion();
        if (!$workflow || !$version) return ActionResponse::failed('Target workflow lookup failed.');
        $trigger = collect($version->nodes_data)->first(fn ($node) => in_array($node['type'] ?? '', ['trigger', 'incoming_message', 'keyword', 'contact_created', 'birthday', 'schedule'], true));
        if (!$trigger) return ActionResponse::failed('Target workflow has no trigger.');
        $sourceEvent = isset($config['source_execution_id']) ? 'execution:'.$config['source_execution_id'] : null;
        $execution = $this->enrollments->create($workflow, $version, $contact?->id, 'workflow_action', $sourceEvent, $trigger['id']);
        \App\Jobs\ExecuteWorkflowJob::dispatch($workflow->id, $contact?->id, null, $execution->id);
        return ActionResponse::success('Enrolled in target workflow.', ['execution_id' => $execution->id]);
    }

    private function remove(int $tenantId, ?Contact $contact, array $config): ActionResponse
    {
        if (!$contact) return ActionResponse::failed('Contact lookup failed.');
        $count = \App\Models\AutomationEnrollment::where('tenant_id', $tenantId)->where('contact_id', $contact->id)
            ->where('workflow_id', $config['workflow_id'] ?? 0)->whereIn('status', ['pending', 'running', 'paused', 'retrying'])
            ->update(['status' => 'cancelled', 'stop_reason' => 'Removed by workflow action.', 'cancelled_at' => now()]);
        return ActionResponse::success('Enrollment removal completed.', ['removed' => $count]);
    }
}
