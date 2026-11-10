<?php

declare(strict_types=1);

/**
 * API Routes
 * 
 * All API routes are defined here. These routes are loaded by the RouteServiceProvider
 * and include authentication, contact management, conversations, WhatsApp messaging, and webhooks.
 */

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\WhatsappMessageController;
use App\Http\Controllers\Api\WhatsappSettingController;
use App\Http\Controllers\Api\WhatsappTemplateController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Api\SystemSettingsController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\EmailCampaignController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\EmailSettingController;
use App\Http\Controllers\Api\EmailTrackingController;
use App\Http\Controllers\Api\Social\SocialAccountController;
use App\Http\Controllers\Api\Social\SocialPostController;
use App\Http\Controllers\Api\Social\SocialInboxController;
use App\Http\Controllers\Api\Social\SocialAnalyticsController;
use App\Http\Controllers\Api\Social\SocialMediaUploadController;
use App\Http\Controllers\Api\Social\SocialWebhookController;
use App\Http\Controllers\Api\Social\SocialAiController;
use App\Http\Controllers\Api\Social\MetaLeadAdsController;
use App\Http\Controllers\Api\Builder\PageBuilderController;
use App\Http\Controllers\Api\Builder\FormBuilderController;
use App\Http\Controllers\Api\Builder\SubmissionController;
use App\Http\Controllers\Api\Builder\TemplateController;
use App\Http\Controllers\Api\Builder\PublicBuilderController;
use App\Http\Controllers\Api\Media\MediaController;
use App\Http\Controllers\Api\Reporting\AdvancedReportingController;
use Illuminate\Support\Facades\Route;

// ============================================
// Public Routes (Authentication)
// ============================================

/**
 * User registration route
 */
Route::post('/register', [AuthController::class, 'register']);

/**
 * User login route
 */
Route::post('/login', [AuthController::class, 'login']);

/**
 * Email verification routes
 */
Route::post('/email/verify', [AuthController::class, 'verifyEmail']);
Route::post('/email/resend-verification', [AuthController::class, 'resendVerification']);

/**
 * Public branding settings route
 */
Route::get('/branding', [SystemSettingsController::class, 'branding']);

/**
 * Public plans route
 */
Route::get('/plans', [SystemSettingsController::class, 'publicPlans']);

/**
 * Public email tracking & unsubscribe routes
 */
Route::get('/email/unsubscribe', [EmailTrackingController::class, 'unsubscribe']);
Route::post('/webhook/email/{provider}', [EmailTrackingController::class, 'webhook']);

/**
 * Public Mailtrap webhook delivery notification routes
 */
Route::post('/webhooks/mailtrap', [\App\Http\Controllers\Api\MailtrapWebhookController::class, 'handle']);
Route::post('/webhook/mailtrap', [\App\Http\Controllers\Api\MailtrapWebhookController::class, 'handle']);

/**
 * Public Meta OAuth callback route for Social Media Planner
 */
Route::get('/v1/social/oauth/callback', [SocialAccountController::class, 'oauthCallback']);
Route::get('/social/oauth/callback', [SocialAccountController::class, 'oauthCallback']);
Route::get('/social/facebook/callback', [SocialAccountController::class, 'oauthCallback']);

/**
 * Public Meta Webhook routes for Facebook Messenger & Instagram Direct
 */
Route::get('/v1/social/webhook', [SocialWebhookController::class, 'verify']);
Route::post('/v1/social/webhook', [SocialWebhookController::class, 'handle']);
Route::get('/social/webhook', [SocialWebhookController::class, 'verify']);
Route::post('/social/webhook', [SocialWebhookController::class, 'handle']);

/**
 * Public CORS-enabled Media Stream route for video frame seeking and thumbnails
 */
Route::get('/v1/social/media/stream', [SocialMediaUploadController::class, 'stream']);
Route::options('/v1/social/media/stream', function () {
    return response('', 204)->withHeaders([
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
        'Access-Control-Allow-Headers' => 'Range, Content-Type, Accept, Origin',
    ]);
});

/**
 * Public Page & Form Builder Routes (Landing Pages, Standalone Forms, Embeds & Submissions)
 */
Route::get('/v1/public/builder/page/{slug}', [PublicBuilderController::class, 'showPage']);
Route::get('/v1/public/builder/form/{slug}', [PublicBuilderController::class, 'showForm']);
Route::post('/v1/public/builder/submit/{slug}', [PublicBuilderController::class, 'submit']);

Route::get('/public/builder/page/{slug}', [PublicBuilderController::class, 'showPage']);
Route::get('/public/builder/form/{slug}', [PublicBuilderController::class, 'showForm']);
Route::post('/public/builder/submit/{slug}', [PublicBuilderController::class, 'submit']);

// ============================================
// Protected Routes (Require Authentication)
// ============================================

Route::middleware('auth:sanctum')->group(function () {
    // Real-Time WebSocket Authentication
    Route::match(['get', 'post'], '/broadcasting/auth', '\Illuminate\Broadcasting\BroadcastController@authenticate');
    Route::match(['get', 'post'], '/proxy/broadcasting/auth', '\Illuminate\Broadcasting\BroadcastController@authenticate');
    /**
     * User logout route
     */
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/password', [AuthController::class, 'changePassword']);
    Route::post('/user/avatar', [AuthController::class, 'updateAvatar']);
    Route::get('/user/notifications', [DashboardController::class, 'notifications']);

     Route::get(

            '/dashboard/insights',

            [DashboardController::class, 'insights']

        );

    // ---- Contact Management ----

    /**
     * Advanced search for contacts with filters
     */
    Route::get('contacts/advance-search', [ContactController::class, 'advanceSearch']);

    /**
     * Bulk delete contacts
     */
    Route::post('contacts/batch-delete', [ContactController::class, 'batchDelete']);

    /**
     * Contact CRUD operations (Create, Read, Update, Delete)
     */
    Route::apiResource('contacts', ContactController::class);

    /**
     * Import contacts from file
     */
    Route::post('contacts/import', [ContactController::class, 'import']);

    // ---- Tag Management ----

    /**
     * Tag CRUD operations
     */
    Route::apiResource('tags', TagController::class);

    // ---- Category Management (Category-Based CRM Architecture) ----
    Route::get('categories/{id}/email-stats', [\App\Http\Controllers\Api\CategoryController::class, 'emailStats']);
    Route::apiResource('categories', \App\Http\Controllers\Api\CategoryController::class);

    // ---- WhatsApp Settings ----

    /**
     * Connect via Meta Embedded Signup OAuth (exchanges code for token)
     */
    Route::post('whatsapp/connect', [WhatsappSettingController::class, 'connect']);

    /**
     * Disconnect WhatsApp (alias: delete settings)
     */
    Route::delete('whatsapp/settings', [WhatsappSettingController::class, 'destroy'])->name('whatsapp.disconnect');

    /**
     * Create or update WhatsApp settings
     */
    Route::post('whatsapp/settings', [WhatsappSettingController::class, 'store']);

    /**
     * Create or update WhatsApp AI settings
     */
    Route::post('whatsapp/settings/ai', [WhatsappSettingController::class, 'updateAiSettings']);

    /**
     * Retrieve WhatsApp settings
     */
    Route::get('whatsapp/settings', [WhatsappSettingController::class, 'show']);

    /**
     * Test WhatsApp connection
     */
    Route::post('whatsapp/test', [WhatsappSettingController::class, 'testConnection']);

    /**
     * Retrieve WhatsApp dashboard stats
     */
    Route::get('whatsapp/dashboard-stats', [WhatsappSettingController::class, 'dashboardStats']);

    /**
     * Register phone number with Meta Cloud API
     */
    Route::post('whatsapp/register', [WhatsappSettingController::class, 'registerNumber']);

    /**
     * Subscribe WABA to Meta App and verify
     */
    Route::post('whatsapp/subscribe-waba', [WhatsappSettingController::class, 'subscribeWaba']);
    Route::get('whatsapp/subscribe-waba/status', [WhatsappSettingController::class, 'checkWabaSubscription']);

    /**
     * Retrieve WhatsApp business profile (logo, description, about) from Meta
     */
    Route::get('whatsapp/profile', [WhatsappSettingController::class, 'getProfile']);

    /**
     * Update WhatsApp business profile (description, about)
     */
    Route::post('whatsapp/profile', [WhatsappSettingController::class, 'updateProfile']);

    /**
     * Upload WhatsApp business profile logo
     */
    Route::post('whatsapp/profile/logo', [WhatsappSettingController::class, 'uploadLogo']);


    // ---- Conversations & Messages ----

    /**
     * Retrieve all conversations
     */
    Route::get('conversations', [ConversationController::class, 'index']);

    /**
     * Retrieve messages for a specific conversation
     */
    Route::get('conversations/{id}/messages', [ConversationController::class, 'messages']);

    /**
     * Mark conversation as read
     */
    Route::post('conversations/{id}/mark-read', [ConversationController::class, 'markRead']);

    /**
     * Toggle auto reply for a specific conversation
     */
    Route::post('conversations/{id}/toggle-auto-reply', [ConversationController::class, 'toggleAutoReply']);

    /**
     * Send a text message via WhatsApp
     */
    Route::post('messages/send', [WhatsappMessageController::class, 'send']);

    /**
     * Simulate an incoming message from a contact
     */
    Route::post('messages/simulate-incoming', [WhatsappMessageController::class, 'simulateIncoming']);

    /**
     * Send a media message via WhatsApp
     */
    Route::post('messages/send-media', [WhatsappMessageController::class, 'sendMedia']);

    // ---- WhatsApp Templates ----

    /**
     * Sync WhatsApp templates with Meta
     */
    Route::get('whatsapp/templates/sync', [WhatsappTemplateController::class, 'sync']);

    /**
     * Retrieve all WhatsApp templates
     */
    Route::get('whatsapp/templates', [WhatsappTemplateController::class, 'index']);

    /**
     * Send a message using a WhatsApp template
     */

    Route::post('whatsapp/templates/send', [WhatsappMessageController::class, 'sendTemplate']);

    Route::post(

        'whatsapp/templates/create',

        [WhatsappTemplateController::class, 'store']

    );

    Route::post(

        'whatsapp/templates/upload-media',

        [WhatsappTemplateController::class, 'uploadMedia']

    );

    Route::put('/whatsapp/templates/{id}', [WhatsappTemplateController::class, 'update']);

    Route::delete('/whatsapp/templates/{id}', [WhatsappTemplateController::class, 'destroy']);
    Route::get(
        '/whatsapp/performance-insights',
        [WhatsappTemplateController::class, 'performanceInsights']
    );

    // Campaign routes (Bonus Challenge)

    Route::post(

        'campaigns',

        [CampaignController::class, 'store']

    );

    Route::get(

        'campaigns/dashboard',

        [CampaignController::class, 'dashboard']

    );

    Route::get(

        'campaign/list',

        [CampaignController::class, 'index']

    );

    Route::get(

        'campaigns/{id}',

        [CampaignController::class, 'show']

    );

    Route::delete(

        'campaigns/{id}',

        [CampaignController::class, 'destroy']

    );

    // ---- Automation Routes ----
    Route::get('automation/workflows', [AutomationController::class, 'index']);
    Route::post('automation/workflows', [AutomationController::class, 'store']);
    Route::get('automation/workflows/{id}', [AutomationController::class, 'show']);
    Route::put('automation/workflows/{id}', [AutomationController::class, 'update']);
    Route::delete('automation/workflows/{id}', [AutomationController::class, 'destroy']);
    Route::post('automation/workflows/{id}/canvas', [AutomationController::class, 'saveCanvas']);
    Route::get('automation/executions', [AutomationController::class, 'executions']);
    Route::get('automation/dashboard-stats', [AutomationController::class, 'dashboardStats']);

    // ---- SaaS Super Admin Panel Routes ----
    Route::middleware('superadmin')->prefix('admin')->group(function () {
        Route::get('stats', [\App\Http\Controllers\Api\SuperAdminController::class, 'dashboardStats']);
        
        Route::get('tenants', [\App\Http\Controllers\Api\SuperAdminController::class, 'tenantsIndex']);
        Route::put('tenants/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'updateTenant']);
        Route::delete('tenants/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'deleteTenant']);
        
        Route::get('users', [\App\Http\Controllers\Api\SuperAdminController::class, 'usersIndex']);
        Route::put('users/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'updateUser']);
        Route::delete('users/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'deleteUser']);
        
        Route::get('plans', [\App\Http\Controllers\Api\SuperAdminController::class, 'plansIndex']);
        Route::post('plans', [\App\Http\Controllers\Api\SuperAdminController::class, 'createPlan']);
        Route::put('plans/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'updatePlan']);
        Route::delete('plans/{id}', [\App\Http\Controllers\Api\SuperAdminController::class, 'deletePlan']);
        
        Route::get('settings', [\App\Http\Controllers\Api\SuperAdminController::class, 'settingsIndex']);
        Route::post('settings', [\App\Http\Controllers\Api\SuperAdminController::class, 'updateSettings']);
        
        // Comprehensive System Settings routes
        Route::get('settings/system', [\App\Http\Controllers\Api\SystemSettingsController::class, 'index']);
        Route::post('settings/system', [\App\Http\Controllers\Api\SystemSettingsController::class, 'update']);
        Route::post('settings/system/test-email', [\App\Http\Controllers\Api\SystemSettingsController::class, 'testEmail']);
        Route::post('settings/system/clear-cache', [\App\Http\Controllers\Api\SystemSettingsController::class, 'clearCache']);
        Route::post('settings/system/optimize', [\App\Http\Controllers\Api\SystemSettingsController::class, 'optimize']);
        Route::get('settings/system/audit-logs', [\App\Http\Controllers\Api\SystemSettingsController::class, 'auditLogs']);
    });

    // ---- Business Information Scraper Routes ----
    Route::prefix('scraper')->group(function () {
        Route::get('status', [\App\Http\Controllers\Api\ScraperController::class, 'status']);
        Route::get('category-stats', [\App\Http\Controllers\Api\ScraperController::class, 'categoryStats']);
        Route::get('searches', [\App\Http\Controllers\Api\ScraperController::class, 'searches']);
        Route::post('searches', [\App\Http\Controllers\Api\ScraperController::class, 'storeSearch']);
        Route::get('searches/{id}', [\App\Http\Controllers\Api\ScraperController::class, 'getSearch']);
        Route::delete('searches/{id}', [\App\Http\Controllers\Api\ScraperController::class, 'deleteSearch']);
        Route::post('searches/{id}/process-now', [\App\Http\Controllers\Api\ScraperController::class, 'processNow']);
        Route::get('results', [\App\Http\Controllers\Api\ScraperController::class, 'results']);
        Route::get('results/{id}', [\App\Http\Controllers\Api\ScraperController::class, 'showResult']);
        Route::delete('results/{id}', [\App\Http\Controllers\Api\ScraperController::class, 'deleteResult']);
        Route::post('results/batch-delete', [\App\Http\Controllers\Api\ScraperController::class, 'batchDeleteResults']);
        Route::post('results/import-crm', [\App\Http\Controllers\Api\ScraperController::class, 'importToCrm']);
        Route::post('results/{id}/enrich', [\App\Http\Controllers\Api\ScraperController::class, 'enrichLead']);
        Route::post('results/bulk-enrich', [\App\Http\Controllers\Api\ScraperController::class, 'bulkEnrichLeads']);
        Route::get('results/{id}/enrichment-logs', [\App\Http\Controllers\Api\ScraperController::class, 'getEnrichmentLogs']);
        Route::get('export-csv', [\App\Http\Controllers\Api\ScraperController::class, 'exportCsv']);
    });

    // ---- Email Campaigns, Templates, & Provider Settings ----
    Route::get('contacts/{id}/email-activities', [ContactController::class, 'emailActivities']);
    Route::get('contacts/{id}/scraper-history', [ContactController::class, 'scraperHistory']);

    // Email Settings
    Route::get('email/settings', [EmailSettingController::class, 'show']);
    Route::post('email/settings', [EmailSettingController::class, 'store']);
    Route::post('email/settings/test', [EmailSettingController::class, 'testConnection']);

    // Email Sending Domains (Mailtrap Multi-tenant Verification)
    Route::get('email/sending-domains', [\App\Http\Controllers\Api\EmailSendingDomainController::class, 'index']);
    Route::post('email/sending-domains', [\App\Http\Controllers\Api\EmailSendingDomainController::class, 'store']);
    Route::get('email/sending-domains/{id}', [\App\Http\Controllers\Api\EmailSendingDomainController::class, 'show']);
    Route::post('email/sending-domains/{id}/verify', [\App\Http\Controllers\Api\EmailSendingDomainController::class, 'verify']);
    Route::delete('email/sending-domains/{id}', [\App\Http\Controllers\Api\EmailSendingDomainController::class, 'destroy']);
    Route::get('email/sending-domains/{id}/logs', [\App\Http\Controllers\Api\EmailSendingDomainController::class, 'domainLogs']);
    Route::get('admin/email/sending-domains', [\App\Http\Controllers\Api\EmailSendingDomainController::class, 'adminIndex']);

    // Email Templates
    Route::post('email/templates/{id}/duplicate', [EmailTemplateController::class, 'duplicate']);
    Route::apiResource('email/templates', EmailTemplateController::class);

    // Email Campaigns
    Route::post('email/ai/generate', [EmailCampaignController::class, 'generateWithAi']);
    Route::post('email/campaigns/estimate-audience', [EmailCampaignController::class, 'estimateAudience']);
    Route::post('email/campaigns/send-test', [EmailCampaignController::class, 'sendTest']);
    Route::post('email/campaigns/{id}/launch', [EmailCampaignController::class, 'launch']);
    Route::post('email/campaigns/{id}/pause', [EmailCampaignController::class, 'pause']);
    Route::post('email/campaigns/{id}/resume', [EmailCampaignController::class, 'resume']);
    Route::post('email/campaigns/{id}/cancel', [EmailCampaignController::class, 'cancel']);
    Route::post('email/campaigns/{id}/duplicate', [EmailCampaignController::class, 'duplicate']);
    Route::get('email/campaigns/{id}/report', [EmailCampaignController::class, 'report']);
    Route::apiResource('email/campaigns', EmailCampaignController::class);

    // AI Marketing Agent Routes (DeepSeek Powered)
    Route::post('email/ai-agent/plan', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'plan']);
    Route::get('email/ai-agent/runs', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'runs']);
    Route::get('email/ai-agent/runs/{id}', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'showRun']);
    Route::post('email/ai-agent/runs/{id}/approve', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'approve']);
    Route::post('email/ai-agent/runs/{id}/cancel', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'cancel']);
    Route::post('email/ai-agent/runs/{id}/analyze', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'analyze']);
    Route::put('email/ai-agent/runs/{id}/campaign', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'updateRunCampaign']);
    Route::get('email/ai-agent/knowledge', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'getKnowledge']);
    Route::post('email/ai-agent/knowledge', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'updateKnowledge']);
    Route::get('email/ai-agent/services', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'services']);
    Route::post('email/ai-agent/services', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'saveService']);
    Route::delete('email/ai-agent/services/{id}', [\App\Http\Controllers\Api\AiMarketingAgentController::class, 'deleteService']);

    // ============================================
    // Social Media Planner Routes
    // ============================================
    Route::prefix('v1/social')->group(function () {
        // Accounts
        Route::get('/accounts', [SocialAccountController::class, 'index']);
        Route::get('/accounts/connect-url', [SocialAccountController::class, 'connectUrl']);
        Route::get('/accounts/pending-pages', [SocialAccountController::class, 'getPendingPages']);
        Route::post('/accounts/confirm-selection', [SocialAccountController::class, 'confirmPageSelection']);
        Route::get('/accounts/{id}', [SocialAccountController::class, 'show']);
        Route::delete('/accounts/{id}', [SocialAccountController::class, 'destroy']);
        Route::post('/accounts/{id}/disconnect', [SocialAccountController::class, 'disconnect']);
        Route::post('/accounts/{id}/check-health', [SocialAccountController::class, 'checkHealth']);
        Route::post('/accounts/{id}/refresh-token', [SocialAccountController::class, 'refreshToken']);

        // Media Upload
        Route::post('/media/upload', [SocialMediaUploadController::class, 'upload']);

        // Posts & Content Calendar
        Route::get('/posts', [SocialPostController::class, 'index']);
        Route::get('/posts/calendar', [SocialPostController::class, 'calendar']);
        Route::post('/posts', [SocialPostController::class, 'store']);
        Route::get('/posts/{id}', [SocialPostController::class, 'show']);
        Route::put('/posts/{id}/reschedule', [SocialPostController::class, 'reschedule']);
        Route::post('/posts/{id}/publish-now', [SocialPostController::class, 'publishNow']);
        Route::delete('/posts/{id}', [SocialPostController::class, 'destroy']);

        // Unified Social Inbox (Facebook Messenger, Instagram Direct & Comments)
        Route::get('/inbox/conversations', [SocialInboxController::class, 'conversations']);
        Route::get('/inbox/conversations/{id}', [SocialInboxController::class, 'conversationShow']);
        Route::get('/inbox/sync-delta', [SocialInboxController::class, 'syncDelta']);
        Route::post('/inbox/conversations/sync', [SocialInboxController::class, 'sync']);
        Route::post('/inbox/conversations/{id}/messages', [SocialInboxController::class, 'sendMessage']);
        Route::put('/inbox/conversations/{id}/status', [SocialInboxController::class, 'updateStatus']);
        Route::put('/inbox/conversations/{id}/priority', [SocialInboxController::class, 'updatePriority']);
        Route::put('/inbox/conversations/{id}/unread', [SocialInboxController::class, 'toggleUnread']);
        Route::put('/inbox/conversations/{id}/assign', [SocialInboxController::class, 'assign']);
        Route::post('/inbox/conversations/{id}/notes', [SocialInboxController::class, 'addNote']);
        Route::delete('/inbox/notes/{noteId}', [SocialInboxController::class, 'deleteNote']);
        Route::post('/inbox/conversations/{id}/tags', [SocialInboxController::class, 'addTag']);
        Route::delete('/inbox/conversations/{id}/tags', [SocialInboxController::class, 'removeTag']);
        Route::post('/inbox/conversations/{id}/link-contact', [SocialInboxController::class, 'linkContact']);
        Route::post('/inbox/conversations/{id}/create-contact', [SocialInboxController::class, 'createContact']);
        Route::get('/inbox/assignable-users', [SocialInboxController::class, 'assignableUsers']);
        Route::get('/inbox/quick-replies', [SocialInboxController::class, 'quickReplies']);
        Route::post('/inbox/sync', [SocialInboxController::class, 'sync']);

        // Analytics
        Route::get('/analytics', [SocialAnalyticsController::class, 'index']);
        Route::post('/analytics/sync', [SocialAnalyticsController::class, 'sync']);

        // AI Social Copilot
        Route::post('/ai/generate-captions', [SocialAiController::class, 'generateCaptions']);
        Route::post('/ai/rewrite', [SocialAiController::class, 'rewrite']);
        Route::post('/ai/suggest-hashtags', [SocialAiController::class, 'suggestHashtags']);
        Route::post('/ai/suggest-reply', [SocialAiController::class, 'suggestReply']);

        // Meta Lead Ads Integration (Facebook & Instagram Lead Generation)
        Route::get('/leads/accounts', [MetaLeadAdsController::class, 'getAccounts']);
        Route::get('/leads/oauth-url', [MetaLeadAdsController::class, 'getOAuthUrl']);
        Route::get('/leads/metrics', [MetaLeadAdsController::class, 'getMetrics']);
        Route::get('/leads/forms', [MetaLeadAdsController::class, 'getForms']);
        Route::post('/leads/forms/sync', [MetaLeadAdsController::class, 'syncRemoteForms']);
        Route::get('/leads/forms/{id}', [MetaLeadAdsController::class, 'getForm']);
        Route::put('/leads/forms/{id}', [MetaLeadAdsController::class, 'updateForm']);
        Route::post('/leads/forms/{id}/import', [MetaLeadAdsController::class, 'importHistoricalLeads']);
        Route::get('/leads/submissions', [MetaLeadAdsController::class, 'getSubmissions']);
        Route::post('/leads/submissions/{id}/retry', [MetaLeadAdsController::class, 'retrySubmission']);
    });

    // ============================================
    // Page & Form Builder Module Routes
    // ============================================
    Route::prefix('v1/builder')->group(function () {
        // Dashboard Metrics (both aliases supported)
        Route::get('/metrics', [SubmissionController::class, 'dashboardMetrics']);
        Route::get('/dashboard/metrics', [SubmissionController::class, 'dashboardMetrics']);

        // Landing Pages
        Route::get('/pages', [PageBuilderController::class, 'index']);
        Route::post('/pages', [PageBuilderController::class, 'store']);
        Route::get('/pages/{id}', [PageBuilderController::class, 'show']);
        Route::put('/pages/{id}', [PageBuilderController::class, 'update']);
        Route::delete('/pages/{id}', [PageBuilderController::class, 'destroy']);
        Route::post('/pages/{id}/publish', [PageBuilderController::class, 'togglePublish']);
        Route::post('/pages/{id}/toggle-publish', [PageBuilderController::class, 'togglePublish']);
        Route::post('/pages/{id}/clone', [PageBuilderController::class, 'clone']);
        Route::post('/pages/{id}/duplicate', [PageBuilderController::class, 'clone']);

        // Forms & Surveys
        Route::get('/forms', [FormBuilderController::class, 'index']);
        Route::post('/forms', [FormBuilderController::class, 'store']);
        Route::get('/forms/{id}', [FormBuilderController::class, 'show']);
        Route::put('/forms/{id}', [FormBuilderController::class, 'update']);
        Route::delete('/forms/{id}', [FormBuilderController::class, 'destroy']);
        Route::post('/forms/{id}/publish', [FormBuilderController::class, 'togglePublish']);
        Route::post('/forms/{id}/toggle-publish', [FormBuilderController::class, 'togglePublish']);
        Route::post('/forms/{id}/clone', [FormBuilderController::class, 'clone']);
        Route::post('/forms/{id}/duplicate', [FormBuilderController::class, 'clone']);

        // Submissions & CSV Export
        Route::get('/submissions', [SubmissionController::class, 'index']);
        Route::get('/submissions/export', [SubmissionController::class, 'export']);
        Route::get('/submissions/export/csv', [SubmissionController::class, 'export']);
        Route::get('/submissions/{id}', [SubmissionController::class, 'show']);
        Route::delete('/submissions/{id}', [SubmissionController::class, 'destroy']);

        // Templates Library
        Route::get('/templates', [TemplateController::class, 'index']);
    });

    // ============================================
    // Media Storage & Library Module Routes
    // ============================================
    Route::prefix('v1/media')->group(function () {
        Route::get('/stats', [MediaController::class, 'getStats']);
        Route::get('/folders', [MediaController::class, 'getFolders']);
        Route::post('/folders', [MediaController::class, 'createFolder']);
        Route::put('/folders/{id}', [MediaController::class, 'updateFolder']);
        Route::delete('/folders/{id}', [MediaController::class, 'deleteFolder']);

        Route::get('/assets', [MediaController::class, 'getAssets']);
        Route::post('/assets/upload', [MediaController::class, 'upload']);
        Route::put('/assets/{id}', [MediaController::class, 'updateAsset']);
        Route::delete('/assets/{id}', [MediaController::class, 'deleteAsset']);
        Route::post('/assets/bulk-move', [MediaController::class, 'bulkMove']);
        Route::post('/assets/bulk-delete', [MediaController::class, 'bulkDelete']);
        Route::post('/assets/{id}/access', [MediaController::class, 'recordAccess']);
    });

    // ============================================
    // Advanced Marketing & Team Analytics Routes
    // ============================================
    Route::prefix('v1/reporting')->group(function () {
        Route::get('/overview', [AdvancedReportingController::class, 'getOverview']);
        Route::get('/advertising', [AdvancedReportingController::class, 'getAdvertising']);
        Route::get('/social', [AdvancedReportingController::class, 'getSocial']);
        Route::get('/leads', [AdvancedReportingController::class, 'getLeads']);
        Route::get('/team', [AdvancedReportingController::class, 'getTeam']);
        Route::get('/saved', [AdvancedReportingController::class, 'getSavedReports']);
        Route::post('/saved', [AdvancedReportingController::class, 'saveReport']);
        Route::delete('/saved/{id}', [AdvancedReportingController::class, 'deleteSavedReport']);
        Route::get('/export', [AdvancedReportingController::class, 'exportCsv']);
    });
});

// ============================================
// WebHook Routes (WhatsApp Webhooks)
// ============================================

/**
 * WhatsApp webhook verification endpoint (GET)
 * Used by Meta to verify webhook URL during setup
 */
Route::get('/webhook/whatsapp', [WhatsappWebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [WhatsappWebhookController::class, 'handle']);
Route::get('/whatsapp/webhook', [WhatsappWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle']);
