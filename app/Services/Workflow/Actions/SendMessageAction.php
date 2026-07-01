<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;
use App\Services\WhatsAppService;
use App\Services\Workflow\VariableResolver;
use Log;

class SendMessageAction implements ActionHandlerInterface
{
    protected WhatsAppService $whatsappService;
    protected VariableResolver $variableResolver;

    public function __construct(WhatsAppService $whatsappService, VariableResolver $variableResolver)
    {
        $this->whatsappService = $whatsappService;
        $this->variableResolver = $variableResolver;
    }

    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $contact = $context->getContact();
        if (!$contact) {
            return ActionResponse::failed('No contact found in execution context.');
        }

        $rawMessage = $config['message'] ?? '';
        if (empty($rawMessage)) {
            return ActionResponse::failed('Message configuration text body is empty.');
        }

        // Resolve variables (e.g. {{contact.name}})
        $message = $this->variableResolver->resolve($rawMessage, $context);

        try {
            $to = preg_replace('/\D/', '', $contact->phone); // normalize phone number
            $response = $this->whatsappService->sendText(
                $context->getWorkflow()->tenant_id,
                $to,
                $message
            );

            // Log raw response
            Log::info('[SendMessageAction] WhatsApp send response: ', $response ?? []);

            if (isset($response['error'])) {
                return ActionResponse::failed($response['error']['message'] ?? 'WhatsApp API returned an error.');
            }

            return ActionResponse::success("Message sent successfully to {$contact->name}.");
        } catch (\Exception $e) {
            return ActionResponse::failed($e->getMessage());
        }
    }
}
