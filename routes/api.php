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
