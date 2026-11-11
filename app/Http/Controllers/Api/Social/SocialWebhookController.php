<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSocialWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class SocialWebhookController extends Controller
{
    /**
     * Meta Webhook Verification Handshake (GET)
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode') 
             ?? $request->query('hub.mode') 
             ?? $request->input('hub_mode') 
             ?? $request->input('hub.mode');

        $token = $request->query('hub_verify_token') 
              ?? $request->query('hub.verify_token') 
              ?? $request->input('hub_verify_token') 
              ?? $request->input('hub.verify_token');

        $challenge = $request->query('hub_challenge') 
                  ?? $request->query('hub.challenge') 
                  ?? $request->input('hub_challenge') 
                  ?? $request->input('hub.challenge');

        $configuredTokens = array_filter([
            config('services.facebook.webhook_verify_token'),
            env('FACEBOOK_WEBHOOK_VERIFY_TOKEN'),
            env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
            'talkinexx-verify-token',
            'crm-talkinexx-verify-token',
            'crm-throbtech-verify-token',
            'CRM_VERIFY_TOKEN_XYZ',
        ]);

        Log::info('[SocialWebhookController] Meta Verification Request:', [
            'mode' => $mode,
            'token' => $token ? 'PROVIDED' : 'MISSING',
            'challenge' => $challenge ? 'PROVIDED' : 'MISSING',
        ]);

        $isVerified = false;
        if ($mode === 'subscribe' && !empty($token)) {
            foreach ($configuredTokens as $expected) {
                if (hash_equals((string) $expected, (string) $token)) {
                    $isVerified = true;
                    break;
                }
            }
        }

        if ($isVerified) {
            Log::info('[SocialWebhookController] Meta Webhook Verified Successfully!');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('[SocialWebhookController] Verification Failed: token mismatch.', [
            'received' => $token,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Handle Inbound Webhook Events (POST)
     */
    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header('X-Hub-Signature-256') ?? $request->header('x-hub-signature-256');
        $rawPayload = $request->getContent();
        $appSecret = (string) config('services.facebook.app_secret', env('FACEBOOK_APP_SECRET', ''));

        // Validate HMAC-SHA256 signature when Meta App Secret is configured
        if (!empty($appSecret)) {
            if (!$signature || !str_starts_with(strtolower($signature), 'sha256=')) {
                Log::warning('[SocialWebhookController] Missing or malformed X-Hub-Signature-256 header.');
                return response()->json(['error' => 'Missing or invalid signature header'], 401);
            }

            $rawSignature = substr($signature, 7);
            $expectedHash = hash_hmac('sha256', $rawPayload, $appSecret);

            if (!hash_equals($expectedHash, $rawSignature)) {
                Log::warning('[SocialWebhookController] Invalid X-Hub-Signature-256 HMAC signature detected.');
                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        $payload = json_decode($rawPayload, true);
        if (!$payload || !is_array($payload)) {
            Log::warning('[SocialWebhookController] Invalid or unparseable JSON payload received');
            return response()->json(['error' => 'Invalid JSON payload'], 400);
        }

        $object = $payload['object'] ?? 'unknown';
        $entriesCount = count($payload['entry'] ?? []);
        Log::info("[SocialWebhookController] Meta Webhook accepted: object='{$object}', entries={$entriesCount}");

        // Dispatch immediately to background worker
        ProcessSocialWebhookJob::dispatch($payload);

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}
