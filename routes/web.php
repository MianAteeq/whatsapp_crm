<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Http\Controllers\Api\Social\SocialWebhookController;

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

// Facebook & Instagram Webhook direct aliases on root (bypassing CSRF verification)
Route::get('/webhooks/facebook', [SocialWebhookController::class, 'verify']);
Route::post('/webhooks/facebook', [SocialWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
Route::get('/webhook/facebook', [SocialWebhookController::class, 'verify']);
Route::post('/webhook/facebook', [SocialWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
Route::get('/webhooks/instagram', [SocialWebhookController::class, 'verify']);
Route::post('/webhooks/instagram', [SocialWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
Route::get('/webhook/instagram', [SocialWebhookController::class, 'verify']);
Route::post('/webhook/instagram', [SocialWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
Route::get('/api/webhooks/facebook', [SocialWebhookController::class, 'verify']);
Route::post('/api/webhooks/facebook', [SocialWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
Route::get('/api/webhooks/instagram', [SocialWebhookController::class, 'verify']);
Route::post('/api/webhooks/instagram', [SocialWebhookController::class, 'handle'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
