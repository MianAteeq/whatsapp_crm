<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$appId = '1333790982005297';
$appSecret = 'd29dfa98e5ba5f2c31c7c20065a9a085';
$appToken = "{$appId}|{$appSecret}";
$configId = '1908588223842822';

echo "Testing App 1333790982005297 with Config 1908588223842822...\n";

// Query config with this app's token
$res = Http::get("https://graph.facebook.com/v21.0/{$configId}", [
    'access_token' => $appToken,
]);

echo "Result for Config {$configId} under App 1333790982005297:\n" . $res->body() . "\n\n";

// Query permissions on this app
$resPerms = Http::get("https://graph.facebook.com/v21.0/{$appId}/permissions", [
    'access_token' => $appToken,
]);

echo "Permissions on App 1333790982005297:\n" . $resPerms->body() . "\n";

