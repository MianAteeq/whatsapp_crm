<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMailtrapWebhookJob;
use App\Models\EmailSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $secret = config('services.mailtrap.webhook_secret') ?: env('MAILTRAP_WEBHOOK_SECRET');
        if (empty($secret)) {
            $settingWithSecret = EmailSetting::whereNotNull('mailtrap_webhook_secret')
                ->where('mailtrap_webhook_secret', '!=', '')
                ->first();
            if ($settingWithSecret) {
                $secret = $settingWithSecret->mailtrap_webhook_secret;
            }
        }

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
                Log::warning('[MailtrapWebhookController] Rejected webhook: invalid signature mismatch.', [
                    'computed' => $computedSignature,
                    'provided' => $signature,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Invalid webhook signature.',
                ], 401);
            }
        } else {
            Log::info('[MailtrapWebhookController] No MAILTRAP_WEBHOOK_SECRET configured; signature check bypassed.');
        }

        // 3. Extract and normalize events list
        $payload = $request->json()->all();
        if (empty($payload)) {
            $payload = json_decode($rawContent, true) ?: [];
        }

        $events = [];
        if (!empty($payload['events']) && is_array($payload['events'])) {
            $events = $payload['events'];
        } elseif (array_is_list($payload) && !empty($payload)) {
            $events = $payload;
        } elseif (!empty($payload['event'])) {
            $events = [$payload];
        }

        if (empty($events)) {
            return response()->json([
                'success' => true,
                'message' => 'No events to process in payload.',
                'events_received' => 0,
            ], 200);
        }

        // 4. Asynchronous processing via Queue Job
        // If queue connection is sync or database, this executes cleanly
        ProcessMailtrapWebhookJob::dispatch($events);

        return response()->json([
            'success' => true,
            'message' => 'Webhook received and queued for processing.',
            'events_received' => count($events),
        ], 200);
    }
}
