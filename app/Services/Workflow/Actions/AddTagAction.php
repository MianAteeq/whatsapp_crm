<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;
use App\Models\Tag;
use App\Services\Workflow\VariableResolver;

class AddTagAction implements ActionHandlerInterface
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

        // Resolve variables in tag name if any
        $tagName = $this->variableResolver->resolve($tagNameRaw, $context);
        $tenantId = $context->getWorkflow()->tenant_id;

        // Find or create tag for this tenant
        $tag = Tag::firstOrCreate([
            'tenant_id' => $tenantId,
            'name' => $tagName
        ], [
            'color' => '#6366f1' // default purple
        ]);

        // Attach tag to contact
        $contact->tags()->syncWithoutDetaching([$tag->id]);

        return ActionResponse::success("Tag [{$tagName}] added successfully to contact {$contact->name}.");
    }
}
