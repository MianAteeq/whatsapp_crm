<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class SocialAccountService
{
    public function __construct(
        protected MetaGraphApiService $metaApi,
        protected FacebookPageSubscriptionService $subscriptionService
    ) {}

    /**
     * Generate secure state parameter and OAuth redirection URL
     */
    public function generateOAuthUrl(int $tenantId, int $userId, string $redirectUri, string $frontendUrl = 'http://localhost:3000'): string
    {
        $payload = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'frontend_url' => $frontendUrl,
            'nonce' => Str::random(32),
            'timestamp' => time(),
        ];

        $state = base64_encode(json_encode($payload));
        return $this->metaApi->getAuthorizationUrl($redirectUri, $state);
    }

    /**
     * Decode and validate OAuth state
     */
    public function validateState(string $state): array
    {
        $normalized = str_replace(' ', '+', trim($state));
        $decoded = json_decode(base64_decode($normalized), true);
        if (!$decoded || !isset($decoded['tenant_id'], $decoded['timestamp'])) {
            throw new Exception("Invalid or corrupted OAuth state parameter.");
        }

        // Expire state after 30 minutes
        if (time() - $decoded['timestamp'] > 1800) {
            throw new Exception("OAuth session has expired. Please initiate connection again.");
        }

        return $decoded;
    }

    /**
     * Handle OAuth Callback: discovers pages and asks user to choose if multiple exist
     */
    public function handleCallback(string $code, string $redirectUri, int $tenantId): array
    {
        // 1. Exchange code for 60-day Long-Lived User Access Token
        $tokenData = $this->metaApi->exchangeCodeForLongLivedToken($code, $redirectUri);
        $userAccessToken = $tokenData['access_token'];
        $userTokenExpiresAt = now()->addSeconds($tokenData['expires_in'] ?? 5184000);

        // 2. Fetch Facebook Pages & connected Instagram Professional Accounts
        $pages = $this->metaApi->getConnectedPagesAndInstagramAccounts($userAccessToken);

        if (empty($pages)) {
            throw new Exception("No Facebook Pages found. Ensure your Meta account has Admin or Editor permissions on at least one Page.");
        }

        // If user has multiple pages, hold connection in pending cache and prompt for selection!
        if (count($pages) > 1) {
            $token = Str::random(40);
            Cache::put("social_pending_connection_{$token}", [
                'tenant_id' => $tenantId,
                'user_access_token' => $userAccessToken,
                'user_token_expires_at' => $userTokenExpiresAt->toIso8601String(),
                'pages' => $pages,
            ], now()->addMinutes(30));

            return [
                'status' => 'requires_selection',
                'token' => $token,
                'pages_count' => count($pages),
            ];
        }

        // If only 1 page, connect it directly
        $connectedAccounts = $this->savePagesAndInstagramAccounts($pages, $userAccessToken, $userTokenExpiresAt, $tenantId);

        return [
            'status' => 'connected',
            'accounts' => $connectedAccounts,
        ];
    }

    /**
     * Retrieve discovered pages pending user selection
     */
    public function getPendingPages(string $token, int $tenantId): array
    {
        $data = Cache::get("social_pending_connection_{$token}");
        if (!$data || (int) $data['tenant_id'] !== $tenantId) {
            throw new Exception("Selection session has expired or is invalid. Please initiate connection again.");
        }

        $existing = SocialAccount::forTenant($tenantId)->whereNull('deleted_at')->pluck('account_id')->toArray();

        $formatted = [];
        foreach ($data['pages'] as $page) {
            $pageId = (string) $page['id'];
            $ig = null;
            if (!empty($page['instagram_business_account'])) {
                $rawIg = $page['instagram_business_account'];
                $ig = [
                    'id' => (string) $rawIg['id'],
                    'name' => (string) ($rawIg['name'] ?? $rawIg['username'] ?? 'Instagram Account'),
                    'username' => (string) ($rawIg['username'] ?? ''),
                    'picture' => $rawIg['profile_picture_url'] ?? null,
                    'followers' => (int) ($rawIg['followers_count'] ?? 0),
                    'already_connected' => in_array((string) $rawIg['id'], $existing),
                ];
            }

            $formatted[] = [
                'id' => $pageId,
                'name' => (string) $page['name'],
                'category' => $page['category'] ?? 'Facebook Page',
                'picture' => $page['picture']['data']['url'] ?? null,
                'already_connected' => in_array($pageId, $existing),
                'instagram' => $ig,
            ];
        }

        return [
            'pages' => $formatted,
            'total_count' => count($formatted),
        ];
    }

    /**
     * Connect only the pages explicitly chosen by the user
     */
    public function connectSelectedPages(string $token, array $selectedPageIds, int $tenantId): array
    {
        $data = Cache::get("social_pending_connection_{$token}");
        if (!$data || (int) $data['tenant_id'] !== $tenantId) {
            throw new Exception("Selection session has expired or is invalid. Please initiate connection again.");
        }

        $selectedPages = array_filter($data['pages'], function ($p) use ($selectedPageIds) {
            return in_array((string) $p['id'], $selectedPageIds);
        });

        if (empty($selectedPages)) {
            throw new Exception("Please select at least one Facebook Page to connect.");
        }

        $connected = $this->savePagesAndInstagramAccounts(
            array_values($selectedPages),
            $data['user_access_token'],
            $data['user_token_expires_at'],
            $tenantId
        );

        // Remove from cache after successful connection
        Cache::forget("social_pending_connection_{$token}");

        return $connected;
    }

    /**
     * Persist Facebook Pages and linked Instagram accounts into database
     */
    public function savePagesAndInstagramAccounts(array $pages, string $userAccessToken, $userTokenExpiresAt, int $tenantId): array
    {
        $connectedAccounts = [];

        foreach ($pages as $page) {
            $pageId = (string) $page['id'];
            $pageName = (string) $page['name'];
            $pageAccessToken = (string) ($page['access_token'] ?? $userAccessToken);
            $pagePicture = $page['picture']['data']['url'] ?? null;
            $category = $page['category'] ?? null;

            // Deactivate any stale connections of this same Page ID in other tenants
            SocialAccount::where('platform', 'facebook')
                ->where('account_id', $pageId)
                ->where('tenant_id', '!=', $tenantId)
                ->update(['is_active' => false]);

            // Upsert Facebook Page (restoring if previously soft-deleted)
            $fbAccount = SocialAccount::withTrashed()->firstOrNew([
                'tenant_id' => $tenantId,
                'platform' => 'facebook',
                'account_id' => $pageId,
            ]);
            $fbAccount->fill([
                'name' => $pageName,
                'username' => null,
                'profile_picture_url' => $pagePicture,
                'access_token' => $pageAccessToken, // Cast encrypted
                'token_expires_at' => null, // Page tokens derived from long-lived user tokens never expire
                'is_active' => true,
                'category' => $category,
                'connected_at' => now(),
                'metadata' => [
                    'tasks' => $page['tasks'] ?? [],
                    'user_token_expires_at' => is_string($userTokenExpiresAt) ? $userTokenExpiresAt : $userTokenExpiresAt->toIso8601String(),
                ],
            ]);
            $fbAccount->deleted_at = null; // Restore if previously soft-deleted
            $fbAccount->save();

            // Automatically subscribe the Facebook Page to webhooks
            $subResult = $this->subscriptionService->subscribePage($fbAccount);

            $connectedAccounts[] = [
                'id' => $fbAccount->id,
                'platform' => 'facebook',
                'name' => $fbAccount->name,
                'account_id' => $fbAccount->account_id,
                'webhook_status' => $subResult['status'] ?? $fbAccount->webhook_status,
            ];

            // Connect linked Instagram Professional Account if present
            if (!empty($page['instagram_business_account'])) {
                $ig = $page['instagram_business_account'];
                $igId = (string) $ig['id'];
                $igUsername = (string) ($ig['username'] ?? '');
                $igName = (string) ($ig['name'] ?? $igUsername);
                $igPicture = $ig['profile_picture_url'] ?? null;
                $followers = (int) ($ig['followers_count'] ?? 0);

                // Instagram Graph API uses the associated Page's access token!
                $igAccount = SocialAccount::withTrashed()->firstOrNew([
                    'tenant_id' => $tenantId,
                    'platform' => 'instagram',
                    'account_id' => $igId,
                ]);
                $igAccount->fill([
                    'name' => $igName,
                    'username' => $igUsername,
                    'profile_picture_url' => $igPicture,
                    'access_token' => $pageAccessToken, // Uses Page access token
                    'token_expires_at' => null,
                    'is_active' => true,
                    'category' => 'Instagram Professional',
                    'followers_count' => $followers,
                    'connected_at' => now(),
                    'metadata' => [
                        'linked_facebook_page_id' => $pageId,
                        'linked_facebook_page_name' => $pageName,
                    ],
                ]);
                $igAccount->deleted_at = null; // Restore if previously soft-deleted
                $igAccount->save();

                $connectedAccounts[] = [
                    'id' => $igAccount->id,
                    'platform' => 'instagram',
                    'name' => $igAccount->name,
                    'username' => $igAccount->username,
                    'account_id' => $igAccount->account_id,
                ];
            }
        }

        Log::info("[SocialAccountService] Connected " . count($connectedAccounts) . " social accounts for tenant {$tenantId}");

        return $connectedAccounts;
    }

    /**
     * Check account health / verify token
     */
    public function checkAccountHealth(SocialAccount $account): array
    {
        try {
            $rawToken = $account->access_token; // Decrypted via cast
            $inspection = $this->metaApi->debugToken($rawToken);

            $isValid = $inspection['is_valid'] ?? false;
            $account->update(['is_active' => $isValid]);

            return [
                'is_valid' => $isValid,
                'expires_at' => isset($inspection['expires_at']) && $inspection['expires_at'] > 0
                    ? date('Y-m-d H:i:s', $inspection['expires_at'])
                    : 'Never (Page Token)',
                'scopes' => $inspection['scopes'] ?? [],
            ];
        } catch (Exception $e) {
            $account->update(['is_active' => false]);
            return [
                'is_valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Disconnect account and unsubscribe from webhooks
     */
    public function disconnectAccount(int $tenantId, int $accountId): bool
    {
        $account = SocialAccount::forTenant($tenantId)->findOrFail($accountId);

        if ($account->platform === 'facebook') {
            try {
                $this->subscriptionService->unsubscribePage($account);
            } catch (\Throwable $e) {
                Log::warning("[SocialAccountService] Error unsubscribing page during disconnect: " . $e->getMessage());
            }
        }

        $account->update([
            'is_active' => false,
            'webhook_status' => SocialAccount::STATUS_DISCONNECTED,
        ]);

        return (bool) $account->delete();
    }
}

