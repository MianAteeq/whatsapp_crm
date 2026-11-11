<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\WhatsappBillingProfile;
use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppBillingService
{
    /**
     * Official Meta domain whitelist for billing redirects.
     */
    protected const ALLOWED_META_DOMAINS = [
        'business.facebook.com',
    ];

    /**
     * Get or initialize a billing profile for the given tenant.
     */
    public function getOrCreateBillingProfile(int $tenantId): ?WhatsappBillingProfile
    {
        $setting = WhatsappSetting::where('tenant_id', $tenantId)->first();

        if (!$setting || !$setting->business_account_id) {
            return null;
        }

        return WhatsappBillingProfile::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'waba_id'   => $setting->business_account_id,
            ],
            [
                'whatsapp_account_id'   => $setting->id,
                'billing_status'        => $setting->is_connected
                    ? WhatsappBillingProfile::STATUS_PENDING_VERIFICATION
                    : WhatsappBillingProfile::STATUS_NOT_CONFIGURED,
                'payment_method_status' => 'unverified',
            ]
        );
    }

    /**
     * Refresh billing status against official Meta Graph API endpoints.
     */
    public function refreshBillingStatus(int $tenantId): array
    {
        $setting = WhatsappSetting::where('tenant_id', $tenantId)->first();

        if (!$setting || !$setting->is_connected || !$setting->access_token || !$setting->business_account_id) {
            return [
                'success' => false,
                'message' => 'WhatsApp Business Account is not connected for this tenant.',
                'status'  => WhatsappBillingProfile::STATUS_NOT_CONFIGURED,
            ];
        }

        $wabaId = $setting->business_account_id;
        $graphVersion = config('services.facebook.graph_version', env('FACEBOOK_GRAPH_VERSION', 'v19.0'));

        $profile = $this->getOrCreateBillingProfile($tenantId);

        try {
            // Documented endpoint for standard Cloud API WABA details (currency, review status, etc.)
            // Note: We do NOT query 'primary_funding_id' here because that field requires official
            // WhatsApp Business Solution Provider (BSP) partner status and triggers OAuthException.
            $url = "https://graph.facebook.com/{$graphVersion}/{$wabaId}";
            $response = Http::withToken($setting->access_token)
                ->timeout(15)
                ->get($url, [
                    'fields' => 'id,name,currency,timezone_id,account_review_status',
                ]);

            $data = $response->json() ?? [];

            // If any requested field triggered a permission check, retry with minimal safe fields
            if (!$response->successful() && isset($data['error'])) {
                $rawMsg = strtolower($data['error']['message'] ?? '');
                if (str_contains($rawMsg, 'business solution provider') || str_contains($rawMsg, 'permission')) {
                    $fallbackResponse = Http::withToken($setting->access_token)
                        ->timeout(15)
                        ->get($url, [
                            'fields' => 'id,name,currency',
                        ]);

                    if ($fallbackResponse->successful()) {
                        $response = $fallbackResponse;
                        $data = $fallbackResponse->json() ?? [];
                    }
                }
            }

            if (!$response->successful()) {
                $errorMsg = $data['error']['message'] ?? 'Failed to query Meta Graph API.';
                Log::warning('[WhatsApp Billing] Graph API check failed', [
                    'tenant_id' => $tenantId,
                    'waba_id'   => $wabaId,
                    'error'     => $data['error'] ?? null,
                ]);

                // Update check timestamp without clearing existing configuration
                if ($profile) {
                    $profile->update([
                        'last_checked_at' => now(),
                    ]);
                }

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'error'   => $data['error'] ?? null,
                    'profile' => $profile ? $this->formatProfile($profile, $setting) : null,
                ];
            }

            // Extract Meta attributes
            $currency = !empty($data['currency']) ? strtoupper(trim($data['currency'])) : null;
            $ownerBusiness = $data['owner_business_info'] ?? [];
            $businessId = !empty($ownerBusiness['id']) ? (string)$ownerBusiness['id'] : $profile?->business_id;
            $accountReviewStatus = $data['account_review_status'] ?? null;
            $primaryFundingId = $data['primary_funding_id'] ?? null;

            // Classify billing status based on verified facts
            $billingStatus = $this->determineStatus(
                $profile,
                $currency,
                $accountReviewStatus,
                $primaryFundingId
            );

            // Determine payment method status (verifiable vs unverified)
            $paymentMethodStatus = !empty($primaryFundingId)
                ? 'configured'
                : 'unverified';

            if ($profile) {
                $updatePayload = [
                    'whatsapp_account_id'   => $setting->id,
                    'business_id'           => $businessId,
                    'currency'              => $currency,
                    'billing_status'        => $billingStatus,
                    'payment_method_status' => $paymentMethodStatus,
                    'last_checked_at'       => now(),
                ];

                // If currency is now present and last error was 131042, resolve it
                if (!empty($currency) && $profile->last_error_code === '131042') {
                    $updatePayload['last_error_code'] = null;
                    $updatePayload['last_error_message'] = null;
                }

                $profile->update($updatePayload);
                $profile->refresh();
            }

            return [
                'success' => true,
                'message' => 'Billing status refreshed successfully.',
                'profile' => $profile ? $this->formatProfile($profile, $setting, $data) : null,
            ];

        } catch (\Throwable $e) {
            Log::error('[WhatsApp Billing] Exception during billing refresh', [
                'tenant_id' => $tenantId,
                'message'   => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Connection to Meta API timed out or failed: ' . $e->getMessage(),
                'profile' => $profile ? $this->formatProfile($profile, $setting) : null,
            ];
        }
    }

    /**
     * Compute current billing state based on factual indicators.
     */
    protected function determineStatus(
        ?WhatsappBillingProfile $profile,
        ?string $currency,
        ?string $accountReviewStatus,
        ?string $primaryFundingId
    ): string {
        // Restricted by Meta review
        if ($accountReviewStatus && in_array(strtoupper($accountReviewStatus), ['FAILED', 'RESTRICTED', 'DISABLED'])) {
            return WhatsappBillingProfile::STATUS_RESTRICTED;
        }

        // Active Error 131042 or currency completely unconfigured
        if (empty($currency) || ($profile && $profile->last_error_code === '131042')) {
            return WhatsappBillingProfile::STATUS_CONFIGURATION_REQUIRED;
        }

        // If there is another active payment error
        if ($profile && !empty($profile->last_error_code) && $profile->last_error_code !== '131042') {
            return WhatsappBillingProfile::STATUS_PAYMENT_ATTENTION_REQUIRED;
        }

        // If a message was successfully sent/delivered recently, we have evidence of operational messaging
        if ($profile && $profile->last_successful_message_at && $profile->last_successful_message_at->gt(now()->subDays(60))) {
            return WhatsappBillingProfile::STATUS_OPERATIONAL;
        }

        // Currency is present and no active errors, but payment method cannot be programmatically checked
        if (!empty($currency)) {
            return !empty($primaryFundingId)
                ? WhatsappBillingProfile::STATUS_OPERATIONAL
                : WhatsappBillingProfile::STATUS_PENDING_VERIFICATION;
        }

        return WhatsappBillingProfile::STATUS_UNKNOWN;
    }

    /**
     * Generate official, validated Meta billing URL for the customer.
     */
    public function generateMetaBillingUrl(
        int $tenantId,
        string $target = 'payment_methods',
        ?WhatsappBillingProfile $profile = null,
        ?WhatsappSetting $setting = null
    ): string {
        $setting = $setting ?? WhatsappSetting::where('tenant_id', $tenantId)->first();
        $profile = $profile ?? WhatsappBillingProfile::where('tenant_id', $tenantId)->first();

        $wabaId = $setting?->business_account_id ?? $profile?->waba_id;
        $businessId = $profile?->business_id;

        // Route to the appropriate official Meta experience
        if ($target === 'currency' || $target === 'business_info') {
            if (!empty($businessId)) {
                $url = "https://business.facebook.com/settings/info?business_id={$businessId}";
            } else {
                $url = "https://business.facebook.com/wa/manage/home/?waba_id={$wabaId}";
            }
        } elseif ($target === 'billing_hub' && !empty($businessId)) {
            $url = "https://business.facebook.com/billing_hub/payment_methods?business_id={$businessId}";
        } else {
            // Default: WhatsApp Manager dedicated payment methods page for this specific WABA
            $url = "https://business.facebook.com/wa/manage/payment-methods/?waba_id={$wabaId}";
        }

        $this->validateMetaUrl($url);

        return $url;
    }

    /**
     * Ensure the generated URL strictly points to official Meta domains.
     */
    public function validateMetaUrl(string $url): void
    {
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');

        if (!in_array($host, self::ALLOWED_META_DOMAINS, true)) {
            throw new \InvalidArgumentException("Invalid destination domain. Redirection only allowed to official Meta portals.");
        }
    }

    /**
     * Handle incoming billing errors from webhooks (e.g. Error 131042).
     * Idempotent to prevent duplicate alerts and multiple campaign pauses.
     */
    public function handleWebhookBillingError(
        int $tenantId,
        string $wabaId,
        string $errorCode,
        string $errorMessage,
        array $details = []
    ): void {
        $profile = WhatsappBillingProfile::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'waba_id'   => $wabaId,
            ],
            [
                'billing_status' => WhatsappBillingProfile::STATUS_PAYMENT_ATTENTION_REQUIRED,
            ]
        );

        $isCode131042 = ($errorCode === '131042' || str_contains($errorMessage, '131042') || str_contains(strtolower($errorMessage), 'currency'));
        $targetStatus = $isCode131042
            ? WhatsappBillingProfile::STATUS_CONFIGURATION_REQUIRED
            : WhatsappBillingProfile::STATUS_PAYMENT_ATTENTION_REQUIRED;

        // Check if already in this error state to ensure idempotency
        $alreadyLogged = ($profile->last_error_code === $errorCode && $profile->billing_status === $targetStatus);

        $profile->update([
            'billing_status'     => $targetStatus,
            'last_error_code'    => $errorCode,
            'last_error_message' => $errorMessage,
            'last_error_details' => $details,
            'last_checked_at'    => now(),
        ]);

        if (!$alreadyLogged) {
            Log::warning("[WhatsApp Billing Alert] Webhook error detected for tenant {$tenantId}", [
                'tenant_id'  => $tenantId,
                'waba_id'    => $wabaId,
                'error_code' => $errorCode,
                'error'      => $errorMessage,
            ]);

            // Pause affected campaigns belonging exclusively to this tenant
            try {
                $affectedCount = Campaign::where('tenant_id', $tenantId)
                    ->whereIn('status', ['running', 'queued', 'scheduled'])
                    ->update(['status' => 'paused']);

                if ($affectedCount > 0) {
                    Log::info("[WhatsApp Billing] Paused {$affectedCount} campaigns for tenant {$tenantId} due to billing error {$errorCode}");
                }
            } catch (\Throwable $e) {
                Log::error("[WhatsApp Billing] Failed to pause campaigns for tenant {$tenantId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Record a successful message transmission from webhook/API.
     */
    public function recordSuccessfulMessage(int $tenantId, ?string $wabaId = null): void
    {
        $query = WhatsappBillingProfile::where('tenant_id', $tenantId);
        if ($wabaId) {
            $query->where('waba_id', $wabaId);
        }

        $profile = $query->first();

        if ($profile) {
            $updates = [
                'last_successful_message_at' => now(),
            ];

            // If profile was in configuration or attention required and was cleared, or pending verification, mark operational
            if ($profile->billing_status === WhatsappBillingProfile::STATUS_PENDING_VERIFICATION ||
                ($profile->billing_status === WhatsappBillingProfile::STATUS_PAYMENT_ATTENTION_REQUIRED && empty($profile->last_error_code))) {
                $updates['billing_status'] = WhatsappBillingProfile::STATUS_OPERATIONAL;
            }

            $profile->update($updates);
        }
    }

    /**
     * Clear or acknowledge an active billing error.
     */
    public function dismissBillingError(int $tenantId): bool
    {
        $profile = WhatsappBillingProfile::where('tenant_id', $tenantId)->first();

        if (!$profile) {
            return false;
        }

        $profile->update([
            'last_error_code'    => null,
            'last_error_message' => null,
            'billing_status'     => !empty($profile->currency)
                ? ($profile->last_successful_message_at ? WhatsappBillingProfile::STATUS_OPERATIONAL : WhatsappBillingProfile::STATUS_PENDING_VERIFICATION)
                : WhatsappBillingProfile::STATUS_CONFIGURATION_REQUIRED,
        ]);

        return true;
    }

    /**
     * Format billing profile for client API response.
     */
    public function formatProfile(
        WhatsappBillingProfile $profile,
        ?WhatsappSetting $setting = null,
        array $extraMeta = []
    ): array {
        $setting = $setting ?? WhatsappSetting::where('tenant_id', $profile->tenant_id)->first();

        return [
            'id'                         => $profile->id,
            'tenant_id'                  => $profile->tenant_id,
            'waba_id'                    => $profile->waba_id,
            'business_id'                => $profile->business_id,
            'business_name'              => $setting?->business_name ?? null,
            'phone_number'               => $setting?->phone_number ?? null,
            'phone_number_id'            => $setting?->phone_number_id ?? null,
            'is_connected'               => (bool)($setting?->is_connected ?? false),
            'billing_status'             => $profile->billing_status,
            'currency'                   => $profile->currency,
            'country'                    => $profile->country,
            'payment_method_status'      => $profile->payment_method_status ?? 'unverified',
            'payment_verification_note'  => 'Payment method status must be verified in Meta Business Manager.',
            'last_error_code'            => $profile->last_error_code,
            'last_error_message'         => $profile->last_error_message,
            'last_error_details'         => $profile->last_error_details,
            'last_checked_at'            => $profile->last_checked_at?->toISOString(),
            'last_successful_message_at' => $profile->last_successful_message_at?->toISOString(),
            'meta_links'                 => [
                'payment_methods' => $this->generateMetaBillingUrl($profile->tenant_id, 'payment_methods', $profile, $setting),
                'currency_config' => $this->generateMetaBillingUrl($profile->tenant_id, 'currency', $profile, $setting),
                'billing_hub'     => $this->generateMetaBillingUrl($profile->tenant_id, 'billing_hub', $profile, $setting),
            ],
            'meta_raw'                   => !empty($extraMeta) ? [
                'currency'              => $extraMeta['currency'] ?? null,
                'timezone_id'           => $extraMeta['timezone_id'] ?? null,
                'account_review_status' => $extraMeta['account_review_status'] ?? null,
                'primary_funding_id'    => !empty($extraMeta['primary_funding_id']),
            ] : null,
        ];
    }
}
