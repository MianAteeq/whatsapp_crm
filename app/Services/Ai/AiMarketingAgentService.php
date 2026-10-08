<?php

namespace App\Services\Ai;

use App\Models\AiCampaignAgentRun;
use App\Models\Category;
use App\Models\EmailCampaign;
use App\Models\EmailDeliveryEvent;
use App\Models\EmailSendingDomain;
use App\Models\EmailSetting;
use App\Models\MarketingKnowledge;
use App\Models\MarketingService;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AiMarketingAgentService
{
    protected string $endpoint = 'https://api.deepseek.com/chat/completions';
    protected string $model = 'deepseek-chat';

    public function __construct(
        protected AiMarketingAgentTools $tools
    ) {}

    /**
     * Resolve the active DeepSeek API Key (Universal platform key from .env / config).
     */
    public static function resolveDeepSeekKey(): string
    {
        $key = config('services.deepseek.api_key') ?: env('DEEPSEEK_API_KEY');
        if (empty($key)) {
            throw new RuntimeException('Universal DeepSeek API key is not configured in .env (DEEPSEEK_API_KEY).');
        }
        return trim($key);
    }

    /**
     * Send chat completion request to DeepSeek Chat
     */
    protected function callDeepSeek(array $messages, float $temperature = 0.4): string
    {
        $apiKey = self::resolveDeepSeekKey();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($this->endpoint, [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
        ]);

        if (!$response->successful()) {
            Log::error('[DeepSeek AI Agent] API Error: ' . $response->body());
            throw new RuntimeException('DeepSeek API error (' . $response->status() . '): ' . $response->body());
        }

        $content = $response->json('choices.0.message.content');
        if (empty($content)) {
            throw new RuntimeException('DeepSeek returned an empty response.');
        }

        return trim($content);
    }

    /**
     * Process Natural Language Request and Generate Campaign Plan
     */
    public function planCampaign(int $tenantId, int $userId, string $userPrompt): AiCampaignAgentRun
    {
        $userPrompt = trim($userPrompt);
        if (empty($userPrompt)) {
            throw new RuntimeException('Please enter a natural-language marketing request.');
        }

        // 1. Initialize Run Record in database
        $run = AiCampaignAgentRun::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'user_request' => $userPrompt,
            'status' => AiCampaignAgentRun::STATUS_PLANNING,
            'started_at' => now(),
            'actions_taken' => [],
        ]);

        try {
            // STEP 1: Knowledge & Context Gathering
            $knowledge = $this->tools->get_marketing_knowledge($tenantId);
            $categories = $this->tools->get_categories($tenantId);
            $services = $this->tools->get_services($tenantId);
            $verifiedDomains = $this->tools->get_verified_sending_domains($tenantId);
            $campaignHistory = $this->tools->get_campaign_history($tenantId);

            $run->appendActionLog('knowledge_loaded', 'Loaded company marketing knowledge and service catalog', 'success', [
                'services_count' => count($services),
                'categories_count' => count($categories),
            ]);

            // STEP 2: HARD SECURITY RULE: Verify sending domains
            if (empty($verifiedDomains)) {
                $errorMsg = 'No verified sending domain is available. Please verify a sending domain before sending this campaign.';
                $run->appendActionLog('domain_check_failed', $errorMsg, 'failed');
                $run->update([
                    'status' => AiCampaignAgentRun::STATUS_FAILED,
                    'errors' => $errorMsg,
                    'completed_at' => now(),
                ]);
                throw new RuntimeException($errorMsg);
            }

            // STEP 3: AI Intent Analysis via DeepSeek
            $run->appendActionLog('intent_analysis_start', 'Analyzing marketing objective and target audience with DeepSeek AI', 'running');

            $intent = $this->analyzeIntentWithDeepSeek($userPrompt, $categories, $services, $knowledge, $campaignHistory);
            
            $run->appendActionLog('intent_analyzed', "Understood objective: {$intent['objective']} for {$intent['category']} ({$intent['service']})", 'success', [
                'intent' => $intent,
            ]);

            // STEP 4: Audience Selection & Eligibility Check
            $run->appendActionLog('audience_query', "Querying CRM contacts belonging to {$intent['category']}...", 'running');

            $audienceFilters = [
                'has_email' => true,
            ];

            if (!empty($intent['matched_category_id'])) {
                $audienceFilters['category_id'] = $intent['matched_category_id'];
            } elseif (!empty($intent['category'])) {
                $audienceFilters['industry'] = $intent['category'];
            }

            if (!empty($intent['location'])) {
                $audienceFilters['location'] = $intent['location'];
            }

            if (!empty($intent['email_history'])) {
                $audienceFilters['email_history'] = $intent['email_history'];
            }

            // Support Follow-Up filter
            if (!empty($intent['is_follow_up']) && !empty($intent['previous_campaign_id'])) {
                $audienceFilters['is_follow_up'] = true;
                $audienceFilters['previous_campaign_id'] = $intent['previous_campaign_id'];
            }

            $audienceStats = $this->tools->search_contacts($tenantId, $audienceFilters);

            $run->appendActionLog('audience_evaluated', "Evaluated {$audienceStats['total_evaluated']} contacts ({$audienceStats['eligible_count']} eligible, {$audienceStats['excluded_count']} excluded)", 'success', [
                'breakdown' => $audienceStats['reasons'],
                'eligible_count' => $audienceStats['eligible_count'],
            ]);

            if ($audienceStats['eligible_count'] === 0) {
                if (!empty($audienceStats['reasons']['recently_contacted_cooldown']) && $audienceStats['total_evaluated'] > 0) {
                    $errorMsg = "Found {$audienceStats['total_evaluated']} contact(s) in Category '{$intent['category']}', but all were excluded due to the active contact cooldown period. You can adjust or set the cooldown period to 0 in Safety Limits to send immediately.";
                } else {
                    $errorMsg = "No eligible contacts found matching criteria: Category '{$intent['category']}'" . (!empty($intent['location']) ? ", Location '{$intent['location']}'" : "");
                }
                $run->appendActionLog('audience_empty', $errorMsg, 'failed');
                $run->update([
                    'status' => AiCampaignAgentRun::STATUS_FAILED,
                    'interpreted_intent' => $intent,
                    'audience_summary' => $audienceStats,
                    'errors' => $errorMsg,
                    'completed_at' => now(),
                ]);
                throw new RuntimeException($errorMsg);
            }

            // STEP 5: Select Verified Sending Domain
            $selectedDomain = $verifiedDomains[0];
            $emailSetting = EmailSetting::where('tenant_id', $tenantId)->first();
            
            // If emailSetting has a verified from_email matching one of our domains, use it
            $fromName = $emailSetting?->from_name ?: ($knowledge['company_name'] . ' Team');
            $fromEmail = $emailSetting?->from_email ?: "outreach@{$selectedDomain['domain']}";

            // Ensure From email domain matches a verified domain
            $fromDomainPart = substr(strrchr($fromEmail, "@"), 1);
            $hasMatchingDomain = false;
            foreach ($verifiedDomains as $vd) {
                if (strtolower($vd['domain']) === strtolower($fromDomainPart)) {
                    $selectedDomain = $vd;
                    $hasMatchingDomain = true;
                    break;
                }
            }
            if (!$hasMatchingDomain) {
                $fromEmail = "marketing@{$selectedDomain['domain']}";
            }

            $run->appendActionLog('domain_selected', "Selected verified sending domain: {$selectedDomain['domain']} (From: {$fromEmail})", 'success');

            // STEP 6: AI Email Content Generation
            $run->appendActionLog('content_generation_start', "Generating tailored B2B email copy for {$intent['category']} using AI...", 'running');

            $serviceData = null;
            if (!empty($intent['matched_service_id'])) {
                $serviceData = $this->tools->get_service_details($tenantId, $intent['matched_service_id']);
            }

            $emailContent = $this->generateEmailContentWithDeepSeek(
                $intent,
                $serviceData,
                $knowledge,
                $fromName
            );

            // Wrap in professional template frame
            $framedHtml = self::wrapInHtmlEmailTemplate(
                $emailContent['html_body'],
                $knowledge['company_name'] ?: 'Our Company',
                $fromName,
                $emailContent['cta_text'] ?? 'Book a Demo',
                $serviceData['cta_url'] ?? ($knowledge['demo_link'] ?? 'https://example.com/demo'),
                'executive'
            );

            $run->appendActionLog('content_generated', "Generated high-converting email: '{$emailContent['subject']}' with merge tag personalization", 'success');

            // STEP 7: Create Campaign Draft in existing EmailCampaign database
            $campaignName = $intent['campaign_name'] ?: ($intent['category'] . ' ' . $intent['service'] . ' Outreach');
            
            $campaign = $this->tools->create_campaign($tenantId, $userId, [
                'name' => $campaignName,
                'subject' => $emailContent['subject'],
                'from_name' => $fromName,
                'from_email' => $fromEmail,
                'reply_to' => $knowledge['contact_email'] ?: $fromEmail,
                'sending_domain_id' => $selectedDomain['id'],
                'html_body' => $framedHtml,
                'text_body' => $emailContent['text_body'],
                'audience_filter' => $audienceFilters,
            ]);

            $run->appendActionLog('campaign_created', "Created campaign draft #{$campaign->id}: '{$campaign->name}'", 'success');

            // STEP 8: Campaign Pre-flight Validation
            $validation = $this->tools->validate_campaign($tenantId, $campaign->id);
            if (!$validation['is_valid']) {
                $run->appendActionLog('validation_failed', "Validation failed: {$validation['error']}", 'failed');
                $run->update([
                    'status' => AiCampaignAgentRun::STATUS_FAILED,
                    'errors' => $validation['error'],
                ]);
                throw new RuntimeException($validation['error']);
            }

            $run->appendActionLog('campaign_validated', 'Validated sending domain, SPF/DKIM, recipient syntax, and tenant safety limits', 'success');

            // STEP 9: Autonomous Limits & Approval Determination
            $approvalMode = $knowledge['approval_mode'] ?? 'approval';
            $requireApprovalAbove = (int) ($knowledge['require_approval_above_recipients'] ?? 250);
            $isAutonomousEligible = ($approvalMode === 'autonomous') && ($audienceStats['eligible_count'] <= $requireApprovalAbove);

            $planSummary = [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'objective' => $intent['objective'],
                'category' => $intent['category'],
                'service' => $intent['service'],
                'location' => $intent['location'] ?? 'All Locations',
                'subject' => $emailContent['subject'],
                'preheader' => $emailContent['preheader'] ?? '',
                'from_name' => $fromName,
                'from_email' => $fromEmail,
                'cta_text' => $emailContent['cta_text'] ?? 'Book a Demo',
                'sending_domain' => $selectedDomain['domain'],
                'total_contacts' => $audienceStats['total_evaluated'],
                'contacts_with_email' => $audienceStats['contacts_with_email'],
                'eligible_count' => $audienceStats['eligible_count'],
                'excluded_count' => $audienceStats['excluded_count'],
                'exclusion_reasons' => $audienceStats['reasons'],
                'sample_eligible' => $audienceStats['sample_eligible'],
                'html_preview' => $framedHtml,
                'text_preview' => $emailContent['text_body'],
                'template_theme' => 'executive',
                'is_autonomous' => $isAutonomousEligible,
                'approval_mode' => $approvalMode,
            ];

            if ($isAutonomousEligible) {
                // Autonomous mode enabled and within safety limits -> Auto-launch via Mailtrap
                $run->appendActionLog('autonomous_dispatch', "Autonomous Mode: Eligible recipients ({$audienceStats['eligible_count']}) is within limit ({$requireApprovalAbove}). Automatically dispatching...", 'running');
                
                $this->tools->send_campaign($tenantId, $campaign->id);

                $run->appendActionLog('campaign_queued', 'Campaign approved and queued for background dispatch through Mailtrap', 'success');
                
                $run->update([
                    'campaign_id' => $campaign->id,
                    'interpreted_intent' => $intent,
                    'approval_mode' => $approvalMode,
                    'status' => AiCampaignAgentRun::STATUS_RUNNING,
                    'recipients_selected' => $audienceStats['eligible_count'],
                    'recipients_excluded' => $audienceStats['excluded_count'],
                    'audience_summary' => $audienceStats,
                    'campaign_plan' => $planSummary,
                    'completed_at' => now(),
                ]);
            } else {
                // Requires human review and explicit approval
                $reasonLabel = ($approvalMode === 'autonomous')
                    ? "Requires approval because recipient count ({$audienceStats['eligible_count']}) exceeds autonomous limit ({$requireApprovalAbove})"
                    : "Campaign ready for human review and explicit approval";

                $run->appendActionLog('awaiting_approval', $reasonLabel, 'success');

                $run->update([
                    'campaign_id' => $campaign->id,
                    'interpreted_intent' => $intent,
                    'approval_mode' => $approvalMode,
                    'status' => AiCampaignAgentRun::STATUS_AWAITING_APPROVAL,
                    'recipients_selected' => $audienceStats['eligible_count'],
                    'recipients_excluded' => $audienceStats['excluded_count'],
                    'audience_summary' => $audienceStats,
                    'campaign_plan' => $planSummary,
                ]);
            }

            return $run->fresh();

        } catch (Throwable $e) {
            Log::error('[AiMarketingAgent] Planning error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $run->appendActionLog('run_error', 'Execution error: ' . $e->getMessage(), 'failed');
            $run->update([
                'status' => AiCampaignAgentRun::STATUS_FAILED,
                'errors' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * DeepSeek Intent Analysis Prompt
     */
    protected function analyzeIntentWithDeepSeek(
        string $userPrompt,
        array $categories,
        array $services,
        array $knowledge,
        array $campaignHistory
    ): array {
        $catList = array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $categories);
        $svcList = array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'target_industries' => $s['target_industries']], $services);

        $systemPrompt = <<<PROMPT
You are an advanced AI Marketing Agent for a B2B SaaS CRM platform.
Your task is to analyze natural language marketing instructions from users and extract structured campaign intent.

CRITICAL INSTRUCTIONS:
1. Identify:
   - "category": Target industry/category name (e.g. Real Estate, Dental Clinics, Automotive). Match to available categories if possible.
   - "matched_category_id": ID of existing matching category or null.
   - "service": Product/service to promote (e.g. AI Chatbot, AI Receptionist, WhatsApp Automation). Match to available services if possible.
   - "matched_service_id": ID of matching service or null.
   - "objective": Campaign objective (e.g. Lead Generation, Service Promotion, Appointment Requests, Cold Outreach, Follow-up).
   - "location": Specific city/region mentioned (e.g. Lahore, New York) or null if none mentioned.
   - "email_history": One of ["never_sent", "previously_sent", "all", "follow_up"]. If user says "never received an email" or "not contacted before", use "never_sent".
   - "is_follow_up": true if user specifically asks to follow up on a previous campaign, otherwise false.
   - "campaign_name": A crisp, professional internal campaign title.
   - "channel": Always "Email".
   - "action": "Create and send campaign".

2. Available CRM Categories:
PROMPT;
        $systemPrompt .= json_encode($catList, JSON_PRETTY_PRINT) . "\n\n3. Available Services:\n" . json_encode($svcList, JSON_PRETTY_PRINT);
        $systemPrompt .= "\n\n4. You MUST respond with ONLY a valid raw JSON object matching this schema without markdown fences:\n";
        $systemPrompt .= <<<JSON
{
  "category": "Real Estate",
  "matched_category_id": 1,
  "service": "AI Chatbot",
  "matched_service_id": 1,
  "objective": "Lead Generation",
  "location": null,
  "email_history": "all",
  "is_follow_up": false,
  "campaign_name": "Real Estate AI Chatbot Outreach",
  "channel": "Email",
  "action": "Create and send campaign"
}
JSON;

        $userMessage = "User Instruction: \"{$userPrompt}\"";

        $response = $this->callDeepSeek([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ], 0.2);

        $cleaned = trim($response);
        if (str_starts_with($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        }

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded)) {
            Log::warning('[AiMarketingAgent] Failed to decode DeepSeek intent JSON: ' . $response);
            // Fallback parsing
            return [
                'category' => 'Real Estate',
                'matched_category_id' => $categories[0]['id'] ?? null,
                'service' => 'AI Chatbot',
                'matched_service_id' => $services[0]['id'] ?? null,
                'objective' => 'Lead Generation',
                'location' => null,
                'email_history' => 'all',
                'is_follow_up' => false,
                'campaign_name' => 'AI Marketing Outreach',
                'channel' => 'Email',
                'action' => 'Create and send campaign',
            ];
        }

        // Try fuzzy matching category name if ID was not matched
        if (empty($decoded['matched_category_id']) && !empty($decoded['category'])) {
            $catLower = strtolower(trim($decoded['category']));
            foreach ($categories as $cat) {
                if (str_contains(strtolower($cat['name']), $catLower) || str_contains($catLower, strtolower($cat['name']))) {
                    $decoded['matched_category_id'] = $cat['id'];
                    $decoded['category'] = $cat['name'];
                    break;
                }
            }
        }

        // Try fuzzy matching service
        if (empty($decoded['matched_service_id']) && !empty($decoded['service'])) {
            $svcLower = strtolower(trim($decoded['service']));
            foreach ($services as $svc) {
                if (str_contains(strtolower($svc['name']), $svcLower) || str_contains($svcLower, strtolower($svc['name']))) {
                    $decoded['matched_service_id'] = $svc['id'];
                    $decoded['service'] = $svc['name'];
                    break;
                }
            }
        }

        return $decoded;
    }

    /**
     * DeepSeek Content Generation Prompt with Industry Pain Points & Merge Tags
     */
    protected function generateEmailContentWithDeepSeek(
        array $intent,
        ?array $service,
        array $knowledge,
        string $fromName
    ): array {
        $category = $intent['category'] ?? 'Business';
        $serviceName = $service['name'] ?? ($intent['service'] ?? 'Business Automation');
        $features = !empty($service['features']) ? implode(', ', $service['features']) : '24/7 responsiveness, instant lead qualification, automated workflow integration';
        $benefits = !empty($service['benefits']) ? implode(', ', $service['benefits']) : 'Faster lead response, higher conversion rates, streamlined operations';
        $ctaText = $service['cta_text'] ?? 'Book a 15-Minute Demo';
        $ctaUrl = $service['cta_url'] ?? ($knowledge['demo_link'] ?? 'https://example.com/demo');
        $brandVoice = $knowledge['brand_voice'] ?? 'Professional, consultative, and results-focused';
        $companyName = $knowledge['company_name'] ?? 'Our Company';

        $systemPrompt = <<<PROMPT
You are an elite B2B email copywriter specializing in high-converting cold email outreach and lead generation.
Generate an engaging, personalized email for prospects in the {$category} industry promoting {$serviceName}.

INDUSTRY PAIN POINTS TO ADDRESS:
- Real Estate: Slow response to property inquiries, missed buyer/renter leads after hours, manual lead qualification.
- Dental Clinics: Overwhelmed front reception, missed appointment bookings, patients not getting instant answers to treatment questions.
- Automotive: Missed test drive requests, slow responses to vehicle availability questions, lead follow-up delays.
- General B2B / Software: Lead leakage, slow follow-up cycles, manual repetitive administrative overhead.

COMPANY CONTEXT:
- Company Name: {$companyName}
- Service: {$serviceName}
- Key Features: {$features}
- Core Benefits: {$benefits}
- Desired CTA: {$ctaText} ({$ctaUrl})
- Brand Voice: {$brandVoice}

CRITICAL RULES:
1. ONLY use valid personalization merge tags:
   - {{first_name}}
   - {{last_name}}
   - {{business_name}}
   - {{company}}
   - {{job_title}}
   - {{city}}
2. Keep the email concise (120 to 200 words), focused on value and outcome, with no robotic or spammy cliches.
3. Include a prominent, elegant CTA button linking to {$ctaUrl}.
4. Provide BOTH "subject", "preheader", "html_body", and "text_body".
5. Do NOT invent fake customer testimonials or fake statistics.
6. You MUST respond with ONLY a valid raw JSON object with NO markdown code fences:
{
  "subject": "A smarter way to handle property inquiries for {{business_name}}",
  "preheader": "Turn more website visitors into qualified leads automatically.",
  "cta_text": "{$ctaText}",
  "html_body": "<p>Hi {{first_name}},</p><p>...</p><p><a href=\"{$ctaUrl}\" style=\"display:inline-block;padding:12px 24px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;\">{$ctaText}</a></p><p>Best regards,<br>{$fromName}</p>",
  "text_body": "Hi {{first_name}},\n\n...\n\n{$ctaText}: {$ctaUrl}\n\nBest regards,\n{$fromName}"
}
PROMPT;

        $userPrompt = "Objective: {$intent['objective']}. Target Industry: {$category}. Generate a personalized B2B outreach email.";

        $response = $this->callDeepSeek([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ], 0.3);

        $cleaned = trim($response);
        if (str_starts_with($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        }

        $decoded = json_decode($cleaned, true);
        if (!is_array($decoded) || empty($decoded['subject']) || empty($decoded['html_body'])) {
            Log::warning('[AiMarketingAgent] Failed to decode email content JSON: ' . $response);
            return [
                'subject' => "A smarter way to scale {$category} inquiries with {$serviceName}",
                'preheader' => "Automate responses and capture qualified leads 24/7.",
                'cta_text' => $ctaText,
                'html_body' => "<p>Hi {{first_name}},</p><p>I noticed {{business_name}} and wanted to reach out regarding a proven way to automate inquiry responses and capture qualified leads around the clock.</p><p>With our {$serviceName}, you can ensure every inquiry receives an immediate, intelligent response, significantly boosting your team's conversion rate.</p><p style=\"margin: 24px 0;\"><a href=\"{$ctaUrl}\" style=\"display: inline-block; background-color: #4f46e5; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600;\">{$ctaText}</a></p><p>Best regards,<br>{$fromName}</p>",
                'text_body' => "Hi {{first_name}},\n\nI noticed {{business_name}} and wanted to reach out regarding a proven way to automate inquiry responses with {$serviceName}.\n\n{$ctaText}: {$ctaUrl}\n\nBest regards,\n{$fromName}",
            ];
        }

        return $decoded;
    }

    /**
     * Explicit Human Approval & Send/Schedule
     */
    public function approveAndSend(int $tenantId, int $runId, bool $sendNow = true, ?string $scheduledAt = null): AiCampaignAgentRun
    {
        $run = AiCampaignAgentRun::where('tenant_id', $tenantId)->findOrFail($runId);

        if (!$run->campaign_id) {
            throw new RuntimeException('No associated campaign found for this agent run.');
        }

        $run->appendActionLog('human_approval', 'Campaign explicitly approved by user', 'success');

        if ($sendNow) {
            $run->appendActionLog('dispatching', 'Queueing campaign emails for background delivery via Mailtrap...', 'running');
            $this->tools->send_campaign($tenantId, $run->campaign_id);
            $run->appendActionLog('dispatched', 'Campaign launched successfully! Background workers are processing recipients.', 'success');

            $run->update([
                'status' => AiCampaignAgentRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);
        } else {
            if (empty($scheduledAt)) {
                throw new RuntimeException('Scheduled date and time is required.');
            }
            $run->appendActionLog('scheduling', "Scheduling campaign for {$scheduledAt}...", 'running');
            $this->tools->schedule_campaign($tenantId, $run->campaign_id, $scheduledAt);
            $run->appendActionLog('scheduled', "Campaign scheduled for {$scheduledAt}", 'success');

            $run->update([
                'status' => AiCampaignAgentRun::STATUS_COMPLETED,
            ]);
        }

        return $run->fresh();
    }

    /**
     * Cancel an agent run
     */
    public function cancelRun(int $tenantId, int $runId): AiCampaignAgentRun
    {
        $run = AiCampaignAgentRun::where('tenant_id', $tenantId)->findOrFail($runId);
        
        if ($run->campaign_id) {
            $campaign = EmailCampaign::where('tenant_id', $tenantId)->find($run->campaign_id);
            if ($campaign && in_array($campaign->status, [EmailCampaign::STATUS_DRAFT, EmailCampaign::STATUS_SCHEDULED, EmailCampaign::STATUS_SENDING])) {
                \App\Services\Email\EmailCampaignService::cancelCampaign($campaign);
            }
        }

        $run->appendActionLog('cancelled', 'Agent run cancelled by user', 'success');
        $run->update([
            'status' => AiCampaignAgentRun::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);

        return $run->fresh();
    }

    /**
     * Post-Campaign DeepSeek Performance Analysis (Requirement 24 & 26)
     */
    public function analyzeCampaignPerformance(int $tenantId, int $runId): array
    {
        $run = AiCampaignAgentRun::where('tenant_id', $tenantId)->findOrFail($runId);
        if (!$run->campaign_id) {
            throw new RuntimeException('No campaign associated with this run to analyze.');
        }

        $stats = $this->tools->get_campaign_results($tenantId, $run->campaign_id);
        $campaign = EmailCampaign::where('tenant_id', $tenantId)->find($run->campaign_id);

        $systemPrompt = <<<PROMPT
You are a senior email deliverability and conversion rate optimization specialist.
Analyze the following email marketing campaign performance metrics and provide actionable, executive-level insights.

CRITICAL INSTRUCTIONS:
1. "summary": Concise 2-sentence summary of overall campaign performance.
2. "delivery_analysis": Assessment of delivery rate ({$stats['delivery_rate']}%) and bounce rate ({$stats['bounce_rate']}%).
3. "engagement_analysis": Assessment of open rate ({$stats['open_rate']}%) and click rate ({$stats['click_rate']}%).
4. "key_learnings": Array of 3 bullet points analyzing what worked and what audience segment responded best.
5. "recommended_changes": Array of 3 specific recommendations for subsequent outreach.
6. "follow_up_recommendation": Whether a follow-up is recommended, who to target (e.g., opened but did not click), and suggested angle.
7. Return raw JSON matching this schema:
{
  "summary": "...",
  "delivery_analysis": "...",
  "engagement_analysis": "...",
  "key_learnings": ["...", "..."],
  "recommended_changes": ["...", "..."],
  "follow_up_recommendation": {
    "recommended": true,
    "target_segment": "Opened but did not click",
    "suggested_angle": "Offer a short 2-minute video case study instead of a call"
  }
}
PROMPT;

        $promptInput = "Campaign: {$stats['name']}\nSent: {$stats['sent']}\nDelivered: {$stats['delivered']}\nBounced: {$stats['bounced']}\nOpens: {$stats['opens']}\nClicks: {$stats['clicks']}\nUnsubscribes: {$stats['unsubscribed']}";

        $response = $this->callDeepSeek([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $promptInput],
        ], 0.3);

        $cleaned = trim($response);
        if (str_starts_with($cleaned, '```')) {
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        }

        $analysis = json_decode($cleaned, true) ?: [
            'summary' => "Campaign sent to {$stats['sent']} recipients with {$stats['delivered']} successfully delivered ({$stats['delivery_rate']}%).",
            'delivery_analysis' => "Deliverability was solid with a bounce rate of {$stats['bounce_rate']}%.",
            'engagement_analysis' => "Open rate reached {$stats['open_rate']}% and click rate reached {$stats['click_rate']}%.",
            'key_learnings' => ['Target industry showed interest in automation capabilities.'],
            'recommended_changes' => ['Test shorter subject line variations for the next batch.'],
            'follow_up_recommendation' => [
                'recommended' => true,
                'target_segment' => 'Contacts who received the email but have not booked a demo',
                'suggested_angle' => 'Send a gentle follow-up providing an interactive product video link.',
            ],
        ];

        $analysisResult = [
            'metrics' => $stats,
            'ai_insights' => $analysis,
            'analyzed_at' => now()->toIso8601String(),
        ];

        $run->update(['performance_analysis' => $analysisResult]);
        $run->appendActionLog('performance_analyzed', 'Post-campaign performance analyzed with DeepSeek AI', 'success');

        return $analysisResult;
    }

    /**
     * Wrap raw email body in a responsive HTML email template frame
     */
    public static function wrapInHtmlEmailTemplate(
        string $bodyHtml,
        string $companyName,
        string $fromName,
        string $ctaText = 'Book a Demo',
        string $ctaUrl = 'https://example.com',
        string $theme = 'executive'
    ): string {
        $headerBg = match ($theme) {
            'saas' => 'linear-gradient(135deg, #4338ca 0%, #6366f1 100%)',
            'emerald' => '#064e3b',
            'minimal' => '#ffffff',
            default => '#0f172a',
        };

        $headerBorder = match ($theme) {
            'saas' => 'none',
            'emerald' => '3px solid #10b981',
            'minimal' => '2px solid #e2e8f0',
            default => '3px solid #3b82f6',
        };

        $headerTextColor = ($theme === 'minimal') ? '#0f172a' : '#ffffff';
        $badgeText = ($theme === 'minimal') ? 'Private Briefing' : 'Verified Business Outreach';
        $badgeColor = ($theme === 'minimal') ? '#64748b' : '#60a5fa';
        $badgeBg = ($theme === 'minimal') ? 'transparent' : 'rgba(255,255,255,0.15)';

        return <<<HTML
<div style="background-color: #f1f5f9; padding: 32px 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
  <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; overflow: hidden;">
    <div style="background: {$headerBg}; padding: 26px 32px; border-bottom: {$headerBorder};">
      <table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse;">
        <tr>
          <td>
            <span style="color: {$headerTextColor}; font-size: 18px; font-weight: 800; text-transform: uppercase; letter-spacing: -0.3px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">{$companyName}</span>
            <span style="display: block; color: #94a3b8; font-size: 12px; margin-top: 3px; font-weight: 500;">Direct Solutions & Advisory</span>
          </td>
          <td align="right">
            <span style="display: inline-block; background-color: {$badgeBg}; color: {$badgeColor}; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 16px; text-transform: uppercase; letter-spacing: 0.5px;">{$badgeText}</span>
          </td>
        </tr>
      </table>
    </div>

    <!-- EMAIL_BODY_START -->
    <div style="padding: 36px 32px; color: #1e293b; font-size: 14px; line-height: 1.7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
      {$bodyHtml}
    </div>
    <!-- EMAIL_BODY_END -->

    <div style="background-color: #f8fafc; padding: 24px 32px; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 11px; line-height: 1.6;">
      <p style="margin: 0 0 6px 0; color: #64748b; font-size: 12px;">You received this business email regarding <strong>{{business_name}}</strong>.</p>
      <p style="margin: 0 0 10px 0;">Sent by {$fromName} &bull; {$companyName} &bull; All rights reserved.</p>
      <p style="margin: 0;"><a href="{{unsubscribe_url}}" style="color: #64748b; text-decoration: underline; font-weight: 600;">Unsubscribe from marketing emails</a></p>
    </div>
  </div>
</div>
HTML;
    }
}
