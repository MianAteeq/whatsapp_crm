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

// ============================================
// Protected Routes (Require Authentication)
// ============================================

Route::middleware('auth:sanctum')->group(function () {
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
});

// ============================================
// WebHook Routes (WhatsApp Webhooks)
// ============================================

/**
 * WhatsApp webhook verification endpoint (GET)
 * Used by Meta to verify webhook URL during setup
 */
Route::get('/webhook/whatsapp', [WhatsappWebhookController::class, 'verify']);

/**
 * WhatsApp webhook handler endpoint (POST)
 * Receives incoming messages and status updates from Meta
 */
Route::post('/webhook/whatsapp', [WhatsappWebhookController::class, 'handle']);
