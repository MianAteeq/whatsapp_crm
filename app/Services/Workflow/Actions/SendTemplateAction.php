<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;
use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\Http;
use App\Services\Workflow\VariableResolver;
use Log;

class SendTemplateAction implements ActionHandlerInterface
{
    protected VariableResolver $variableResolver;

    public function __construct(VariableResolver $variableResolver)
    {
        $this->variableResolver = $variableResolver;
    }

    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
         Log::info('[SendTemplateAction] WhatsApp Template response: ');

        $contact = $context->getContact();
        if (!$contact) {
            return ActionResponse::failed('No contact found in execution context.');
        }

        $templateName = $config['template_id'] ?? ($config['templateId'] ?? '');
        if (empty($templateName)) {
            return ActionResponse::failed('Template Name ID is empty.');
        }

        // Load setting
        $tenantId = $context->getWorkflow()->tenant_id;
        $setting = WhatsappSetting::where('tenant_id', $tenantId)->first();
        if (!$setting) {
            return ActionResponse::failed('WhatsApp account settings not configured.');
        }

        // Dynamically resolve template language from template database record
        $dbTemplate = \App\Models\WhatsappTemplate::where('tenant_id', $tenantId)
            ->where('name', $templateName)
            ->first();

        $language = $dbTemplate ? $dbTemplate->language : ($config['language'] ?? 'en_US');
        $language = str_replace('-', '_', $language);

        Log::info("[SendTemplateAction] Dispatching Template request: {$templateName}, resolved language: {$language}");

        try {
            $to = preg_replace('/\D/', '', $contact->phone); // normalize phone
            $url = "https://graph.facebook.com/v19.0/{$setting->phone_number_id}/messages";

            $response = Http::withToken($setting->access_token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => [
                            'code' => $language
                        ]
                    ]
                ])
                ->json();

            Log::info('[SendTemplateAction] WhatsApp Template response: ', $response ?? []);

            if (isset($response['error'])) {
                return ActionResponse::failed($response['error']['message'] ?? 'WhatsApp API returned an error.');
            }

            return ActionResponse::success("Template [{$templateName}] sent successfully to {$contact->name}.");
        } catch (\Exception $e) {
            return ActionResponse::failed($e->getMessage());
        }
    }
}
