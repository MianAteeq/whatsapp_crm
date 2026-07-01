<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use Carbon\Carbon;

class VariableResolver
{
    /**
     * Resolve variables in a given text template string.
     */
    public function resolve(string $text, WorkflowContext $context): string
    {
        return preg_replace_callback('/\{\{\s*([^}]+)\s*\}\}/', function ($matches) use ($context) {
            $key = trim($matches[1]);

            // Resolve contact variables
            if (str_starts_with($key, 'contact.')) {
                $field = substr($key, 8);
                $contact = $context->getContact();
                if ($contact) {
                    return (string)($contact->{$field} ?? '');
                }
            }

            // Resolve message variables
            if (str_starts_with($key, 'message.')) {
                $field = substr($key, 8);
                $message = $context->getMessage();
                if ($message) {
                    if ($field === 'text') {
                        return (string)($message->message ?? '');
                    }
                    return (string)($message->{$field} ?? '');
                }
            }

            // Resolve workflow variables
            if (str_starts_with($key, 'workflow.')) {
                $field = substr($key, 9);
                return (string)($context->getWorkflow()->{$field} ?? '');
            }

            // Resolve company variables
            if (str_starts_with($key, 'company.')) {
                $field = substr($key, 8);
                $tenant = $context->getTenant();
                if ($tenant) {
                    return (string)($tenant->{$field} ?? '');
                }
            }

            // Resolve today / general variables
            if ($key === 'today') {
                return Carbon::now()->toDateString();
            }

            // Fallback to custom context variables stored during execution
            return (string)$context->getVariable($key, $matches[0]);
        }, $text);
    }

    /**
     * Recursively resolve variables inside configurations array.
     */
    public function resolveArray(array $config, WorkflowContext $context): array
    {
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $config[$key] = $this->resolve($value, $context);
            } elseif (is_array($value)) {
                $config[$key] = $this->resolveArray($value, $context);
            }
        }
        return $config;
    }
}
