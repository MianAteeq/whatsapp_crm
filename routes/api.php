<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\WhatsappSettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get(
        'contacts/advance-search',
        [ContactController::class, 'advanceSearch']
    );
    Route::apiResource('contacts', ContactController::class);
    Route::apiResource('tags', TagController::class);
    Route::post(
        'contacts/import',
        [ContactController::class, 'import']
    );

    Route::post(
        'whatsapp/settings',
        [WhatsappSettingController::class, 'store']
    );

    Route::get(
        'whatsapp/settings',
        [WhatsappSettingController::class, 'show']
    );
    Route::post(
        'whatsapp/test',
        [WhatsappSettingController::class, 'testConnection']
    );
});
