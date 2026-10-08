<?php

namespace App\Services\Email\Contracts;

interface EmailProviderInterface
{
    /**
     * Send a single personalized email.
     *
     * @param array{
     *     from_name: string,
     *     from_email: string,
     *     reply_to?: string|null,
     *     to_email: string,
     *     to_name?: string|null,
     *     subject: string,
     *     html_body: string,
     *     text_body?: string|null,
     *     headers?: array<string, string>,
     *     metadata?: array<string, mixed>
     * } $message
     * @return array{
     *     success: bool,
     *     message_id?: string|null,
     *     error?: string|null,
     *     raw_response?: mixed
     * }
     */
    public function send(array $message): array;

    /**
     * Send a batch of emails.
     *
     * @param array<int, array> $messages
     * @return array<int, array{success: bool, message_id?: string|null, error?: string|null}>
     */
    public function sendBatch(array $messages): array;

    /**
     * Get delivery status from provider if supported.
     *
     * @param string $messageId
     * @return array{status: string, details?: mixed}
     */
    public function getStatus(string $messageId): array;

    /**
     * Handle incoming provider webhook payload.
     *
     * @param array $payload
     * @param array $headers
     * @return array{
     *     event: string,
     *     message_id?: string|null,
     *     email?: string|null,
     *     reason?: string|null,
     *     metadata?: array
     * }|null
     */
    public function handleWebhook(array $payload, array $headers = []): ?array;
}
