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

// ============================================
// Protected Routes (Require Authentication)
// ============================================

Route::middleware('auth:sanctum')->group(function () {
    /**
     * User logout route
     */
    Route::post('/logout', [AuthController::class, 'logout']);

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
     * Create or update WhatsApp settings
     */
    Route::post('whatsapp/settings', [WhatsappSettingController::class, 'store']);

    /**
     * Retrieve WhatsApp settings
     */
    Route::get('whatsapp/settings', [WhatsappSettingController::class, 'show']);

    /**
     * Test WhatsApp connection
     */
    Route::post('whatsapp/test', [WhatsappSettingController::class, 'testConnection']);

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
     * Send a text message via WhatsApp
     */
    Route::post('messages/send', [WhatsappMessageController::class, 'send']);

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
