<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class FacebookPageSubscriptionService
{
    protected string $graphVersion;
    protected string $baseUrl;

    /**
     * Standard messaging and feed fields Talkinexx requires for Pages.
     */
    protected array $defaultSubscribedFields = [
        'messages',
        'messaging_postbacks',
        'message_deliveries',
        'message_reads',
        'feed',
    ];

    public function __construct(
        protected MetaGraphApiService $metaApi
    ) {
        $this->graphVersion = config('services.facebook.graph_version', env('FACEBOOK_GRAPH_VERSION', 'v21.0'));
        $this->baseUrl = "https://graph.facebook.com/{$this->graphVersion}";
    }

    /**
     * Subscribe a connected Facebook Page to the required webhook fields.
     */
    public function subscribePage(SocialAccount $account, array $fields = []): array
    {
        if ($account->platform !== 'facebook') {
            return [
                'success' => false,
                'message' => 'Only Facebook Pages can be subscribed directly to Page webhooks.',
                'status'  => $account->webhook_status,
            ];
        }

        $pageId = (string) $account->account_id;
        $accessToken = (string) $account->access_token; // Decrypted via cast

        if (empty($accessToken)) {
            $errorMsg = 'Missing Page access token for Facebook Page ' . $pageId;
            Log::warning("[FacebookPageSubscriptionService] {$errorMsg}", [
                'tenant_id' => $account->tenant_id,
                'account_id' => $account->id,
            ]);

            $account->update([
                'webhook_status' => SocialAccount::STATUS_WEBHOOK_FAILED,
                'webhook_subscription_error' => $errorMsg,
                'last_synced_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'status'  => SocialAccount::STATUS_WEBHOOK_FAILED,
            ];
        }

        $fieldsToSubscribe = !empty($fields) ? $fields : $this->defaultSubscribedFields;

        try {
            Log::info("[FacebookPageSubscriptionService] Subscribing Page {$pageId} to webhooks", [
                'tenant_id' => $account->tenant_id,
                'fields' => $fieldsToSubscribe,
            ]);

            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post("{$this->baseUrl}/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => $fieldsToSubscribe,
                ]);

            $data = $response->json() ?? [];

            if ($response->successful() && !empty($data['success'])) {
                Log::info("[FacebookPageSubscriptionService] Successfully subscribed Page {$pageId} to webhooks", [
                    'tenant_id' => $account->tenant_id,
                ]);

                $account->update([
                    'webhook_status' => SocialAccount::STATUS_WEBHOOK_ACTIVE,
                    'webhook_subscription_error' => null,
                    'last_synced_at' => now(),
                ]);

                return [
                    'success' => true,
                    'message' => 'Page successfully subscribed to Facebook webhooks.',
                    'status'  => SocialAccount::STATUS_WEBHOOK_ACTIVE,
                    'fields'  => $fieldsToSubscribe,
                ];
            }

            // Extract Meta Error
            $metaError = $data['error']['message'] ?? $response->body();
            $errorCode = $data['error']['code'] ?? $response->status();
            $fullError = "Meta API Error [{$errorCode}]: {$metaError}";

            Log::warning("[FacebookPageSubscriptionService] Webhook subscription failed for Page {$pageId}: {$fullError}", [
                'tenant_id' => $account->tenant_id,
                'error_payload' => $data['error'] ?? null,
            ]);

            $account->update([
                'webhook_status' => SocialAccount::STATUS_WEBHOOK_FAILED,
                'webhook_subscription_error' => $fullError,
                'last_synced_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => $fullError,
                'status'  => SocialAccount::STATUS_WEBHOOK_FAILED,
            ];
        } catch (\Throwable $e) {
            $errorMsg = 'Network or connection error while subscribing Page: ' . $e->getMessage();
            Log::error("[FacebookPageSubscriptionService] Exception subscribing Page {$pageId}: {$errorMsg}", [
                'tenant_id' => $account->tenant_id,
            ]);

            $account->update([
                'webhook_status' => SocialAccount::STATUS_WEBHOOK_FAILED,
                'webhook_subscription_error' => $errorMsg,
                'last_synced_at' => now(),
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'status'  => SocialAccount::STATUS_WEBHOOK_FAILED,
            ];
        }
    }

    /**
     * Unsubscribe a Facebook Page from webhooks (e.g., when disconnected).
     */
    public function unsubscribePage(SocialAccount $account): array
    {
        if ($account->platform !== 'facebook') {
            return ['success' => false, 'message' => 'Not a Facebook Page.'];
        }

        $pageId = (string) $account->account_id;
        $accessToken = (string) $account->access_token;

        try {
            if ($accessToken) {
                $response = Http::withToken($accessToken)
                    ->timeout(10)
                    ->delete("{$this->baseUrl}/{$pageId}/subscribed_apps");

                Log::info("[FacebookPageSubscriptionService] Unsubscribed Page {$pageId} from webhooks", [
                    'tenant_id' => $account->tenant_id,
                    'status' => $response->status(),
                ]);
            }

            $account->update([
                'webhook_status' => SocialAccount::STATUS_DISCONNECTED,
                'last_synced_at' => now(),
            ]);

            return ['success' => true, 'message' => 'Page unsubscribed from webhooks.'];
        } catch (\Throwable $e) {
            Log::warning("[FacebookPageSubscriptionService] Failed to unsubscribe Page {$pageId}: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check active subscription status directly from Meta Graph API.
     */
    public function checkSubscriptionStatus(SocialAccount $account): array
    {
        if ($account->platform !== 'facebook') {
            return ['success' => false, 'message' => 'Not a Facebook Page.'];
        }

        $pageId = (string) $account->account_id;
        $accessToken = (string) $account->access_token;

        if (empty($accessToken)) {
            return ['success' => false, 'message' => 'Missing access token.'];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get("{$this->baseUrl}/{$pageId}/subscribed_apps");

            $data = $response->json() ?? [];

            if ($response->successful()) {
                $apps = $data['data'] ?? [];
                $isSubscribed = !empty($apps);

                return [
                    'success' => true,
                    'is_subscribed' => $isSubscribed,
                    'apps' => $apps,
                ];
            }

            return [
                'success' => false,
                'message' => $data['error']['message'] ?? 'Failed to query subscription status.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Retry subscription for a tenant's Facebook Page account.
     */
    public function retrySubscription(int $tenantId, int $accountId): array
    {
        $account = SocialAccount::forTenant($tenantId)->findOrFail($accountId);

        return $this->subscribePage($account);
    }
}

