<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SendGridEmailProvider implements EmailProviderInterface
{
    public function __construct(
        protected ?string $apiKey
    ) {}

    public function send(array $message): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'SendGrid API Key is not configured.',
            ];
        }

        try {
            $from = [
                'email' => $message['from_email'] ?? '',
                'name' => $message['from_name'] ?? '',
            ];

            $to = [
                'email' => $message['to_email'] ?? '',
            ];
            if (!empty($message['to_name'])) {
                $to['name'] = $message['to_name'];
            }

            $content = [];
            if (!empty($message['text_body'])) {
                $content[] = [
                    'type' => 'text/plain',
                    'value' => $message['text_body'],
                ];
            }
            if (!empty($message['html_body'])) {
                $content[] = [
                    'type' => 'text/html',
                    'value' => $message['html_body'],
                ];
            }
            if (empty($content)) {
                $content[] = [
                    'type' => 'text/plain',
                    'value' => '',
                ];
            }

            $payload = [
                'personalizations' => [
                    [
                        'to' => [$to],
                        'subject' => $message['subject'] ?? '(No Subject)',
                    ],
                ],
                'from' => $from,
                'content' => $content,
            ];

            if (!empty($message['reply_to'])) {
                $payload['reply_to'] = ['email' => $message['reply_to']];
            }

            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->post('https://api.sendgrid.com/v3/mail/send', $payload);

            if ($response->status() === 429) {
                return [
                    'success' => false,
                    'message_id' => null,
                    'error' => 'RATE_LIMIT: SendGrid rate limit exceeded (HTTP 429).',
                ];
            }

            if ($response->successful()) {
                $msgId = $response->header('X-Message-Id') ?? ('sg_' . Str::uuid()->toString());
                return [
                    'success' => true,
                    'message_id' => $msgId,
                    'error' => null,
                ];
            }

            $errorBody = $response->json();
            $errorMsg = $errorBody['errors'][0]['message'] ?? ('HTTP ' . $response->status() . ': ' . $response->body());

            return [
                'success' => false,
                'message_id' => null,
                'error' => 'SendGrid Error: ' . $errorMsg,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'SendGrid Request Failed: ' . $e->getMessage(),
            ];
        }
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
        return ['status' => 'sent'];
    }

    public function handleWebhook(array $payload, array $headers = []): ?array
    {
        // SendGrid event webhook array
        $first = $payload[0] ?? $payload;
        $event = $first['event'] ?? 'unknown';
        
        $mappedEvent = match ($event) {
            'delivered' => 'delivered',
            'bounce' => 'bounced',
            'dropped' => 'failed',
            'spamreport' => 'spam_complaint',
            'unsubscribe' => 'unsubscribed',
            default => $event,
        };

        return [
            'event' => $mappedEvent,
            'message_id' => $first['sg_message_id'] ?? null,
            'email' => $first['email'] ?? null,
            'reason' => $first['reason'] ?? null,
            'metadata' => $first,
        ];
    }
}
