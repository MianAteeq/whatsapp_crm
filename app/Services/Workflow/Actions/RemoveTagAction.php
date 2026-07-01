<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;
use App\Models\Tag;
use App\Services\Workflow\VariableResolver;

class RemoveTagAction implements ActionHandlerInterface
{
    protected VariableResolver $variableResolver;

    public function __construct(VariableResolver $variableResolver)
    {
        $this->variableResolver = $variableResolver;
    }

    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $contact = $context->getContact();
        if (!$contact) {
            return ActionResponse::failed('No contact found in execution context.');
        }

        $tagNameRaw = $config['tagName'] ?? ($config['tag_name'] ?? ($config['tag'] ?? ''));
        if (empty($tagNameRaw)) {
            return ActionResponse::failed('Tag name configuration is empty.');
        }

        $tagName = $this->variableResolver->resolve($tagNameRaw, $context);
        $tenantId = $context->getWorkflow()->tenant_id;

        // Find tag for this tenant
        $tag = Tag::where('tenant_id', $tenantId)
            ->where('name', $tagName)
            ->first();

        if ($tag) {
            $contact->tags()->detach([$tag->id]);
            return ActionResponse::success("Tag [{$tagName}] detached successfully from contact {$contact->name}.");
        }

        return ActionResponse::success("Tag [{$tagName}] was not associated with contact {$contact->name} (skipped).");
    }
}
