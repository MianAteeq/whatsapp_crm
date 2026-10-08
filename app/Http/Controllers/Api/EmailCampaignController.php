<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSetting;
use App\Services\Email\EmailAudienceFilterService;
use App\Services\Email\EmailCampaignService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailCampaignController extends Controller
{
    /**
     * List email campaigns
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $query = EmailCampaign::where('tenant_id', $tenantId)->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where('name', 'LIKE', "%{$s}%");
        }

        $campaigns = $query->paginate($request->input('per_page', 15));

        $items = collect($campaigns->items())->map(function ($camp) {
            $eligible = max(1, $camp->eligible_count ?: $camp->total_recipients);
            $processed = $camp->sent_count + $camp->failed_count;
            $progressPercent = $eligible > 0 ? min(100, (int) round(($processed / $eligible) * 100)) : 0;
            if ($camp->status === EmailCampaign::STATUS_COMPLETED) {
                $progressPercent = 100;
            }

            return [
                'id' => $camp->id,
                'name' => $camp->name,
                'subject' => $camp->subject,
                'from_name' => $camp->from_name,
                'from_email' => $camp->from_email,
                'reply_to' => $camp->reply_to,
                'status' => $camp->status,
                'total_recipients' => $camp->total_recipients,
                'eligible_count' => $camp->eligible_count,
                'excluded_count' => $camp->excluded_count,
                'sent_count' => $camp->sent_count,
                'delivered_count' => $camp->delivered_count,
                'bounced_count' => $camp->bounced_count,
                'failed_count' => $camp->failed_count,
                'unsubscribed_count' => $camp->unsubscribed_count,
                'progress_percent' => $progressPercent,
                'scheduled_at' => $camp->scheduled_at?->toISOString(),
                'started_at' => $camp->started_at?->toISOString(),
                'completed_at' => $camp->completed_at?->toISOString(),
                'created_at' => $camp->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Store new campaign
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $userId = (int)$request->user()->id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'from_name' => 'required|string|max:191',
            'from_email' => 'required|email|max:191',
            'reply_to' => 'nullable|email|max:191',
            'sending_domain_id' => 'nullable|integer',
            'template_id' => 'nullable|exists:email_templates,id',
            'html_body' => 'required|string',
            'text_body' => 'nullable|string',
            'audience_filter' => 'nullable|array',
            'scheduled_at' => 'nullable|date',
            'launch_now' => 'nullable|boolean',
        ]);

        // HARD SECURITY RULE: Validate that From email domain is owned and verified
        $domainService = app(\App\Services\Email\MailtrapDomainService::class);
        $domainCheck = $domainService->validateDomainForSending(
            $tenantId,
            $validated['from_email'],
            $validated['sending_domain_id'] ?? null
        );

        if (!$domainCheck['allowed']) {
            return response()->json([
                'success' => false,
                'message' => $domainCheck['message'],
            ], 422);
        }

        $verifiedDomain = $domainCheck['domain'];

        $status = EmailCampaign::STATUS_DRAFT;
        if (!empty($validated['scheduled_at'])) {
            $status = EmailCampaign::STATUS_SCHEDULED;
        }

        // Evaluate audience counts upfront so stats are accurate immediately upon creation
        $audienceFilter = $validated['audience_filter'] ?? [];
        $evaluation = \App\Services\Email\EmailAudienceFilterService::evaluateAudience($tenantId, $audienceFilter);

        $campaign = EmailCampaign::create([
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'from_name' => $validated['from_name'],
            'from_email' => $validated['from_email'],
            'reply_to' => $validated['reply_to'] ?? null,
            'sending_domain_id' => $verifiedDomain?->id,
            'sending_domain' => $verifiedDomain?->domain,
            'template_id' => $validated['template_id'] ?? null,
            'html_body' => $validated['html_body'],
            'text_body' => $validated['text_body'] ?? strip_tags($validated['html_body']),
            'audience_filter' => $audienceFilter,
            'status' => $status,
            'total_recipients' => $evaluation['total_evaluated'] ?? 0,
            'eligible_count' => $evaluation['eligible_count'] ?? 0,
            'excluded_count' => $evaluation['excluded_count'] ?? 0,
            'scheduled_at' => !empty($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : null,
        ]);

        // If user chose to launch immediately
        if (!empty($validated['launch_now'])) {
            EmailCampaignService::launchCampaign($campaign, true);
        }

        $campaign->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Email campaign created successfully.',
            'data' => $campaign,
        ], 201);
    }

    /**
     * Show single campaign
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::with(['template', 'creator'])->where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $campaign,
        ]);
    }

    /**
     * Update campaign
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        if (!in_array($campaign->status, [EmailCampaign::STATUS_DRAFT, EmailCampaign::STATUS_SCHEDULED])) {
            return response()->json([
                'success' => false,
                'error' => 'Only Draft or Scheduled campaigns can be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'subject' => 'sometimes|required|string|max:255',
            'from_name' => 'sometimes|required|string|max:191',
            'from_email' => 'sometimes|required|email|max:191',
            'reply_to' => 'nullable|email|max:191',
            'sending_domain_id' => 'nullable|integer',
            'template_id' => 'nullable|exists:email_templates,id',
            'html_body' => 'sometimes|required|string',
            'text_body' => 'nullable|string',
            'audience_filter' => 'nullable|array',
            'scheduled_at' => 'nullable|date',
        ]);

        if (isset($validated['from_email']) || isset($validated['sending_domain_id'])) {
            $fromEmailToCheck = $validated['from_email'] ?? $campaign->from_email;
            $domainIdToCheck = $validated['sending_domain_id'] ?? $campaign->sending_domain_id;

            $domainService = app(\App\Services\Email\MailtrapDomainService::class);
            $domainCheck = $domainService->validateDomainForSending($tenantId, $fromEmailToCheck, $domainIdToCheck);

            if (!$domainCheck['allowed']) {
                return response()->json([
                    'success' => false,
                    'message' => $domainCheck['message'],
                ], 422);
            }

            $validated['sending_domain_id'] = $domainCheck['domain']?->id;
            $validated['sending_domain'] = $domainCheck['domain']?->domain;
        }

        $campaign->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Campaign updated successfully.',
            'data' => $campaign,
        ]);
    }

    /**
     * Delete campaign
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($id);
        
        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully.',
        ]);
    }

    /**
     * Duplicate campaign
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        $copy = EmailCampaignService::duplicateCampaign($campaign, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Campaign duplicated as draft.',
            'data' => $copy,
        ], 201);
    }

    /**
     * Pre-flight audience evaluation
     */
    public function estimateAudience(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $filters = $request->all();

        $result = EmailAudienceFilterService::evaluateAudience($tenantId, $filters);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Send personalized test email
     */
    public function sendTest(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $validated = $request->validate([
            'test_email' => 'required|email',
            'from_name' => 'nullable|string',
            'from_email' => 'nullable|email',
            'reply_to' => 'nullable|email',
            'subject' => 'required|string',
            'html_body' => 'required|string',
            'text_body' => 'nullable|string',
        ]);

        // HARD SECURITY RULE: Validate that From email domain is owned and verified
        $fromEmail = trim($validated['from_email'] ?? '');
        if (empty($fromEmail)) {
            $setting = \App\Models\EmailSetting::where('tenant_id', $tenantId)->first();
            $fromEmail = $setting?->from_email ?? '';
        }

        $domainService = app(\App\Services\Email\MailtrapDomainService::class);
        $domainCheck = $domainService->validateDomainForSending($tenantId, $fromEmail);
        if (!$domainCheck['allowed']) {
            return response()->json([
                'success' => false,
                'error' => $domainCheck['message'],
            ], 422);
        }

        try {
            $res = EmailCampaignService::sendTestEmail($tenantId, $validated);

            $provider = $res['provider'] ?? 'provider';
            $msg = ($provider === 'mailtrap')
                ? "Mailtrap accepted the test email for {$validated['test_email']}."
                : "Test email sent successfully to {$validated['test_email']}.";

            return response()->json([
                'success' => true,
                'message' => $msg,
                'data' => $res,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Launch or schedule campaign
     */
    public function launch(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        // HARD SECURITY RULE: Validate sending domain ownership and verification status
        $domainService = app(\App\Services\Email\MailtrapDomainService::class);
        $domainCheck = $domainService->validateDomainForSending($tenantId, $campaign->from_email, $campaign->sending_domain_id);
        if (!$domainCheck['allowed']) {
            return response()->json([
                'success' => false,
                'error' => $domainCheck['message'],
            ], 422);
        }

        $sendNow = $request->boolean('send_now', true);
        if ($request->filled('scheduled_at')) {
            $campaign->scheduled_at = Carbon::parse($request->scheduled_at);
            $campaign->save();
            $sendNow = false;
        }

        try {
            EmailCampaignService::launchCampaign($campaign, $sendNow);

            return response()->json([
                'success' => true,
                'message' => $sendNow ? 'Campaign launched! Sending emails in background.' : 'Campaign scheduled successfully.',
                'data' => $campaign->fresh(),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pause campaign
     */
    public function pause(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        EmailCampaignService::pauseCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campaign paused. Pending emails will not be sent.',
            'data' => $campaign->fresh(),
        ]);
    }

    /**
     * Resume campaign
     */
    public function resume(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        EmailCampaignService::resumeCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campaign resumed. Sending remaining emails.',
            'data' => $campaign->fresh(),
        ]);
    }

    /**
     * Cancel campaign
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        EmailCampaignService::cancelCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campaign cancelled.',
            'data' => $campaign->fresh(),
        ]);
    }

    /**
     * Detailed analytics report
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->findOrFail($id);

        // Refresh metrics
        $campaign->recalculateMetrics();
        $campaign->refresh();

        $eligible = max(1, $campTotal = ($campaign->eligible_count ?: $campaign->total_recipients));
        $sent = $campaign->sent_count;
        $delivered = $campaign->delivered_count;
        $bounced = $campaign->bounced_count;
        $failed = $campaign->failed_count;
        $unsubscribed = $campaign->unsubscribed_count;

        $deliveryRate = $eligible > 0 ? round(($delivered / $eligible) * 100, 1) : 0;
        $bounceRate = $eligible > 0 ? round(($bounced / $eligible) * 100, 1) : 0;
        $failureRate = $eligible > 0 ? round(($failed / $eligible) * 100, 1) : 0;
        $unsubRate = $eligible > 0 ? round(($unsubscribed / $eligible) * 100, 1) : 0;
        $progressPercent = $eligible > 0 ? min(100, (int) round((($sent + $failed) / $eligible) * 100)) : 0;
        if ($campaign->status === EmailCampaign::STATUS_COMPLETED) {
            $progressPercent = 100;
        }

        // Fetch paginated recipient log
        $recipientsQuery = EmailCampaignRecipient::with('contact:id,name,company,phone')
            ->where('campaign_id', $id)
            ->latest('updated_at');

        if ($request->filled('recipient_status') && $request->recipient_status !== 'all') {
            $recipientsQuery->where('status', $request->recipient_status);
        }

        $recipients = $recipientsQuery->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => [
                'campaign' => $campaign,
                'metrics' => [
                    'total_recipients' => $campaign->total_recipients,
                    'eligible_count' => $campaign->eligible_count,
                    'excluded_count' => $campaign->excluded_count,
                    'sent' => $sent,
                    'delivered' => $delivered,
                    'bounced' => $bounced,
                    'failed' => $failed,
                    'unsubscribed' => $unsubscribed,
                    'delivery_rate' => $deliveryRate,
                    'bounce_rate' => $bounceRate,
                    'failure_rate' => $failureRate,
                    'unsubscribe_rate' => $unsubRate,
                    'progress_percent' => $progressPercent,
                    'started_at' => $campaign->started_at?->format('h:i A, M d Y'),
                    'completed_at' => $campaign->completed_at?->format('h:i A, M d Y'),
                ],
                'recipients' => $recipients->items(),
                'recipients_meta' => [
                    'current_page' => $recipients->currentPage(),
                    'last_page' => $recipients->lastPage(),
                    'total' => $recipients->total(),
                ],
            ],
        ]);
    }

    /**
     * Generate email subject and body using DeepSeek AI
     */
    public function generateWithAi(Request $request): JsonResponse
    {
        $tenantId = (int)$request->user()->tenant_id;

        $validated = $request->validate([
            'topic' => 'required|string|max:1500',
            'purpose' => 'nullable|string|max:100',
            'tone' => 'nullable|string|max:100',
            'target_audience' => 'nullable|string|max:255',
            'call_to_action' => 'nullable|string|max:255',
            'api_key' => 'nullable|string',
        ]);

        $apiKey = \App\Services\Ai\DeepSeekEmailService::resolveApiKey($tenantId, $validated['api_key'] ?? null);

        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'error' => 'DeepSeek API Key is required. Please add your DeepSeek API key in Email Settings or provide it here.',
                'needs_key' => true,
            ], 422);
        }

        $service = new \App\Services\Ai\DeepSeekEmailService();
        $result = $service->generateEmail($apiKey, $validated);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'AI generation failed.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email copy generated with DeepSeek AI',
            'data' => $result,
        ]);
    }
}
