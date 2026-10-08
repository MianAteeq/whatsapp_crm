<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogEmailProvider implements EmailProviderInterface
{
    public function send(array $message): array
    {
        $messageId = 'log_' . Str::uuid()->toString();
        
        Log::info("[LogEmailProvider] Email Dispatched", [
            'message_id' => $messageId,
            'to' => $message['to_email'] ?? '',
            'from' => ($message['from_name'] ?? '') . ' <' . ($message['from_email'] ?? '') . '>',
            'subject' => $message['subject'] ?? '',
            'has_html' => !empty($message['html_body']),
        ]);

        return [
            'success' => true,
            'message_id' => $messageId,
            'error' => null,
            'raw_response' => ['status' => 'logged', 'time' => now()->toISOString()],
        ];
    }

    public function sendBatch(array $messages): array
    {
        $results = [];
        foreach ($messages as $msg) {
            $results[] = $this->send($msg);
        }
        return $results;
    }

    public function getStatus(string $messageId): array
    {
        return [
            'status' => 'delivered',
            'details' => 'Simulated delivery for local log provider',
        ];
    }

    public function handleWebhook(array $payload, array $headers = []): ?array
    {
        return [
            'event' => $payload['event'] ?? 'delivered',
            'message_id' => $payload['message_id'] ?? null,
            'email' => $payload['email'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'metadata' => $payload,
        ];
    }
}
