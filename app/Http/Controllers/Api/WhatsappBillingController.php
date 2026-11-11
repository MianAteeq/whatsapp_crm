<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappBillingProfile;
use App\Models\WhatsappSetting;
use App\Services\WhatsAppBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsappBillingController extends Controller
{
    protected WhatsAppBillingService $billingService;

    public function __construct(WhatsAppBillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    /**
     * Retrieve WhatsApp billing profile and status for the authenticated tenant.
     *
     * GET /api/whatsapp/billing
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $setting  = WhatsappSetting::where('tenant_id', $tenantId)->first();

        if (!$setting || !$setting->business_account_id) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'tenant_id'                  => $tenantId,
                    'waba_id'                    => null,
                    'business_id'                => null,
                    'business_name'              => null,
                    'phone_number'               => null,
                    'is_connected'               => false,
                    'billing_status'             => WhatsappBillingProfile::STATUS_NOT_CONFIGURED,
                    'currency'                   => null,
                    'country'                    => null,
                    'payment_method_status'      => 'unverified',
                    'payment_verification_note'  => 'Payment method status must be verified in Meta Business Manager.',
                    'last_error_code'            => null,
                    'last_error_message'         => null,
                    'last_error_details'         => null,
                    'last_checked_at'            => null,
                    'last_successful_message_at' => null,
                    'meta_links'                 => null,
                ],
            ]);
        }

        $profile = $this->billingService->getOrCreateBillingProfile($tenantId);

        // Auto-refresh once if it has never been checked
        if ($profile && empty($profile->last_checked_at) && $setting->is_connected && $setting->access_token) {
            $refreshResult = $this->billingService->refreshBillingStatus($tenantId);
            if (!empty($refreshResult['profile'])) {
                return response()->json([
                    'success' => true,
                    'data'    => $refreshResult['profile'],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $profile ? $this->billingService->formatProfile($profile, $setting) : null,
        ]);
    }

    /**
     * Actively refresh billing & currency status from Meta Graph API.
     *
     * POST /api/whatsapp/billing/refresh
     */
    public function refresh(Request $request): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $result   = $this->billingService->refreshBillingStatus($tenantId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Generate a validated official Meta billing portal URL.
     *
     * POST /api/whatsapp/billing/meta-url
     */
    public function getMetaUrl(Request $request): JsonResponse
    {
        $request->validate([
            'target' => 'nullable|string|in:payment_methods,currency,billing_hub,business_info,whatsapp_manager,business_settings',
        ]);

        $tenantId = (int) auth()->user()->tenant_id;
        $target   = $request->input('target', 'payment_methods');

        try {
            $url = $this->billingService->generateMetaBillingUrl($tenantId, $target);

            return response()->json([
                'success' => true,
                'target'  => $target,
                'url'     => $url,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Dismiss/acknowledge active billing error alert.
     *
     * POST /api/whatsapp/billing/dismiss-error
     */
    public function dismissError(Request $request): JsonResponse
    {
        $tenantId = (int) auth()->user()->tenant_id;
        $success  = $this->billingService->dismissBillingError($tenantId);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Billing alert acknowledged.' : 'No active profile found.',
        ]);
    }
}

