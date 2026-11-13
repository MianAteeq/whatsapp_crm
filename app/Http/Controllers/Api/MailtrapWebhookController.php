<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMailtrapWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;

class MailtrapWebhookController extends Controller
{
    /**
     * Handle incoming Mailtrap Webhook events
     * Endpoint: POST /api/webhooks/mailtrap
     */
    public function handle(Request $request): JsonResponse
    {
        $rawContent = $request->getContent();
        $signature = $request->header('mailtrap-signature')
            ?: $request->header('Mailtrap-Signature')
            ?: $request->header('X-Mailtrap-Signature');

        Log::info('[MailtrapWebhookController] Received webhook request.', [
            'has_signature' => !empty($signature),
            'content_length' => strlen($rawContent),
        ]);

        // 1. Resolve webhook signing secret
        $secret = config('services.mailtrap.webhook_secret');

        // 2. Cryptographic signature verification
        if (!empty($secret)) {
            if (empty($signature)) {
                Log::warning('[MailtrapWebhookController] Rejected webhook: missing signature header.');
                return response()->json([
                    'success' => false,
                    'error' => 'Missing mailtrap-signature header.',
                ], 401);
            }

            $computedSignature = hash_hmac('sha256', $rawContent, $secret);

            if (!hash_equals(strtolower($computedSignature), strtolower(trim($signature)))) {
                Log::warning('[MailtrapWebhookController] Rejected webhook: invalid signature mismatch.');

                return response()->json([
                    'success' => false,
                    'error' => 'Invalid webhook signature.',
                ], 401);
            }
        } else {
            Log::info('[MailtrapWebhookController] No MAILTRAP_WEBHOOK_SECRET configured; signature check bypassed.');
        }

        // 3. Extract and normalize events list
        try {
            $events = $this->decodeEvents($rawContent);
        } catch (JsonException) {
            return response()->json(['success' => false, 'error' => 'Invalid JSON or JSON Lines webhook payload.'], 400);
        }
        foreach ($events as $event) {
            if (!is_array($event) || empty($event['event']) || !is_string($event['event'])) {
                return response()->json(['success' => false, 'error' => 'Each webhook event must contain an event name.'], 422);
            }
        }

        if (empty($events)) {
            return response()->json([
                'success' => true,
                'message' => 'No events to process in payload.',
                'events_received' => 0,
            ], 200);
        }

        // 4. Asynchronous processing via Queue Job
        // The database queue requires a running queue worker to process events.
        ProcessMailtrapWebhookJob::dispatch($events);

        return response()->json([
            'success' => true,
            'message' => 'Webhook received and queued for processing.',
            'events_received' => count($events),
        ], 200);
    }

    private function decodeEvents(string $rawContent): array
    {
        try {
            $payloads = [json_decode($rawContent, true, flags: JSON_THROW_ON_ERROR)];
        } catch (JsonException) {
            // Mailtrap also supports newline-delimited JSON. Verify the raw
            // signature above before decoding either format.
            $payloads = array_map(
                fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                preg_split('/\R/', trim($rawContent), flags: PREG_SPLIT_NO_EMPTY)
            );
        }

        $events = [];
        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                throw new JsonException('Webhook payload must be an object or array.');
            }
            if (isset($payload['events']) && is_array($payload['events'])) {
                array_push($events, ...array_values($payload['events']));
            } elseif (array_is_list($payload)) {
                array_push($events, ...$payload);
            } elseif (isset($payload['event'])) {
                $events[] = $payload;
            }
        }

        return $events;
    }
}
