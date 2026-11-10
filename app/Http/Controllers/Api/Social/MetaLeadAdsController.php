<?php

namespace App\Http\Controllers\Api\Social;

use App\Http\Controllers\Controller;
use App\Models\MetaLeadForm;
use App\Models\MetaLeadSubmission;
use App\Models\SocialAccount;
use App\Services\Social\MetaGraphApiService;
use App\Services\Social\MetaLeadAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class MetaLeadAdsController extends Controller
{
    public function __construct(
        protected MetaLeadAdsService $leadAdsService,
        protected MetaGraphApiService $metaApi
    ) {}

    /**
     * Resolve current tenant ID from request auth context
     */
    protected function getTenantId(Request $request): int
    {
        return (int) ($request->user()?->tenant_id ?? 1);
    }

    /**
     * Get connected accounts with permissions and Lead Ads status
     */
    public function getAccounts(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $accounts = $this->leadAdsService->getConnectedAccountsWithStatus($tenantId);

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Get OAuth URL for requesting/upgrading Lead Ads permissions
     */
    public function getOAuthUrl(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $userId = (int) ($request->user()?->id ?? 1);

        $redirectUri = $request->input('redirect_uri')
            ?: config('services.facebook.redirect_uri')
            ?: env('META_REDIRECT_URI')
            ?: (rtrim(env('APP_URL', 'https://generic-turbofan-rundown.ngrok-free.dev'), '/') . '/api/v1/social/oauth/callback');

        $frontendUrl = $request->input('frontend_url')
            ?: env('FRONTEND_URL', 'http://localhost:3000');

        $payload = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'frontend_url' => $frontendUrl,
            'return_to' => '/social-planner/lead-ads',
            'nonce' => Str::random(32),
            'timestamp' => time(),
        ];

        $state = base64_encode(json_encode($payload));
        $url = $this->metaApi->getLeadAdsAuthorizationUrl($redirectUri, $state);

        return response()->json([
            'success' => true,
            'data' => [
                'authorization_url' => $url,
            ],
        ]);
    }

    /**
     * Get summary metrics for Lead Ads dashboard
     */
    public function getMetrics(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $totalForms = MetaLeadForm::where('tenant_id', $tenantId)->count();
        $activeForms = MetaLeadForm::where('tenant_id', $tenantId)->where('is_active', true)->count();
        $totalSubmissions = MetaLeadSubmission::where('tenant_id', $tenantId)->count();
        $syncedSubmissions = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->where('sync_status', MetaLeadSubmission::STATUS_SYNCED)
            ->count();
        $failedSubmissions = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->where('sync_status', MetaLeadSubmission::STATUS_FAILED)
            ->count();
        $todaySubmissions = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->whereDate('created_at', today())
            ->count();

        $successRate = $totalSubmissions > 0
            ? round(($syncedSubmissions / $totalSubmissions) * 100, 1)
            : 100.0;

        return response()->json([
            'success' => true,
            'data' => [
                'total_forms' => $totalForms,
                'active_forms' => $activeForms,
                'total_leads' => $totalSubmissions,
                'synced_leads' => $syncedSubmissions,
                'failed_leads' => $failedSubmissions,
                'today_leads' => $todaySubmissions,
                'sync_success_rate' => $successRate,
            ],
        ]);
    }

    /**
     * List all lead forms for the tenant
     */
    public function getForms(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $query = MetaLeadForm::with('socialAccount:id,name,platform,profile_picture_url,account_id')
            ->where('tenant_id', $tenantId);

        if ($request->filled('social_account_id')) {
            $query->where('social_account_id', $request->input('social_account_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('form_id', 'like', "%{$s}%");
            });
        }

        $forms = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $forms,
        ]);
    }

    /**
     * Trigger remote discovery and sync of forms from Meta Graph API
     */
    public function syncRemoteForms(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $request->validate([
            'social_account_id' => 'required|integer',
        ]);

        try {
            $syncedForms = $this->leadAdsService->syncFormsForPage(
                $tenantId,
                (int) $request->input('social_account_id')
            );

            return response()->json([
                'success' => true,
                'message' => 'Lead forms successfully synchronized from Meta Graph API.',
                'data' => $syncedForms,
            ]);
        } catch (Exception $e) {
            Log::error("[MetaLeadAdsController] Form sync failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get single form details
     */
    public function getForm(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $form = MetaLeadForm::with('socialAccount')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $form,
        ]);
    }

    /**
     * Update form settings (field mapping, rules, sync toggle)
     */
    public function updateForm(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $form = MetaLeadForm::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'auto_sync' => 'sometimes|boolean',
            'crm_mapping' => 'sometimes|array',
            'rules' => 'sometimes|array',
        ]);

        $form->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Lead form configuration updated.',
            'data' => $form->fresh(),
        ]);
    }

    /**
     * Import historical leads for a given form
     */
    public function importHistoricalLeads(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $limit = min((int) $request->input('limit', 50), 100);

        try {
            $result = $this->leadAdsService->importHistoricalLeads($tenantId, $id, $limit);

            return response()->json([
                'success' => true,
                'message' => "Import complete: {$result['imported']} new leads imported, {$result['duplicates']} duplicates skipped.",
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error("[MetaLeadAdsController] Historical import error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * List submissions audit log
     */
    public function getSubmissions(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $query = MetaLeadSubmission::with(['form:id,name', 'contact:id,name,email,phone'])
            ->where('tenant_id', $tenantId);

        if ($request->filled('meta_lead_form_id')) {
            $query->where('meta_lead_form_id', $request->input('meta_lead_form_id'));
        }

        if ($request->filled('sync_status')) {
            $query->where('sync_status', $request->input('sync_status'));
        }

        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }

        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('leadgen_id', 'like', "%{$s}%")
                  ->orWhere('ad_name', 'like', "%{$s}%")
                  ->orWhere('campaign_name', 'like', "%{$s}%");
            });
        }

        $submissions = $query->orderBy('created_at', 'desc')->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $submissions,
        ]);
    }

    /**
     * Retry syncing a failed lead submission
     */
    public function retrySubmission(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $submission = MetaLeadSubmission::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $form = $submission->form;
        $account = $form?->socialAccount ?: SocialAccount::where('account_id', $submission->page_id)->first();

        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Associated Facebook Page connection is not found.',
            ], 404);
        }

        try {
            $rawPayload = $submission->raw_payload;

            // If raw payload was an error object, re-fetch from Meta API
            if (isset($rawPayload['error']) || empty($rawPayload['field_data'])) {
                $rawPayload = $this->metaApi->getLeadData($submission->leadgen_id, $account->access_token);
            }

            $fieldKeyMap = [];
            foreach ($rawPayload['field_data'] ?? [] as $fd) {
                $fName = $fd['name'] ?? '';
                $fVals = $fd['values'] ?? [];
                $fieldKeyMap[$fName] = count($fVals) === 1 ? $fVals[0] : implode(', ', $fVals);
            }

            $mapped = $this->leadAdsService->mapLeadFieldsToContact($fieldKeyMap, $form?->crm_mapping ?? []);

            $submission->update([
                'raw_payload' => $rawPayload,
                'mapped_data' => $mapped,
                'sync_status' => MetaLeadSubmission::STATUS_SYNCED,
                'error_message' => null,
                'synced_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Lead submission successfully re-processed.',
                'data' => $submission->fresh(['contact', 'form']),
            ]);
        } catch (Exception $e) {
            Log::error("[MetaLeadAdsController] Retry failed: " . $e->getMessage());
            $submission->update([
                'error_message' => "Retry failed: {$e->getMessage()}",
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}

