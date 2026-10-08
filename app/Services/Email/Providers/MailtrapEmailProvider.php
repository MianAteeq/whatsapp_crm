<?php

namespace App\Services\Email\Providers;

use App\Services\Email\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MailtrapEmailProvider implements EmailProviderInterface
{
    protected string $apiBaseUrl = 'https://send.api.mailtrap.io/api';

    public function __construct(
        protected ?string $apiToken = null,
        protected ?string $webhookSecret = null,
        protected ?string $sendingDomain = null
    ) {
        // Fall back to environment/services configuration if not passed explicitly
        $this->apiToken = $this->apiToken ?: (config('services.mailtrap.api_token') ?: env('MAILTRAP_API_TOKEN'));
        $this->webhookSecret = $this->webhookSecret ?: (config('services.mailtrap.webhook_secret') ?: env('MAILTRAP_WEBHOOK_SECRET'));
        $this->sendingDomain = $this->sendingDomain ?: (config('services.mailtrap.domain') ?: env('MAILTRAP_DOMAIN'));
    }

    /**
     * Send a single personalized email via Mailtrap Email API
     */
    public function send(array $message): array
    {
        if (empty($this->apiToken)) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Mailtrap API Token is not configured. Please add your Mailtrap API token in Email Settings or .env.',
            ];
        }

        try {
            $fromEmail = trim($message['from_email'] ?? '');
            $fromName = trim($message['from_name'] ?? '');
            $toEmail = trim($message['to_email'] ?? '');
            $toName = trim($message['to_name'] ?? '');

            if (empty($fromEmail) || empty($toEmail)) {
                return [
                    'success' => false,
                    'message_id' => null,
                    'error' => 'Both from_email and to_email are required by Mailtrap.',
                ];
            }

            // Mailtrap Email API payload structure
            $payload = [
                'from' => [
                    'email' => $fromEmail,
                    'name' => $fromName ?: $fromEmail,
                ],
                'to' => [
                    array_filter([
                        'email' => $toEmail,
                        'name' => $toName ?: null,
                    ])
                ],
                'subject' => $message['subject'] ?? '(No Subject)',
            ];

            if (!empty($message['html_body'])) {
                $payload['html'] = $message['html_body'];
            }

            if (!empty($message['text_body'])) {
                $payload['text'] = $message['text_body'];
            } elseif (!empty($message['html_body'])) {
                $payload['text'] = strip_tags($message['html_body']);
            }

            if (!empty($message['reply_to'])) {
                $payload['reply_to'] = [
                    'email' => $message['reply_to'],
                    'name' => $fromName ?: $fromEmail,
                ];
            }

            // Headers & tracking metadata
            if (!empty($message['headers']) && is_array($message['headers'])) {
                $payload['headers'] = $message['headers'];
            }

            $customVars = [];
            if (!empty($message['metadata']) && is_array($message['metadata'])) {
                $customVars = $message['metadata'];
            }
            if (!empty($message['headers']['X-Campaign-Id'])) {
                $customVars['campaign_id'] = (string)$message['headers']['X-Campaign-Id'];
            }
            if (!empty($message['headers']['X-Recipient-Id'])) {
                $customVars['recipient_id'] = (string)$message['headers']['X-Recipient-Id'];
            }
            if (!empty($customVars)) {
                $payload['custom_variables'] = $customVars;
            }

            $endpoint = rtrim($this->apiBaseUrl, '/') . '/send';

            $response = Http::withToken($this->apiToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->timeout(15)
                ->post($endpoint, $payload);

            // Handle HTTP 429 Rate Limit
            if ($response->status() === 429) {
                Log::warning('[MailtrapEmailProvider] Rate limit hit (HTTP 429).');
                return [
                    'success' => false,
                    'message_id' => null,
                    'error' => 'RATE_LIMIT: Mailtrap rate limit reached. Delaying batch.',
                    'raw_response' => $response->json(),
                ];
            }

            $data = $response->json();

            if ($response->successful()) {
                // Mailtrap returns {"success": true, "message_ids": ["uuid..."]}
                $messageId = null;
                if (!empty($data['message_ids']) && is_array($data['message_ids'])) {
                    $messageId = $data['message_ids'][0];
                }

                // If no message_id returned, generate a fallback tracing ID
                if (!$messageId) {
                    $messageId = 'mt_' . Str::uuid()->toString();
                }

                return [
                    'success' => true,
                    'message_id' => $messageId,
                    'error' => null,
                    'raw_response' => $data,
                ];
            }

            // Format errors from Mailtrap API response
            $errorMessage = 'Mailtrap API Error (HTTP ' . $response->status() . ')';
            if (!empty($data['errors'])) {
                if (is_array($data['errors'])) {
                    $errorMessage .= ': ' . implode(', ', array_map(fn($e) => is_string($e) ? $e : json_encode($e), $data['errors']));
                } else {
                    $errorMessage .= ': ' . (string)$data['errors'];
                }
            } elseif (!empty($data['message'])) {
                $errorMessage .= ': ' . $data['message'];
            } else {
                $errorMessage .= ': ' . $response->body();
            }

            if ($response->status() === 403 && str_contains(strtolower($errorMessage), 'compliance review')) {
                $errorMessage = "Mailtrap Compliance Action Required: Domain is under compliance review. Please log into mailtrap.io → Sending Domains → open your domain to complete your company details.";
            }

            Log::error('[MailtrapEmailProvider] Send failed: ' . $errorMessage, [
                'status' => $response->status(),
                'payload' => $payload,
                'response' => $data,
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $errorMessage,
                'raw_response' => $data,
            ];
        } catch (Throwable $e) {
            Log::error('[MailtrapEmailProvider] Request Exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Mailtrap Connection Failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Send a batch of emails sequentially
     */
    public function sendBatch(array $messages): array
    {
        $results = [];
        foreach ($messages as $msg) {
            $results[] = $this->send($msg);
        }
        return $results;
    }

    /**
     * Get delivery status from provider if supported
     */
    public function getStatus(string $messageId): array
    {
        return ['status' => 'sent', 'message_id' => $messageId];
    }

    /**
     * Verify cryptographic HMAC-SHA256 signature from Mailtrap webhook
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature, ?string $secret = null): bool
    {
        $signingSecret = $secret ?: $this->webhookSecret;

        // If no secret configured in environment or setting, we cannot verify signature
        if (empty($signingSecret)) {
            Log::warning('[MailtrapEmailProvider] Webhook verification skipped: No MAILTRAP_WEBHOOK_SECRET configured.');
            return false;
        }

        if (empty($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $signingSecret);

        return hash_equals(strtolower($expectedSignature), strtolower(trim($signature)));
    }

    /**
     * Handle incoming provider webhook payload
     */
    public function handleWebhook(array $payload, array $headers = []): ?array
    {
        $event = $payload['event'] ?? 'unknown';

        $mappedEvent = match (strtolower((string)$event)) {
            'delivery', 'delivered' => 'delivered',
            'bounce' => 'bounced',
            'soft_bounce' => 'bounced',
            'reject', 'dropped' => 'failed',
            'spam_complaint', 'spam', 'complaint' => 'complained',
            'unsubscribe', 'unsubscribed' => 'unsubscribed',
            'deferral', 'deferred' => 'deferred',
            'open', 'opened' => 'opened',
            'click', 'clicked' => 'clicked',
            default => strtolower((string)$event),
        };

        return [
            'event' => $mappedEvent,
            'message_id' => $payload['message_id'] ?? null,
            'event_id' => $payload['event_id'] ?? null,
            'email' => $payload['email'] ?? null,
            'timestamp' => $payload['timestamp'] ?? null,
            'bounce_type' => $payload['bounce_type'] ?? ($event === 'soft_bounce' ? 'soft' : ($event === 'bounce' ? 'hard' : null)),
            'reason' => $payload['reason'] ?? $payload['response'] ?? null,
            'metadata' => $payload,
        ];
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function getSendingDomain(): ?string
    {
        return $this->sendingDomain;
    }
}
