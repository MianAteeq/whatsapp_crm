<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenantId = 99993;

try {
    $setting = \App\Models\WhatsappSetting::updateOrCreate(
        ['tenant_id' => $tenantId],
        [
            'openai_key'            => null,
            'company_prompt'        => 'hhh',
            'reply_language'        => 'en',
            'is_auto_reply_enabled' => false,
        ]
    );

    echo "SUCCESS: Created/updated setting id=" . $setting->id . " for tenant=" . $setting->tenant_id . "\n";
    echo "Access Token is: " . var_export($setting->access_token, true) . "\n";
    echo "Is Connected is: " . var_export($setting->is_connected, true) . "\n";
    echo "Is Auto Reply Enabled: " . var_export($setting->is_auto_reply_enabled, true) . "\n";
} catch (\Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

