<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Social\SocialAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class SocialAccountController extends Controller
{
    public function __construct(
        protected SocialAccountService $accountService
    ) {}

    protected function getTenantId(Request $request): int
    {
        return (int) ($request->user()?->tenant_id ?? 1);
    }

    /**
     * List all connected social accounts for the authenticated tenant
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $query = SocialAccount::forTenant($tenantId);

        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $accounts = $query->latest('connected_at')->get();

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Generate Meta OAuth Connect URL
     */
    public function connectUrl(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $userId = (int) $request->user()->id;

        $redirectUri = $request->input('redirect_uri')
            ?: config('services.facebook.redirect_uri')
            ?: env('META_REDIRECT_URI')
            ?: (rtrim(env('APP_URL', 'https://generic-turbofan-rundown.ngrok-free.dev'), '/') . '/api/v1/social/oauth/callback');

        $frontendUrl = $request->input('frontend_url')
            ?: env('FRONTEND_URL', 'http://localhost:3000');

        try {
            $authUrl = $this->accountService->generateOAuthUrl($tenantId, $userId, $redirectUri, $frontendUrl);

            return response()->json([
                'success' => true,
                'data' => [
                    'auth_url' => $authUrl,
                    'redirect_uri' => $redirectUri,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate Meta connection URL: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Meta OAuth Callback
     */
    public function oauthCallback(Request $request)
    {
        Log::info('[Meta OAuth Callback] Incoming parameters: ' . json_encode($request->except(['access_token'])));

        $code = $request->input('code');
        $state = $request->input('state');
        $error = $request->input('error_message') ?? $request->input('error') ?? $request->input('error_description');

        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $stateData = null;

        if ($state) {
            try {
                $normalizedState = str_replace(' ', '+', (string) $state);
                $stateData = $this->accountService->validateState($normalizedState);
                if (!empty($stateData['frontend_url'])) {
                    $frontendUrl = rtrim($stateData['frontend_url'], '/');
                }
            } catch (Exception $e) {
                Log::warning('[Meta OAuth Callback] State validation failed: ' . $e->getMessage());
            }
        }

        $returnTo = $stateData['return_to'] ?? '/social-planner/accounts';

        // Check for user cancellation or Meta error
        if ($error) {
            Log::warning('[Meta OAuth Callback] Meta returned error: ' . $error);
            return redirect()->away($frontendUrl . $returnTo . '?oauth=error&message=' . urlencode($error));
        }

        if (!$code || !$stateData) {
            Log::warning('[Meta OAuth Callback] Rejected callback. Code: ' . ($code ? 'present' : 'missing') . ', StateData: ' . ($stateData ? 'valid' : 'invalid'));
            return redirect()->away($frontendUrl . $returnTo . '?oauth=error&message=' . urlencode('Missing or invalid code/state from Meta.'));
        }

        try {
            $tenantId = (int) $stateData['tenant_id'];
            $redirectUri = $stateData['redirect_uri']
                ?? config('services.facebook.redirect_uri')
                ?? env('META_REDIRECT_URI', 'https://generic-turbofan-rundown.ngrok-free.dev/api/social/facebook/callback');

            $result = $this->accountService->handleCallback($code, $redirectUri, $tenantId);

            // If user manages multiple pages, redirect to selection view in React
            if (($result['status'] ?? '') === 'requires_selection') {
                $selectUrl = $frontendUrl . '/social-planner/accounts?select_pages=1&token=' . urlencode($result['token']) . '&return_to=' . urlencode($returnTo);
                return redirect()->away($selectUrl);
            }

            // Otherwise, single page connected directly
            $connectedCount = count($result['accounts'] ?? []);
            $successUrl = $frontendUrl . $returnTo . '?oauth=success&connected=' . $connectedCount;
            return redirect()->away($successUrl);
        } catch (Exception $e) {
            return redirect()->away($frontendUrl . $returnTo . '?oauth=error&message=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Retrieve discovered pages pending user selection
     */
    public function getPendingPages(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $token = $request->query('token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Connection token is required.',
            ], 422);
        }

        try {
            $data = $this->accountService->getPendingPages($token, $tenantId);
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Confirm which pages the user chose to connect
     */
    public function confirmPageSelection(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'selected_page_ids' => ['required', 'array', 'min:1'],
            'selected_page_ids.*' => ['string'],
        ]);

        try {
            $connected = $this->accountService->connectSelectedPages(
                $validated['token'],
                $validated['selected_page_ids'],
                $tenantId
            );

            return response()->json([
                'success' => true,
                'message' => 'Successfully connected ' . count($connected) . ' account(s).',
                'data' => $connected,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get a single social account details
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $account = SocialAccount::forTenant($tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $account,
        ]);
    }

    /**
     * Re-verify / inspect account token status
     */
    public function checkHealth(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $account = SocialAccount::forTenant($tenantId)->findOrFail($id);

        $health = $this->accountService->checkAccountHealth($account);

        return response()->json([
            'success' => true,
            'data' => $health,
        ]);
    }

    /**
     * Alias for checkHealth / token refresh
     */
    public function refreshToken(Request $request, int $id): JsonResponse
    {
        return $this->checkHealth($request, $id);
    }

    /**
     * Disconnect / remove social account
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $this->accountService->disconnectAccount($tenantId, $id);

        return response()->json([
            'success' => true,
            'message' => 'Social account disconnected successfully.',
        ]);
    }

    /**
     * Alias for destroy
     */
    public function disconnect(Request $request, int $id): JsonResponse
    {
        return $this->destroy($request, $id);
    }
}

