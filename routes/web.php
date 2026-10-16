<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WhatsappWebhookController;

Route::get('/', function () {
    return view('welcome');
});

// WhatsApp Webhook direct aliases on root
Route::get('/webhook/whatsapp', [WhatsappWebhookController::class, 'verify']);
Route::post('/webhook/whatsapp', [WhatsappWebhookController::class, 'handle']);
Route::get('/whatsapp/webhook', [WhatsappWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'handle']);
Route::get('/api/webhook/whatsapp', [WhatsappWebhookController::class, 'verify']);
Route::post('/api/webhook/whatsapp', [WhatsappWebhookController::class, 'handle']);
