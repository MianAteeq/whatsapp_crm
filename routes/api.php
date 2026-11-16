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
use App\Http\Controllers\Api\WhatsappBillingController;
use App\Http\Controllers\Api\BusinessProfileController;
use App\Http\Controllers\Api\WorkspaceGovernanceController;
use App\Http\Controllers\Api\SalesWorkspaceController;
use App\Http\Controllers\Api\UnifiedInboxController;
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
Route::get('/webhooks/facebook', [SocialWebhookController::class, 'verify']);
Route::post('/webhooks/facebook', [SocialWebhookController::class, 'handle']);
Route::get('/webhook/facebook', [SocialWebhookController::class, 'verify']);
Route::post('/webhook/facebook', [SocialWebhookController::class, 'handle']);
Route::get('/webhooks/instagram', [SocialWebhookController::class, 'verify']);
Route::post('/webhooks/instagram', [SocialWebhookController::class, 'handle']);
Route::get('/webhook/instagram', [SocialWebhookController::class, 'verify']);
Route::post('/webhook/instagram', [SocialWebhookController::class, 'handle']);
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
    Route::get('sales/bootstrap', [SalesWorkspaceController::class, 'bootstrap'])->middleware('tenant.permission:sales.view');
    Route::get('sales/overview', [SalesWorkspaceController::class, 'overview'])->middleware('tenant.permission:sales.view');
    Route::get('sales/opportunities', [SalesWorkspaceController::class, 'opportunities'])->middleware('tenant.permission:sales.view');
    Route::get('sales/opportunities/{opportunity}', [SalesWorkspaceController::class, 'opportunity'])->middleware('tenant.permission:sales.view');
    Route::post('sales/pipelines', [SalesWorkspaceController::class, 'savePipeline'])->middleware('tenant.permission:sales.manage');
    Route::delete('sales/pipelines/{pipeline}', [SalesWorkspaceController::class, 'deletePipeline'])->middleware('tenant.permission:sales.manage');
    Route::post('sales/stages', [SalesWorkspaceController::class, 'saveStage'])->middleware('tenant.permission:sales.manage');
    Route::delete('sales/stages/{stage}', [SalesWorkspaceController::class, 'deleteStage'])->middleware('tenant.permission:sales.manage');
    Route::post('sales/opportunities', [SalesWorkspaceController::class, 'saveOpportunity'])->middleware('tenant.permission:sales.manage');
    Route::patch('sales/opportunities/{opportunity}/stage', [SalesWorkspaceController::class, 'moveOpportunity'])->middleware('tenant.permission:sales.manage');
    Route::delete('sales/opportunities/{opportunity}', [SalesWorkspaceController::class, 'deleteOpportunity'])->middleware('tenant.permission:sales.manage');
    Route::get('sales/tasks', [SalesWorkspaceController::class, 'tasks'])->middleware('tenant.permission:tasks.view');
    Route::post('sales/tasks', [SalesWorkspaceController::class, 'saveTask'])->middleware('tenant.permission:tasks.manage');
    Route::post('sales/tasks/{task}/complete', [SalesWorkspaceController::class, 'completeTask'])->middleware('tenant.permission:tasks.manage');
    Route::delete('sales/tasks/{task}', [SalesWorkspaceController::class, 'deleteTask'])->middleware('tenant.permission:tasks.manage');
    Route::get('sales/companies', [SalesWorkspaceController::class, 'companies'])->middleware('tenant.permission:companies.view');
    Route::get('sales/companies/{company}', [SalesWorkspaceController::class, 'company'])->middleware('tenant.permission:companies.view');
    Route::post('sales/companies', [SalesWorkspaceController::class, 'saveCompany'])->middleware('tenant.permission:companies.manage');
    Route::delete('sales/companies/{company}', [SalesWorkspaceController::class, 'deleteCompany'])->middleware('tenant.permission:companies.manage');
    Route::get('sales/appointments', [SalesWorkspaceController::class, 'appointments'])->middleware('tenant.permission:appointments.view');
    Route::post('sales/appointments', [SalesWorkspaceController::class, 'saveAppointment'])->middleware('tenant.permission:appointments.manage');
    Route::post('sales/appointments/{appointment}/reschedule', [SalesWorkspaceController::class, 'rescheduleAppointment'])->middleware('tenant.permission:appointments.manage');
    Route::delete('sales/appointments/{appointment}', [SalesWorkspaceController::class, 'deleteAppointment'])->middleware('tenant.permission:appointments.manage');
    Route::get('sales/configuration', [SalesWorkspaceController::class, 'configuration'])->middleware('tenant.permission:settings.view');
    Route::post('sales/custom-fields', [SalesWorkspaceController::class, 'saveCustomField'])->middleware('tenant.permission:custom_fields.manage');
    Route::delete('sales/custom-fields/{field}', [SalesWorkspaceController::class, 'deleteCustomField'])->middleware('tenant.permission:custom_fields.manage');
    Route::get('sales/custom-fields/{type}/{id}/values', [SalesWorkspaceController::class, 'entityCustomValues'])->middleware('tenant.permission:contacts.view');
    Route::put('sales/custom-fields/{type}/{id}/values', [SalesWorkspaceController::class, 'saveEntityCustomValues'])->middleware('tenant.permission:custom_fields.manage');
    Route::post('sales/lists', [SalesWorkspaceController::class, 'saveList'])->middleware('tenant.permission:contacts.update');
    Route::get('sales/lists/{list}/members', [SalesWorkspaceController::class, 'listMembers'])->middleware('tenant.permission:contacts.view');
    Route::delete('sales/lists/{list}', [SalesWorkspaceController::class, 'deleteList'])->middleware('tenant.permission:contacts.update');
    Route::post('sales/list-views', [SalesWorkspaceController::class, 'saveView'])->middleware('tenant.permission:settings.manage');
    Route::delete('sales/list-views/{view}', [SalesWorkspaceController::class, 'deleteView'])->middleware('tenant.permission:settings.manage');
    Route::post('sales/tag-categories', [SalesWorkspaceController::class, 'saveTagCategory'])->middleware('tenant.permission:settings.manage');
    Route::delete('sales/tag-categories/{category}', [SalesWorkspaceController::class, 'deleteTagCategory'])->middleware('tenant.permission:settings.manage');
    Route::post('sales/value-folders', [SalesWorkspaceController::class, 'saveFolder'])->middleware('tenant.permission:settings.manage');
    Route::post('sales/reusable-values', [SalesWorkspaceController::class, 'saveReusableValue'])->middleware('tenant.permission:settings.manage');
    Route::delete('sales/reusable-values/{value}', [SalesWorkspaceController::class, 'deleteReusableValue'])->middleware('tenant.permission:settings.manage');
    Route::get('settings/business-profile', [BusinessProfileController::class, 'show']);
    Route::post('settings/business-profile', [BusinessProfileController::class, 'update']);
    Route::get('workspace/governance', [WorkspaceGovernanceController::class, 'overview'])->middleware('tenant.permission:settings.view');
    Route::post('workspace/roles', [WorkspaceGovernanceController::class, 'saveRole'])->middleware('tenant.permission:users.manage');
    Route::delete('workspace/roles/{role}', [WorkspaceGovernanceController::class, 'deleteRole'])->middleware('tenant.permission:users.manage');
    Route::post('workspace/users', [WorkspaceGovernanceController::class, 'saveUser'])->middleware('tenant.permission:users.manage');
    Route::delete('workspace/users/{user}', [WorkspaceGovernanceController::class, 'deleteUser'])->middleware('tenant.permission:users.manage');
    Route::get('workspace/audit', [WorkspaceGovernanceController::class, 'audit'])->middleware('tenant.permission:audit.view');
    Route::get('workspace/audit/export', [WorkspaceGovernanceController::class, 'exportAudit'])->middleware('tenant.permission:audit.export');
    Route::put('workspace/communication-policy', [WorkspaceGovernanceController::class, 'savePolicy'])->middleware('tenant.permission:settings.manage');
    Route::get('workspace/duplicate-reviews', [WorkspaceGovernanceController::class, 'duplicateReviews'])->middleware('tenant.permission:contacts.view');
    Route::post('workspace/duplicate-reviews/{review}/resolve', [WorkspaceGovernanceController::class, 'resolveDuplicate'])->middleware('tenant.permission:contacts.update');
    Route::get('contacts/{contact}/communication-preferences', [WorkspaceGovernanceController::class, 'contactPreferences'])->middleware('tenant.permission:contacts.view');
    Route::put('contacts/{contact}/communication-preferences', [WorkspaceGovernanceController::class, 'saveContactPreferences'])->middleware('tenant.permission:contacts.update');
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
    Route::get('contacts/advance-search', [ContactController::class, 'advanceSearch'])->middleware('tenant.permission:contacts.view');

    /**
     * Bulk delete contacts
     */
    Route::post('contacts/batch-delete', [ContactController::class, 'batchDelete'])->middleware('tenant.permission:contacts.delete');

    /**
     * Contact CRUD operations (Create, Read, Update, Delete)
     */
    Route::get('contacts', [ContactController::class, 'index'])->middleware('tenant.permission:contacts.view');
    Route::get('contacts/{contact}', [ContactController::class, 'show'])->middleware('tenant.permission:contacts.view');
    Route::post('contacts', [ContactController::class, 'store'])->middleware('tenant.permission:contacts.create');
    Route::match(['put','patch'], 'contacts/{contact}', [ContactController::class, 'update'])->middleware('tenant.permission:contacts.update');
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->middleware('tenant.permission:contacts.delete');

    /**
     * Import contacts from file
     */
    Route::post('contacts/import', [ContactController::class, 'import'])->middleware('tenant.permission:contacts.import');

    // ---- Tag Management ----

    /**
     * Tag CRUD operations
     */
    Route::get('tags', [TagController::class, 'index'])->middleware('tenant.permission:contacts.view');
    Route::get('tags/{tag}', [TagController::class, 'show'])->middleware('tenant.permission:contacts.view');
    Route::post('tags', [TagController::class, 'store'])->middleware('tenant.permission:contacts.update');
    Route::match(['put','patch'], 'tags/{tag}', [TagController::class, 'update'])->middleware('tenant.permission:contacts.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->middleware('tenant.permission:contacts.update');

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

    // ---- WhatsApp Billing & Payments ----

    /**
     * WhatsApp Billing Profile & Status
     */
    Route::get('whatsapp/billing', [WhatsappBillingController::class, 'index']);
    Route::post('whatsapp/billing/refresh', [WhatsappBillingController::class, 'refresh']);
    Route::post('whatsapp/billing/meta-url', [WhatsappBillingController::class, 'getMetaUrl']);
    Route::post('whatsapp/billing/dismiss-error', [WhatsappBillingController::class, 'dismissError']);


    // ---- Conversations & Messages ----

    /**
     * Retrieve all conversations
     */
    Route::get('conversations', [ConversationController::class, 'index'])->middleware('tenant.permission:messages.view');

    /**
     * Retrieve messages for a specific conversation
     */
    Route::get('conversations/{id}/messages', [ConversationController::class, 'messages'])->middleware('tenant.permission:messages.view');

    /**
     * Mark conversation as read
     */
    Route::post('conversations/{id}/mark-read', [ConversationController::class, 'markRead'])->middleware('tenant.permission:messages.view');

    /**
     * Toggle auto reply for a specific conversation
     */
    Route::post('conversations/{id}/toggle-auto-reply', [ConversationController::class, 'toggleAutoReply'])->middleware('tenant.permission:messages.send');

    // ---- Professional Unified Inbox (WhatsApp, Facebook, Instagram) ----
    Route::get('inbox/bootstrap', [UnifiedInboxController::class, 'bootstrap'])->middleware('tenant.permission:messages.view');
    Route::get('inbox/conversations', [UnifiedInboxController::class, 'index'])->middleware('tenant.permission:messages.view');
    Route::get('inbox/conversations/{channel}/{sourceId}', [UnifiedInboxController::class, 'show'])->middleware('tenant.permission:messages.view');
    Route::patch('inbox/conversations/{channel}/{sourceId}/state', [UnifiedInboxController::class, 'updateState'])->middleware('tenant.permission:messages.view');
    Route::post('inbox/conversations/{channel}/{sourceId}/notes', [UnifiedInboxController::class, 'addNote'])->middleware('tenant.permission:messages.send');
    Route::get('inbox/snippets', [UnifiedInboxController::class, 'snippets'])->middleware('tenant.permission:snippets.view');
    Route::post('inbox/snippets', [UnifiedInboxController::class, 'saveSnippet'])->middleware('tenant.permission:messages.send');
    Route::delete('inbox/snippets/{snippet}', [UnifiedInboxController::class, 'deleteSnippet'])->middleware('tenant.permission:messages.send');
    Route::post('inbox/conversations/{channel}/{sourceId}/render-snippet', [UnifiedInboxController::class, 'renderSnippet'])->middleware('tenant.permission:snippets.view');
    Route::put('inbox/sla-policies', [UnifiedInboxController::class, 'saveSlaPolicy'])->middleware('tenant.permission:sla.manage');
    Route::get('inbox/sla-report', [UnifiedInboxController::class, 'slaReport'])->middleware('tenant.permission:sla.view');
    Route::post('inbox/conversations/{channel}/{sourceId}/tasks', [UnifiedInboxController::class, 'createLinkedTask'])->middleware('tenant.permission:tasks.manage');

    /**
     * Send a text message via WhatsApp
     */
    Route::post('messages/send', [WhatsappMessageController::class, 'send'])->middleware('tenant.permission:messages.send');

    /**
     * Simulate an incoming message from a contact
     */
    Route::post('messages/simulate-incoming', [WhatsappMessageController::class, 'simulateIncoming'])->middleware('tenant.permission:settings.manage');

    /**
     * Send a media message via WhatsApp
     */
    Route::post('messages/send-media', [WhatsappMessageController::class, 'sendMedia'])->middleware('tenant.permission:messages.send');

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

    Route::post('whatsapp/templates/send', [WhatsappMessageController::class, 'sendTemplate'])->middleware('tenant.permission:messages.send');

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

    )->middleware('tenant.permission:campaigns.manage');

    Route::get(

        'campaigns/dashboard',

        [CampaignController::class, 'dashboard']

    )->middleware('tenant.permission:campaigns.view');

    Route::get(

        'campaign/list',

        [CampaignController::class, 'index']

    )->middleware('tenant.permission:campaigns.view');

    Route::get(

        'campaigns/{id}',

        [CampaignController::class, 'show']

    )->middleware('tenant.permission:campaigns.view');

    Route::delete(

        'campaigns/{id}',

        [CampaignController::class, 'destroy']

    )->middleware('tenant.permission:campaigns.manage');

    // ---- Automation Routes ----
    Route::get('automation/workflows', [AutomationController::class, 'index'])->middleware('tenant.permission:automation.view');
    Route::post('automation/workflows', [AutomationController::class, 'store'])->middleware('tenant.permission:automation.manage');
    Route::get('automation/workflows/{id}', [AutomationController::class, 'show'])->middleware('tenant.permission:automation.view');
    Route::put('automation/workflows/{id}', [AutomationController::class, 'update'])->middleware('tenant.permission:automation.manage');
    Route::delete('automation/workflows/{id}', [AutomationController::class, 'destroy'])->middleware('tenant.permission:automation.manage');
    Route::post('automation/workflows/{id}/canvas', [AutomationController::class, 'saveCanvas'])->middleware('tenant.permission:automation.manage');
    Route::get('automation/executions', [AutomationController::class, 'executions'])->middleware('tenant.permission:automation.view');
    Route::get('automation/dashboard-stats', [AutomationController::class, 'dashboardStats'])->middleware('tenant.permission:automation.view');

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
    Route::get('contacts/{id}/email-activities', [ContactController::class, 'emailActivities'])->middleware('tenant.permission:contacts.view');
    Route::get('contacts/{id}/scraper-history', [ContactController::class, 'scraperHistory'])->middleware('tenant.permission:contacts.view');

    // Email Settings
    Route::get('email/settings', [EmailSettingController::class, 'show'])->middleware('tenant.permission:settings.view');
    Route::post('email/settings', [EmailSettingController::class, 'store'])->middleware('tenant.permission:settings.manage');
    Route::post('email/settings/test', [EmailSettingController::class, 'testConnection'])->middleware('tenant.permission:settings.manage');

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
    Route::post('email/campaigns/estimate-audience', [EmailCampaignController::class, 'estimateAudience'])->middleware('tenant.permission:campaigns.view');
    Route::post('email/campaigns/send-test', [EmailCampaignController::class, 'sendTest'])->middleware('tenant.permission:campaigns.manage');
    Route::post('email/campaigns/{id}/launch', [EmailCampaignController::class, 'launch'])->middleware('tenant.permission:campaigns.launch');
    Route::post('email/campaigns/{id}/pause', [EmailCampaignController::class, 'pause'])->middleware('tenant.permission:campaigns.manage');
    Route::post('email/campaigns/{id}/resume', [EmailCampaignController::class, 'resume'])->middleware('tenant.permission:campaigns.manage');
    Route::post('email/campaigns/{id}/cancel', [EmailCampaignController::class, 'cancel'])->middleware('tenant.permission:campaigns.manage');
    Route::post('email/campaigns/{id}/duplicate', [EmailCampaignController::class, 'duplicate'])->middleware('tenant.permission:campaigns.manage');
    Route::get('email/campaigns/{id}/report', [EmailCampaignController::class, 'report'])->middleware('tenant.permission:campaigns.view');
    Route::get('email/campaigns', [EmailCampaignController::class, 'index'])->middleware('tenant.permission:campaigns.view');
    Route::get('email/campaigns/{email_campaign}', [EmailCampaignController::class, 'show'])->middleware('tenant.permission:campaigns.view');
    Route::post('email/campaigns', [EmailCampaignController::class, 'store'])->middleware('tenant.permission:campaigns.manage');
    Route::match(['put','patch'], 'email/campaigns/{email_campaign}', [EmailCampaignController::class, 'update'])->middleware('tenant.permission:campaigns.manage');
    Route::delete('email/campaigns/{email_campaign}', [EmailCampaignController::class, 'destroy'])->middleware('tenant.permission:campaigns.manage');

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
        Route::post('/accounts/{id}/retry-webhook', [SocialAccountController::class, 'retryWebhook']);

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
        Route::get('/inbox/conversations', [SocialInboxController::class, 'conversations'])->middleware('tenant.permission:messages.view');
        Route::get('/inbox/conversations/{id}', [SocialInboxController::class, 'conversationShow'])->middleware('tenant.permission:messages.view');
        Route::get('/inbox/sync-delta', [SocialInboxController::class, 'syncDelta'])->middleware('tenant.permission:messages.view');
        Route::post('/inbox/conversations/sync', [SocialInboxController::class, 'sync'])->middleware('tenant.permission:inbox.manage');
        Route::post('/inbox/conversations/{id}/messages', [SocialInboxController::class, 'sendMessage'])->middleware('tenant.permission:messages.send');
        Route::put('/inbox/conversations/{id}/status', [SocialInboxController::class, 'updateStatus'])->middleware('tenant.permission:messages.send');
        Route::put('/inbox/conversations/{id}/priority', [SocialInboxController::class, 'updatePriority'])->middleware('tenant.permission:inbox.manage');
        Route::put('/inbox/conversations/{id}/unread', [SocialInboxController::class, 'toggleUnread'])->middleware('tenant.permission:messages.view');
        Route::put('/inbox/conversations/{id}/assign', [SocialInboxController::class, 'assign'])->middleware('tenant.permission:inbox.manage');
        Route::post('/inbox/conversations/{id}/notes', [SocialInboxController::class, 'addNote'])->middleware('tenant.permission:messages.send');
        Route::delete('/inbox/notes/{noteId}', [SocialInboxController::class, 'deleteNote'])->middleware('tenant.permission:messages.send');
        Route::post('/inbox/conversations/{id}/tags', [SocialInboxController::class, 'addTag'])->middleware('tenant.permission:messages.send');
        Route::delete('/inbox/conversations/{id}/tags', [SocialInboxController::class, 'removeTag'])->middleware('tenant.permission:messages.send');
        Route::post('/inbox/conversations/{id}/link-contact', [SocialInboxController::class, 'linkContact'])->middleware('tenant.permission:contacts.update');
        Route::post('/inbox/conversations/{id}/create-contact', [SocialInboxController::class, 'createContact'])->middleware('tenant.permission:contacts.create');
        Route::get('/inbox/assignable-users', [SocialInboxController::class, 'assignableUsers'])->middleware('tenant.permission:messages.view');
        Route::get('/inbox/quick-replies', [SocialInboxController::class, 'quickReplies'])->middleware('tenant.permission:snippets.view');
        Route::post('/inbox/sync', [SocialInboxController::class, 'sync'])->middleware('tenant.permission:inbox.manage');

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
