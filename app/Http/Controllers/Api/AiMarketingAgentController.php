<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCampaignAgentRun;
use App\Models\MarketingKnowledge;
use App\Models\MarketingService;
use App\Services\Ai\AiMarketingAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class AiMarketingAgentController extends Controller
{
    public function __construct(
        protected AiMarketingAgentService $agentService
    ) {}

    /**
     * Submit Natural Language Request -> AI Intent Analysis & Campaign Plan
     */
    public function plan(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $userId = (int)$request->user()->id;

        $request->validate([
            'prompt' => 'required|string|min:3|max:1000',
        ]);

        try {
            $run = $this->agentService->planCampaign($tenantId, $userId, $request->input('prompt'));

            return response()->json([
                'success' => true,
                'message' => 'AI Campaign plan generated successfully.',
                'data' => $run,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Approve and Launch or Schedule Campaign
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $request->validate([
            'send_now' => 'nullable|boolean',
            'scheduled_at' => 'nullable|date',
        ]);

        $sendNow = $request->boolean('send_now', true);
        $scheduledAt = $request->input('scheduled_at');

        try {
            $run = $this->agentService->approveAndSend($tenantId, $id, $sendNow, $scheduledAt);

            return response()->json([
                'success' => true,
                'message' => $sendNow ? 'Campaign approved and launched for background delivery via Mailtrap!' : 'Campaign scheduled successfully.',
                'data' => $run,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel Agent Run
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        try {
            $run = $this->agentService->cancelRun($tenantId, $id);

            return response()->json([
                'success' => true,
                'message' => 'Agent run cancelled.',
                'data' => $run,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * List Agent Runs (Audit History)
     */
    public function runs(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $runs = AiCampaignAgentRun::where('tenant_id', $tenantId)
            ->with(['campaign:id,name,status,sent_count,delivered_count,bounced_count'])
            ->latest('created_at')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $runs->items(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    /**
     * Show Run Details
     */
    public function showRun(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $run = AiCampaignAgentRun::where('tenant_id', $tenantId)
            ->with(['campaign', 'actions'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $run,
        ]);
    }

    /**
     * Post-Campaign DeepSeek Performance Analysis
     */
    public function analyze(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        try {
            $result = $this->agentService->analyzeCampaignPerformance($tenantId, $id);

            return response()->json([
                'success' => true,
                'message' => 'Campaign analyzed successfully with DeepSeek AI.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get Marketing Knowledge & Autonomous Safety Limits
     */
    public function getKnowledge(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $knowledge = MarketingKnowledge::getOrCreateForTenant($tenantId);

        return response()->json([
            'success' => true,
            'data' => $knowledge,
        ]);
    }

    /**
     * Update Marketing Knowledge & Autonomous Safety Limits
     */
    public function updateKnowledge(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $knowledge = MarketingKnowledge::getOrCreateForTenant($tenantId);

        $validated = $request->validate([
            'company_name' => 'nullable|string|max:191',
            'company_description' => 'nullable|string',
            'brand_voice' => 'nullable|string|max:191',
            'website' => 'nullable|string|max:255',
            'demo_link' => 'nullable|string|max:255',
            'booking_link' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:191',
            'contact_phone' => 'nullable|string|max:50',
            'pricing_overview' => 'nullable|string',
            'case_studies' => 'nullable|array',
            'target_industries' => 'nullable|array',
            'approval_mode' => 'nullable|in:manual,approval,autonomous',
            'max_emails_per_campaign' => 'nullable|integer|min:1|max:50000',
            'max_emails_per_day' => 'nullable|integer|min:1|max:100000',
            'min_hours_between_campaigns' => 'nullable|integer|min:1|max:168',
            'cooldown_days_per_contact' => 'nullable|integer|min:0|max:90',
            'require_approval_above_recipients' => 'nullable|integer|min:1|max:50000',
            'allowed_categories' => 'nullable|array',
            'allowed_services' => 'nullable|array',
            'allowed_sending_domain_ids' => 'nullable|array',
        ]);

        $knowledge->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Marketing knowledge and safety limits updated successfully.',
            'data' => $knowledge->fresh(),
        ]);
    }

    /**
     * List Services
     */
    public function services(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        MarketingService::seedDefaultServicesForTenant($tenantId);

        $services = MarketingService::where('tenant_id', $tenantId)->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * Store or Update Service
     */
    public function saveService(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $validated = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'target_industries' => 'nullable|array',
            'features' => 'nullable|array',
            'benefits' => 'nullable|array',
            'pricing' => 'nullable|string|max:255',
            'cta_text' => 'nullable|string|max:100',
            'cta_url' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($validated['id'])) {
            $service = MarketingService::where('tenant_id', $tenantId)->findOrFail($validated['id']);
            $service->update($validated);
        } else {
            $service = MarketingService::create(array_merge($validated, [
                'tenant_id' => $tenantId,
                'slug' => Str::slug($validated['name']),
            ]));
        }

        return response()->json([
            'success' => true,
            'message' => 'Service saved successfully.',
            'data' => $service,
        ]);
    }

    /**
     * Delete Service
     */
    public function deleteService(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $service = MarketingService::where('tenant_id', $tenantId)->findOrFail($id);
        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service deleted successfully.',
        ]);
    }

    /**
     * Update campaign plan & associated email campaign draft (Requirement: user can edit email and template)
     */
    public function updateRunCampaign(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $run = AiCampaignAgentRun::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'preheader' => 'nullable|string|max:255',
            'from_name' => 'required|string|max:191',
            'from_email' => 'required|email|max:191',
            'cta_text' => 'nullable|string|max:100',
            'html_preview' => 'required|string',
            'text_preview' => 'nullable|string',
            'template_theme' => 'nullable|string',
        ]);

        // Update run campaign_plan
        $plan = $run->campaign_plan ?? [];
        $plan['subject'] = $validated['subject'];
        $plan['preheader'] = $validated['preheader'] ?? ($plan['preheader'] ?? '');
        $plan['from_name'] = $validated['from_name'];
        $plan['from_email'] = $validated['from_email'];
        if (!empty($validated['cta_text'])) {
            $plan['cta_text'] = $validated['cta_text'];
        }
        $plan['html_preview'] = $validated['html_preview'];
        $plan['text_preview'] = $validated['text_preview'] ?? strip_tags($validated['html_preview']);
        if (!empty($validated['template_theme'])) {
            $plan['template_theme'] = $validated['template_theme'];
        }

        $run->campaign_plan = $plan;
        $run->save();

        // Update underlying EmailCampaign record if attached
        if ($run->campaign_id) {
            $campaign = \App\Models\EmailCampaign::where('tenant_id', $tenantId)->find($run->campaign_id);
            if ($campaign && in_array($campaign->status, [\App\Models\EmailCampaign::STATUS_DRAFT, \App\Models\EmailCampaign::STATUS_SCHEDULED])) {
                $campaign->update([
                    'subject' => $validated['subject'],
                    'from_name' => $validated['from_name'],
                    'from_email' => $validated['from_email'],
                    'html_body' => $validated['html_preview'],
                    'text_body' => $validated['text_preview'] ?? strip_tags($validated['html_preview']),
                ]);
            }
        }

        $run->appendActionLog('plan_updated', 'User customized and saved email template content', 'success');

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully.',
            'data' => $run->fresh(),
        ]);
    }
}
