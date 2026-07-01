<?php

declare(strict_types=1);

namespace App\Services\Workflow\Actions;

use App\Services\Workflow\WorkflowContext;
use App\Services\Workflow\VariableResolver;
use Illuminate\Support\Facades\Http;
use Log;

class WebhookAction implements ActionHandlerInterface
{
    protected VariableResolver $variableResolver;

    public function __construct(VariableResolver $variableResolver)
    {
        $this->variableResolver = $variableResolver;
    }

    public function execute(WorkflowContext $context, array $config): ActionResponse
    {
        $urlRaw = $config['url'] ?? '';
        if (empty($urlRaw)) {
            return ActionResponse::failed('Webhook url configuration is empty.');
        }

        $url = $this->variableResolver->resolve($urlRaw, $context);
        $method = strtoupper($config['method'] ?? 'POST');
        $timeout = (int)($config['timeout'] ?? 10);

        // Resolve parameters/payload array recursively
        $payloadRaw = $config['payload'] ?? [];
        $payload = $this->variableResolver->resolveArray($payloadRaw, $context);

        // Resolve custom headers
        $headersRaw = $config['headers'] ?? [];
        $headers = $this->variableResolver->resolveArray($headersRaw, $context);

        try {
            $request = Http::timeout($timeout)->withHeaders($headers);

            $response = match ($method) {
                'GET' => $request->get($url, $payload),
                'PUT' => $request->put($url, $payload),
                'DELETE' => $request->delete($url, $payload),
                default => $request->post($url, $payload)
            };

            $statusCode = $response->status();
            $body = $response->body();

            Log::info("[WebhookAction] Outbound call to {$url} returned status {$statusCode}");

            if ($response->successful()) {
                return ActionResponse::success(
                    "Webhook call to {$url} completed with status {$statusCode}.",
                    ['webhook_response' => $body]
                );
            }

            return ActionResponse::failed("Webhook returned non-2xx status: {$statusCode}. Body: " . substr($body, 0, 200));
        } catch (\Exception $e) {
            return ActionResponse::failed("Webhook call failed: " . $e->getMessage());
        }
    }
}
